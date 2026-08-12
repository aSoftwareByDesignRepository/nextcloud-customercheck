<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Exception\ValidationException;

/**
 * Minimal SMTP client for shared-mailbox send (product §14.3).
 *
 * Speaks EHLO + STARTTLS/SMTPS + AUTH LOGIN + DATA. Supports plain text and
 * multipart/mixed Files attachments (PDF/PNG/JPEG/TXT). Failures throw
 * ValidationException so drafts are kept.
 */
final class SharedMailboxSmtpClient
{
	/**
	 * Fail closed before any network I/O — empty username/password must never
	 * reach AUTH LOGIN (product §14.3).
	 *
	 * @param array<string, mixed> $cfg
	 */
	public static function assertConfig(array $cfg): void
	{
		$host = trim((string)($cfg['host'] ?? ''));
		$user = (string)($cfg['username'] ?? '');
		$pass = (string)($cfg['password'] ?? '');
		$from = (string)($cfg['from'] ?? '');
		$to = (string)($cfg['to'] ?? '');
		if ($host === '' || $from === '' || $to === '' || $user === '' || $pass === '') {
			throw ValidationException::field('VALIDATION', 'smtp', 'SMTP configuration incomplete.');
		}
	}

	/**
	 * Build the DATA payload (headers + body). Pure — used by unit tests.
	 *
	 * @param array{
	 *   from: string,
	 *   fromName?: string,
	 *   to: string,
	 *   subject: string,
	 *   body: string,
	 *   attachments?: list<array{filename: string, mime: string, data: string}>
	 * } $cfg
	 */
	public static function buildDataPayload(array $cfg): string
	{
		$from = (string)($cfg['from'] ?? '');
		$to = (string)($cfg['to'] ?? '');
		$subject = (string)($cfg['subject'] ?? '');
		$body = (string)($cfg['body'] ?? '');
		$fromName = (string)($cfg['fromName'] ?? $from);
		/** @var list<array{filename?: string, mime?: string, data?: string}> $attachments */
		$attachments = isset($cfg['attachments']) && is_array($cfg['attachments'])
			? $cfg['attachments']
			: [];

		$headers = [
			'From: ' . self::encodeAddress($fromName, $from),
			'To: ' . self::encodeAddress($to, $to),
			'Subject: ' . self::encodeHeader($subject),
			'MIME-Version: 1.0',
			'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
		];

		if ($attachments === []) {
			$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			$headers[] = 'Content-Transfer-Encoding: 8bit';
			$payload = implode("\r\n", $headers) . "\r\n\r\n"
				. self::dotStuff($body) . "\r\n.";

			return $payload;
		}

		$boundary = 'crm_' . bin2hex(random_bytes(12));
		$headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

		$parts = [];
		$parts[] = '--' . $boundary;
		$parts[] = 'Content-Type: text/plain; charset=UTF-8';
		$parts[] = 'Content-Transfer-Encoding: 8bit';
		$parts[] = '';
		$parts[] = self::dotStuff($body);

		foreach ($attachments as $att) {
			$filename = self::safeAttachName((string)($att['filename'] ?? 'attachment.bin'));
			$mime = self::safeMime((string)($att['mime'] ?? 'application/octet-stream'));
			$data = (string)($att['data'] ?? '');
			$parts[] = '--' . $boundary;
			$parts[] = 'Content-Type: ' . $mime . '; name="' . self::asciiFilename($filename) . '"';
			$parts[] = 'Content-Transfer-Encoding: base64';
			$parts[] = 'Content-Disposition: attachment; filename="' . self::asciiFilename($filename) . '"';
			$parts[] = '';
			$parts[] = trim(chunk_split(base64_encode($data), 76, "\r\n"));
		}
		$parts[] = '--' . $boundary . '--';

		return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n.";
	}

