<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Constants;

/**
 * Live IMAP TLS probe that does NOT require php-imap.
 * Uses MailPreviewText for safe plain-text body previews.
 *
 * Speaks enough of the wire protocol to prove: TCP+TLS reachability, server
 * greeting, and LOGIN rejection/acceptance. Used by integration tests and as a
 * diagnostic fallback when diagnosing provider connectivity.
 *
 * Never logs passwords. Timeouts are hard-bounded.
 */
final class ImapLiveProbe
{
	/**
	 * @return array{
	 *   ok: bool,
	 *   reachable: bool,
	 *   greeting: string,
	 *   reason: 'ok'|'auth'|'network'|'protocol'|'unknown',
	 *   detail: string
	 * }
	 */
	public static function probeLogin(
		string $host,
		int $port,
		string $username,
		string $password,
		float $timeoutSeconds = 12.0,
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$port = $port > 0 ? $port : 993;
		if ($host === '' || $user === '' || $password === '') {
			return [
				'ok' => false,
				'reachable' => false,
				'greeting' => '',
				'reason' => 'unknown',
				'detail' => 'host, username and password are required',
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));
			return [
				'ok' => false,
				'reachable' => false,
				'greeting' => '',
				'reason' => ImapConnection::classifyError($detail) === 'network' ? 'network' : 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reachable' => true,
					'greeting' => ImapConnection::sanitizeError($greeting),
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
				];
			}

