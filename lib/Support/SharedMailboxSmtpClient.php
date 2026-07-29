<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Exception\ValidationException;

/**
 * Minimal SMTP client for shared-mailbox send (product §14.3).
 *
 * Speaks EHLO + STARTTLS/SMTPS + AUTH LOGIN + DATA. No attachment MIME
 * multipart (attachments stay Files-only via compose UX). Failures throw
 * ValidationException so drafts are kept.
 */
final class SharedMailboxSmtpClient
{
	/**
	 * @param array{
	 *   host: string,
	 *   port: int,
	 *   enc: string,
	 *   username: string,
	 *   password: string,
	 *   from: string,
	 *   fromName: string,
	 *   to: string,
	 *   subject: string,
	 *   body: string
	 * } $cfg
	 */
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

	public static function send(array $cfg): void
	{
		self::assertConfig($cfg);

		$host = trim((string)($cfg['host'] ?? ''));
		$port = (int)($cfg['port'] ?? 587);
		$enc = strtolower(trim((string)($cfg['enc'] ?? 'tls')));
		$user = (string)($cfg['username'] ?? '');
		$pass = (string)($cfg['password'] ?? '');
		$from = (string)($cfg['from'] ?? '');
		$to = (string)($cfg['to'] ?? '');
		$subject = (string)($cfg['subject'] ?? '');
		$body = (string)($cfg['body'] ?? '');
		$fromName = (string)($cfg['fromName'] ?? $from);

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
			self::cmd($fp, 'MAIL FROM:<' . self::addr($from) . '>', [250]);
			self::cmd($fp, 'RCPT TO:<' . self::addr($to) . '>', [250, 251]);
			self::cmd($fp, 'DATA', [354]);

			$headers = [
				'From: ' . self::encodeAddress($fromName, $from),
				'To: ' . self::encodeAddress($to, $to),
				'Subject: ' . self::encodeHeader($subject),
				'MIME-Version: 1.0',
				'Content-Type: text/plain; charset=UTF-8',
				'Content-Transfer-Encoding: 8bit',
				'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
			];
			$payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body) . "\r\n.";
			fwrite($fp, $payload . "\r\n");
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
}