	/**
	 * @param array<string, mixed> $cfg
	 */
	public static function send(array $cfg): void
	{
		self::assertConfig($cfg);

		$host = ImapConnection::normalizeHost((string)($cfg['host'] ?? ''));
		if (ImapConnection::isBlockedMailHost($host)) {
			throw ValidationException::field(
				'VALIDATION',
				'smtp',
				'Use a public mail host — localhost and private network addresses are not allowed.',
			);
		}
		$port = (int)($cfg['port'] ?? 587);
		$enc = strtolower(trim((string)($cfg['enc'] ?? 'tls')));
		$user = (string)($cfg['username'] ?? '');
		$pass = (string)($cfg['password'] ?? '');

		$remote = ($enc === 'ssl' || $enc === 'smtps' ? 'ssl://' : '') . $host . ':' . ($port > 0 ? $port : ($enc === 'ssl' ? 465 : 587));
		$fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
		if ($fp === false) {
			throw ValidationException::field('VALIDATION', 'smtp', 'SMTP connect failed: ' . $errstr);
		}
		stream_set_timeout($fp, 20);

		try {
			self::expect($fp, [220]);
			self::cmd($fp, 'EHLO customercheck.local', [250]);
			if ($enc === 'tls' || $enc === 'starttls') {
				self::cmd($fp, 'STARTTLS', [220]);
				if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
					throw ValidationException::field('VALIDATION', 'smtp', 'STARTTLS negotiation failed.');
				}
				self::cmd($fp, 'EHLO customercheck.local', [250]);
			}
			self::cmd($fp, 'AUTH LOGIN', [334]);
			self::cmd($fp, base64_encode($user), [334]);
			self::cmd($fp, base64_encode($pass), [235]);
			self::cmd($fp, 'MAIL FROM:<' . self::addr((string)$cfg['from']) . '>', [250]);
			self::cmd($fp, 'RCPT TO:<' . self::addr((string)$cfg['to']) . '>', [250, 251]);
			self::cmd($fp, 'DATA', [354]);

			fwrite($fp, self::buildDataPayload($cfg) . "\r\n");
			self::expect($fp, [250]);
			self::cmd($fp, 'QUIT', [221, 250]);
		} finally {
			fclose($fp);
		}
	}

	/** @param resource $fp @param list<int> $ok */
	private static function cmd($fp, string $line, array $ok): void
	{
		fwrite($fp, $line . "\r\n");
		self::expect($fp, $ok);
	}

	/** @param resource $fp @param list<int> $ok */
	private static function expect($fp, array $ok): void
	{
		$resp = '';
		while (($line = fgets($fp, 515)) !== false) {
			$resp .= $line;
			if (isset($line[3]) && $line[3] === ' ') {
				break;
			}
		}
		$code = (int)substr($resp, 0, 3);
		if (!in_array($code, $ok, true)) {
			throw ValidationException::field('VALIDATION', 'smtp', 'SMTP rejected: ' . trim($resp));
		}
	}

	private static function addr(string $email): string
	{
		return preg_replace('/[<>\r\n]/', '', $email) ?? $email;
	}

	private static function encodeAddress(string $name, string $email): string
	{
		$email = self::addr($email);
		$name = trim(str_replace(["\r", "\n"], '', $name));
		if ($name === '' || $name === $email) {
			return '<' . $email . '>';
		}

		return '"' . addcslashes($name, '"\\') . '" <' . $email . '>';
	}

	private static function encodeHeader(string $value): string
	{
		$value = str_replace(["\r", "\n"], '', $value);
		if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
			return $value;
		}

		return '=?UTF-8?B?' . base64_encode($value) . '?=';
	}

	private static function dotStuff(string $body): string
	{
		return str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body);
	}

	private static function safeAttachName(string $name): string
	{
		$name = str_replace(["\r", "\n", "\0", '"'], '', $name);
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..') {
			return 'attachment.bin';
		}

		return mb_substr($name, 0, 180);
	}

	private static function asciiFilename(string $name): string
	{
		$safe = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'attachment.bin';
		$safe = str_replace(['\\', '"'], '_', $safe);
		$safe = trim($safe);
		return $safe !== '' ? $safe : 'attachment.bin';
	}

	private static function safeMime(string $mime): string
	{
		$mime = strtolower(trim(str_replace(["\r", "\n", ';'], '', $mime)));
		if ($mime === '' || !preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/', $mime)) {
			return 'application/octet-stream';
		}

		return $mime;
	}
}