			$tag = 'A001';
			$cmd = $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($password) . "\r\n";
			fwrite($fp, $cmd);
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);

			// Some hosts disable clear LOGIN and require AUTHENTICATE PLAIN.
			if (str_contains($upper, 'LOGIN') && (str_contains($upper, 'DISABLED') || str_contains($upper, 'NOT SUPPORTED'))) {
				$tag = 'A010';
				$plain = base64_encode("\0" . $user . "\0" . $password);
				fwrite($fp, $tag . ' AUTHENTICATE PLAIN ' . $plain . "\r\n");
				$response = self::readUntilTagged($fp, $tag);
				$upper = strtoupper($response);
			}

			$sanitized = ImapConnection::sanitizeError($response);
			if (str_contains($upper, $tag . ' OK')) {
				$folders = self::listFoldersAfterLogin($fp);
				// Logout best-effort; ignore result.
				fwrite($fp, "A099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'A099');
				return [
					'ok' => true,
					'reachable' => true,
					'greeting' => ImapConnection::sanitizeError($greeting),
					'reason' => 'ok',
					'detail' => '',
					'folders' => $folders !== [] ? $folders : ['INBOX'],
				];
			}

			$reason = ImapConnection::classifyError($sanitized);
			if ($reason === 'unknown' && (
				str_contains($upper, ' NO ')
				|| str_contains($upper, 'AUTHENTICATIONFAILED')
				|| str_contains($upper, 'LOGIN')
				|| str_contains($upper, 'AUTHENTICATE')
			)) {
				$reason = 'auth';
			}

			return [
				'ok' => false,
				'reachable' => true,
				'greeting' => ImapConnection::sanitizeError($greeting),
				'reason' => $reason === 'network' ? 'auth' : $reason,
				'detail' => $sanitized,
				'folders' => [],
			];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Parse RFC822 header block into CRM ingest fields (pure — unit tested).
	 *
	 * @return array{from: string, subject: string, sentAt: ?string, messageId: ?string}
	 */
	public static function parseRfc822Headers(string $raw): array
	{
		$from = '';
		$subject = '';
		$date = '';
		$messageId = null;
		// Unfold continued header lines.
		$folded = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw)) ?? $raw;
		foreach (explode("\n", $folded) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if (preg_match('/^From:\s*(.+)$/i', $line, $m) === 1) {
				$from = self::extractEmailAddress($m[1]);
			} elseif (preg_match('/^Subject:\s*(.+)$/i', $line, $m) === 1) {
				$subject = MimeHeader::decode(trim($m[1]));
			} elseif (preg_match('/^Date:\s*(.+)$/i', $line, $m) === 1) {
				$date = trim($m[1]);
			} elseif (preg_match('/^Message-ID:\s*(.+)$/i', $line, $m) === 1) {
				$messageId = trim($m[1]);
			}
		}
		$sentAt = null;
		if ($date !== '') {
			$ts = strtotime($date);
			if ($ts !== false) {
				$sentAt = date('Y-m-d H:i:s', $ts);
			}
		}

		return [
			'from' => $from,
			'subject' => $subject,
			'sentAt' => $sentAt,
			'messageId' => $messageId,
		];
	}

	/**
	 * Extract mailbox@host from a From header value.
	 */
	public static function extractEmailAddress(string $raw): string
	{
		$raw = trim($raw);
		if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
			return strtolower(trim($m[1]));
		}
		if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $raw, $m) === 1) {
			return strtolower(trim($m[0]));
		}

		return strtolower($raw);
	}

	/**
	 * Parse EXISTS count from SELECT untagged lines (pure).
	 *
	 * @param list<string> $lines
	 */
	public static function parseExistsCount(array $lines): int
	{
		$max = 0;
		foreach ($lines as $line) {
			if (preg_match('/^\*\s+(\d+)\s+EXISTS\b/i', trim((string)$line), $m) === 1) {
				$max = max($max, (int)$m[1]);
			}
		}

		return $max;
	}

	/**
	 * Parse FETCH responses that include UID + header literal/body (pure).
	 *
	 * @param list<string> $lines
	 * @return list<array{uid: string, from: string, subject: string, sentAt: ?string, messageId: ?string}>
	 */
	public static function parseFetchHeaderResponses(array $lines): array
	{
		$out = [];
		$i = 0;
		$n = count($lines);
		while ($i < $n) {
			$line = (string)$lines[$i];
			if (preg_match('/^\*\s+\d+\s+FETCH\b/i', $line) !== 1) {
				$i++;
				continue;
			}
			$uid = '0';
			if (preg_match('/\bUID\s+(\d+)\b/i', $line, $um) === 1) {
				$uid = $um[1];
			}
			$headerText = '';
			if (preg_match('/\{(\d+)\}\s*$/', $line, $lm) === 1) {
				$i++;
				$headerText = $i < $n ? (string)$lines[$i] : '';
				$i++;
			} elseif (preg_match('/BODY(?:\.PEEK)?\[HEADER(?:\.FIELDS)?[^\]]*\]\s+"((?:\\\\.|[^"\\\\])*)"/i', $line, $bm) === 1) {
				$headerText = stripcslashes($bm[1]);
				$i++;
			} else {
				// Collect following non-FETCH lines until closing paren / next FETCH / tagged OK.
				$i++;
				$chunk = [];
				while ($i < $n) {
					$next = (string)$lines[$i];
					if (preg_match('/^\*\s+\d+\s+FETCH\b/i', $next) === 1 || preg_match('/^[A-Z0-9]+\s+(OK|NO|BAD)\b/i', $next) === 1) {
						break;
					}
					if (trim($next) === ')') {
						$i++;
						break;
					}
					$chunk[] = $next;
					$i++;
				}
				$headerText = implode("\n", $chunk);
			}
			$parsed = self::parseRfc822Headers($headerText);
			$out[] = [
				'uid' => $uid !== '0' ? $uid : (string)(count($out) + 1),
				'from' => $parsed['from'],
				'subject' => $parsed['subject'],
				'sentAt' => $parsed['sentAt'],
				'messageId' => $parsed['messageId'],
			];
		}

		return $out;
	}

	/**
	 * Login + SELECT + FETCH recent headers for one folder (no php-imap).
	 *
	 * @return array{
	 *   ok: bool,
	 *   reason: 'ok'|'auth'|'network'|'protocol'|'unknown',
	 *   detail: string,
	 *   messages: list<array{uid: string, from: string, subject: string, sentAt: ?string, messageId: ?string, snippet: string, direction: string}>
	 * }
	 */
	public static function fetchRecentMessages(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		int $limit = 500,
		float $timeoutSeconds = 20.0,
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
		$port = $port > 0 ? $port : 993;
		$limit = max(1, min(500, $limit));
		if ($host === '' || $user === '' || $password === '') {
			return [
				'ok' => false,
				'reason' => 'unknown',
				'detail' => 'host, username and password are required',
				'messages' => [],
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));
			return [
				'ok' => false,
				'reason' => 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
				'messages' => [],
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
					'messages' => [],
				];
			}

			$tag = 'S001';
			fwrite($fp, $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($password) . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);
			if (str_contains($upper, 'LOGIN') && (str_contains($upper, 'DISABLED') || str_contains($upper, 'NOT SUPPORTED'))) {
				$tag = 'S010';
				$plain = base64_encode("\0" . $user . "\0" . $password);
				fwrite($fp, $tag . ' AUTHENTICATE PLAIN ' . $plain . "\r\n");
				$response = self::readUntilTagged($fp, $tag);
				$upper = strtoupper($response);
			}
			if (!str_contains($upper, $tag . ' OK')) {
				$sanitized = ImapConnection::sanitizeError($response);
				$reason = ImapConnection::classifyError($sanitized);
				if ($reason === 'unknown') {
					$reason = 'auth';
				}

				return [
					'ok' => false,
					'reason' => $reason === 'network' ? 'auth' : $reason,
					'detail' => $sanitized,
					'messages' => [],
				];
			}

			$tag = 'S020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'SELECT failed for folder ' . ImapConnection::sanitizeError($folder),
					'messages' => [],
				];
			}
			$exists = self::parseExistsCount($selectLines);
			if ($exists < 1) {
				fwrite($fp, "S099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'S099');

				return ['ok' => true, 'reason' => 'ok', 'detail' => '', 'messages' => []];
			}

			$start = max(1, $exists - $limit + 1);
			$tag = 'S030';
			$cmd = $tag . ' FETCH ' . $start . ':' . $exists
				. ' (UID BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE MESSAGE-ID)])' . "\r\n";
			fwrite($fp, $cmd);
			$fetchLines = self::readFetchLinesUntilTagged($fp, $tag);
			$parsed = self::parseFetchHeaderResponses($fetchLines);
			$messages = [];
			foreach ($parsed as $msg) {
				$messages[] = [
					'uid' => $msg['uid'],
					'from' => $msg['from'],
					'subject' => $msg['subject'],
					'snippet' => mb_substr($msg['subject'], 0, 200),
					'sentAt' => $msg['sentAt'],
					'messageId' => $msg['messageId'],
					'direction' => 'inbound',
				];
			}

			fwrite($fp, "S099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'S099');

			return ['ok' => true, 'reason' => 'ok', 'detail' => '', 'messages' => $messages];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Delete one message by UID (STORE \Deleted + EXPUNGE). Idempotent when UID is gone.
	 *
	 * @return array{ok: bool, reason: string, detail: string, alreadyGone: bool}
	 */
	public static function deleteUid(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		string $uid,
		float $timeoutSeconds = 20.0,
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
		$uid = trim($uid);
		$port = $port > 0 ? $port : 993;
		if ($host === '' || $user === '' || $password === '' || $uid === '' || !preg_match('/^\d+$/', $uid)) {
			return [
				'ok' => false,
				'reason' => 'unknown',
				'detail' => 'host, username, password and numeric uid are required',
				'alreadyGone' => false,
			];
		}
		if (!ImapConnection::isSafeImapFolder($folder)) {
			return [
				'ok' => false,
				'reason' => 'protocol',
				'detail' => 'unsafe folder',
				'alreadyGone' => false,
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));

			return [
				'ok' => false,
				'reason' => 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
				'alreadyGone' => false,
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
					'alreadyGone' => false,
				];
			}

			$tag = 'D001';
			fwrite($fp, $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($password) . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);
			if (str_contains($upper, 'LOGIN') && (str_contains($upper, 'DISABLED') || str_contains($upper, 'NOT SUPPORTED'))) {
				$tag = 'D010';
				$plain = base64_encode("\0" . $user . "\0" . $password);
				fwrite($fp, $tag . ' AUTHENTICATE PLAIN ' . $plain . "\r\n");
				$response = self::readUntilTagged($fp, $tag);
				$upper = strtoupper($response);
			}
			if (!str_contains($upper, $tag . ' OK')) {
				$sanitized = ImapConnection::sanitizeError($response);
				$reason = ImapConnection::classifyError($sanitized);
				if ($reason === 'unknown') {
					$reason = 'auth';
				}

				return [
					'ok' => false,
					'reason' => $reason === 'network' ? 'auth' : $reason,
					'detail' => $sanitized,
					'alreadyGone' => false,
				];
			}

			$tag = 'D020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'SELECT failed for folder ' . ImapConnection::sanitizeError($folder),
					'alreadyGone' => false,
				];
			}

			// Probe existence — missing UID is success (already deleted).
			$tag = 'D030';
			fwrite($fp, $tag . ' UID FETCH ' . $uid . " (UID)\r\n");
			$fetchLines = self::readLinesUntilTagged($fp, $tag);
			$seen = false;
			foreach ($fetchLines as $line) {
				if (preg_match('/\bUID\s+' . preg_quote($uid, '/') . '\b/i', $line) === 1) {
					$seen = true;
					break;
				}
			}
			$fetchOk = false;
			foreach ($fetchLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$fetchOk = true;
					break;
				}
			}
			if ($fetchOk && !$seen) {
				fwrite($fp, "D099 LOGOUT\r\n");
				self::readUntilTagged($fp, 'D099');

				return [
					'ok' => true,
					'reason' => 'ok',
					'detail' => '',
					'alreadyGone' => true,
				];
			}

			$tag = 'D040';
			fwrite($fp, $tag . ' UID STORE ' . $uid . " +FLAGS.SILENT (\\Deleted)\r\n");
			$storeLines = self::readLinesUntilTagged($fp, $tag);
			$storeOk = false;
			foreach ($storeLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$storeOk = true;
					break;
				}
			}
			if (!$storeOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'UID STORE \\Deleted failed for uid ' . $uid,
					'alreadyGone' => false,
				];
			}

			$tag = 'D050';
			fwrite($fp, $tag . " EXPUNGE\r\n");
			$expungeLines = self::readLinesUntilTagged($fp, $tag);
			$expungeOk = false;
			foreach ($expungeLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$expungeOk = true;
					break;
				}
			}
			if (!$expungeOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'EXPUNGE failed after marking deleted',
					'alreadyGone' => false,
				];
			}

			fwrite($fp, "D099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'D099');

			return [
				'ok' => true,
				'reason' => 'ok',
				'detail' => '',
				'alreadyGone' => false,
			];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Fetch a capped plain-text preview for one UID (BODY.PEEK[TEXT], then BODY.PEEK[1]).
	 *
	 * @return array{ok: bool, reason: string, detail: string, text: string, truncated: bool}
	 */
	public static function fetchUidTextPreview(
		string $host,
		int $port,
		string $username,
		string $password,
		string $folder,
		string $uid,
		int $maxBytes = Constants::MAIL_PREVIEW_MAX_BYTES,
		float $timeoutSeconds = 20.0,
	): array {
		$host = ImapConnection::normalizeHost($host);
		$user = ImapConnection::normalizeUsername($username);
		$folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
		$uid = trim($uid);
		$port = $port > 0 ? $port : 993;
		$maxBytes = max(512, min(Constants::MAIL_PREVIEW_MAX_BYTES, $maxBytes));
		if ($host === '' || $user === '' || $password === '' || $uid === '' || !preg_match('/^\d+$/', $uid)) {
			return [
				'ok' => false,
				'reason' => 'unknown',
				'detail' => 'host, username, password and numeric uid are required',
				'text' => '',
				'truncated' => false,
			];
		}

		$remote = 'ssl://' . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			max(1.0, $timeoutSeconds),
			STREAM_CLIENT_CONNECT,
		);
		if ($fp === false) {
			$detail = ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno));

			return [
				'ok' => false,
				'reason' => 'network',
				'detail' => $detail !== '' ? $detail : 'TLS connect failed',
				'text' => '',
				'truncated' => false,
			];
		}

		stream_set_timeout($fp, (int)max(1, (int)ceil($timeoutSeconds)));
		try {
			$greeting = self::readLine($fp);
			if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting),
					'text' => '',
					'truncated' => false,
				];
			}

			$tag = 'P001';
			fwrite($fp, $tag . ' LOGIN ' . self::quoteAtom($user) . ' ' . self::quoteAtom($password) . "\r\n");
			$response = self::readUntilTagged($fp, $tag);
			$upper = strtoupper($response);
			if (str_contains($upper, 'LOGIN') && (str_contains($upper, 'DISABLED') || str_contains($upper, 'NOT SUPPORTED'))) {
				$tag = 'P010';
				$plain = base64_encode("\0" . $user . "\0" . $password);
				fwrite($fp, $tag . ' AUTHENTICATE PLAIN ' . $plain . "\r\n");
				$response = self::readUntilTagged($fp, $tag);
				$upper = strtoupper($response);
			}
			if (!str_contains($upper, $tag . ' OK')) {
				$sanitized = ImapConnection::sanitizeError($response);
				$reason = ImapConnection::classifyError($sanitized);
				if ($reason === 'unknown') {
					$reason = 'auth';
				}

				return [
					'ok' => false,
					'reason' => $reason === 'network' ? 'auth' : $reason,
					'detail' => $sanitized,
					'text' => '',
					'truncated' => false,
				];
			}

			$tag = 'P020';
			fwrite($fp, $tag . ' SELECT ' . self::quoteAtom($folder) . "\r\n");
			$selectLines = self::readLinesUntilTagged($fp, $tag);
			$selectOk = false;
			foreach ($selectLines as $line) {
				if (str_starts_with(strtoupper($line), strtoupper($tag) . ' OK')) {
					$selectOk = true;
					break;
				}
			}
			if (!$selectOk) {
				return [
					'ok' => false,
					'reason' => 'protocol',
					'detail' => 'SELECT failed for folder ' . ImapConnection::sanitizeError($folder),
					'text' => '',
					'truncated' => false,
				];
			}

			$text = '';
			foreach (['BODY.PEEK[TEXT]', 'BODY.PEEK[1]'] as $section) {
				$tag = $section === 'BODY.PEEK[TEXT]' ? 'P030' : 'P031';
				fwrite($fp, $tag . ' UID FETCH ' . $uid . ' (' . $section . ")\r\n");
				$fetchLines = self::readFetchLinesUntilTagged($fp, $tag, $maxBytes + 2048);
				$candidate = self::parseFetchBodyLiteral($fetchLines);
				if (trim($candidate) !== '') {
					$text = $candidate;
					break;
				}
			}

			fwrite($fp, "P099 LOGOUT\r\n");
			self::readUntilTagged($fp, 'P099');

			$plain = MailPreviewText::toPlainPreview($text, $maxBytes);
			$truncated = strlen($text) > $maxBytes || str_ends_with($plain, '…');

			return [
				'ok' => true,
				'reason' => 'ok',
				'detail' => '',
				'text' => $plain,
				'truncated' => $truncated && $plain !== '',
			];
		} finally {
			fclose($fp);
		}
	}

	/**
	 * Extract the first IMAP FETCH body literal from wire lines.
	 *
	 * @param list<string> $lines
	 */
	public static function parseFetchBodyLiteral(array $lines): string
	{
		$n = count($lines);
		for ($i = 0; $i < $n; $i++) {
			$line = (string)$lines[$i];
			if (preg_match('/\{(\d+)\}\s*$/', $line) === 1 && isset($lines[$i + 1])) {
				return (string)$lines[$i + 1];
			}
			// Inline quoted body (rare): BODY[TEXT] "hello"
			if (preg_match('/BODY(?:\.PEEK)?\[[^\]]*\]\s+"((?:\\\\.|[^"\\\\])*)"/i', $line, $m) === 1) {
				return stripcslashes($m[1]);
			}
		}

		return '';
	}

	/**
	 * @param resource $fp
	 * @return list<string>
	 */
	private static function readLinesUntilTagged($fp, string $tag): array
	{
		$lines = [];
		$deadline = microtime(true) + 20.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			$lines[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}

		return $lines;
	}

	/**
	 * Read FETCH responses including IMAP literals ({n} then n bytes).
	 *
	 * @param resource $fp
	 * @return list<string>
	 */
	private static function readFetchLinesUntilTagged($fp, string $tag, int $maxLiteralBytes = 0): array
	{
		$lines = [];
		$deadline = microtime(true) + 30.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			if (preg_match('/\{(\d+)\}\s*$/', $line, $m) === 1) {
				$len = (int)$m[1];
				$readCap = $len;
				$skipTail = 0;
				if ($maxLiteralBytes > 0 && $len > $maxLiteralBytes) {
					$readCap = $maxLiteralBytes;
					$skipTail = $len - $maxLiteralBytes;
				}
				$literal = '';
				while (strlen($literal) < $readCap && microtime(true) < $deadline) {
					$chunk = fread($fp, $readCap - strlen($literal));
					if ($chunk === false || $chunk === '') {
						$meta = stream_get_meta_data($fp);
						if (!empty($meta['timed_out']) || feof($fp)) {
							break;
						}
						continue;
					}
					$literal .= $chunk;
				}
				while ($skipTail > 0 && microtime(true) < $deadline) {
					$chunk = fread($fp, min(8192, $skipTail));
					if ($chunk === false || $chunk === '') {
						$meta = stream_get_meta_data($fp);
						if (!empty($meta['timed_out']) || feof($fp)) {
							break;
						}
						continue;
					}
					$skipTail -= strlen($chunk);
				}
				$lines[] = $line;
				// Keep literal as one entry so parseFetchHeaderResponses can take it whole.
				$lines[] = $literal;
				continue;
			}
			$lines[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}

		return $lines;
	}

	/**
	 * Parse IMAP LIST untagged lines into folder paths (pure — unit tested).
	 *
	 * @param list<string> $lines
	 * @return list<string>
	 */
	public static function parseListLines(array $lines, int $max = 20): array
	{
		$out = [];
		foreach ($lines as $line) {
			$line = trim((string)$line);
			if ($line === '' || !preg_match('/^\* LIST\b/i', $line)) {
				continue;
			}
			// * LIST (\HasNoChildren) "/" INBOX
			// * LIST () "/" "Sent Items"
			if (preg_match('/^\* LIST\s+\([^)]*\)\s+(\S+)\s+(.+)$/i', $line, $m) !== 1) {
				continue;
			}
			$name = trim($m[2]);
			if ($name !== '' && $name[0] === '"' && str_ends_with($name, '"')) {
				$name = stripcslashes(substr($name, 1, -1));
			}
			$name = trim($name);
			if ($name === '' || in_array($name, $out, true)) {
				continue;
			}
			$out[] = $name;
			if (count($out) >= $max) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param resource $fp
	 * @return list<string>
	 */
	private static function listFoldersAfterLogin($fp): array
	{
		$tag = 'A050';
		fwrite($fp, $tag . " LIST \"\" \"*\"\r\n");
		$lines = [];
		$deadline = microtime(true) + 12.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			$lines[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}
		$folders = self::parseListLines($lines);
		return $folders !== [] ? $folders : ['INBOX'];
	}

	/** @param resource $fp */
	private static function readLine($fp): string
	{
		$line = fgets($fp, 8192);
		return is_string($line) ? rtrim($line, "\r\n") : '';
	}

	/** @param resource $fp */
	private static function readUntilTagged($fp, string $tag): string
	{
		$buf = [];
		$deadline = microtime(true) + 12.0;
		while (microtime(true) < $deadline) {
			$line = self::readLine($fp);
			if ($line === '') {
				$meta = stream_get_meta_data($fp);
				if (!empty($meta['timed_out']) || feof($fp)) {
					break;
				}
				continue;
			}
			$buf[] = $line;
			if (str_starts_with($line, $tag . ' ')) {
				break;
			}
		}
		return implode(' ', $buf);
	}

	/**
	 * IMAP quoted string — escapes " and \. Never used for logging.
	 */
	private static function quoteAtom(string $value): string
	{
		return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
	}
}
