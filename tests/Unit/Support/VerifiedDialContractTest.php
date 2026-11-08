<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Verified-dial contract (ssrf_peer_ip_unchecked / DNS-rebinding TOCTOU).
 *
 * Two mechanical guarantees, proven on the source so the class cannot recur:
 *
 *  1. Every stream_socket_client() dial in lib/ verifies the connected peer
 *     (ImapConnection::dialedPeerIpOrNull) — or goes through the shared
 *     verified dialer (ImapConnection::openVerifiedTlsSocket) — before the
 *     first protocol byte is written (fwrite/fputs/stream_set_timeout).
 *
 *  2. Every imap_open() call in lib/ lives inside
 *     MailboxSyncService::openVerifiedImapStream — the funnel that gates on
 *     verifiedDialIps() and pins the credential-bearing novalidate leg to
 *     verified IP literals (c-client re-resolves and cannot peer-check, so
 *     the dial target itself must be pre-verified).
 */
final class VerifiedDialContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	/**
	 * @return list<string>
	 */
	private static function libPhpFiles(): array
	{
		$files = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(self::appRoot() . '/lib'),
		);
		foreach ($it as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}
		sort($files);

		return $files;
	}

	/**
	 * Blank comments and string literals while preserving byte offsets and
	 * newlines, so position math on the masked source maps back to the file.
	 */
	private static function maskNonCode(string $src): string
	{
		$masked = preg_replace_callback(
			'#//[^\n]*|/\*.*?\*/|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"#s',
			static fn (array $m): string => str_repeat(' ', strlen($m[0])),
			$src,
		);

		return $masked ?? $src;
	}

	/**
	 * Bounds [declPos, closeBracePos] of the nearest enclosing
	 * `function … {` around $pos, or null when the position sits outside any
	 * function body.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private static function functionBounds(string $code, int $pos): ?array
	{
		$decl = strrpos(substr($code, 0, $pos), 'function ');
		if ($decl === false) {
			return null;
		}
		$open = strpos($code, '{', $decl);
		if ($open === false || $open > $pos) {
			return null;
		}
		$depth = 0;
		$len = strlen($code);
		for ($i = $open; $i < $len; $i++) {
			$ch = $code[$i];
			if ($ch === '{') {
				$depth++;
			} elseif ($ch === '}') {
				$depth--;
				if ($depth === 0) {
					return [$decl, $i];
				}
			}
		}

		return null;
	}

	public function testStreamSocketDialsVerifyPeerBeforeFirstProtocolWrite(): void
	{
		$violations = [];
		foreach (self::libPhpFiles() as $file) {
			$code = self::maskNonCode((string)file_get_contents($file));
			$offset = 0;
			while (($pos = strpos($code, 'stream_socket_client(', $offset)) !== false) {
				$offset = $pos + 1;
				$bounds = self::functionBounds($code, $pos);
				if ($bounds === null) {
					$violations[] = $file . ': stream_socket_client outside any function body';
					continue;
				}
				[$decl, $end] = $bounds;
				$body = substr($code, $decl, $end - $decl);
				$dialRel = $pos - $decl;
				$writeRel = false;
				foreach (['fwrite(', 'fputs(', 'stream_set_timeout(', 'stream_socket_enable_crypto('] as $token) {
					$p = strpos($body, $token, $dialRel);
					if ($p !== false && ($writeRel === false || $p < $writeRel)) {
						$writeRel = $p;
					}
				}
				$window = $writeRel === false
					? substr($body, $dialRel)
					: substr($body, $dialRel, $writeRel - $dialRel);
				if (strpos($window, 'dialedPeerIpOrNull') === false
					&& strpos($window, 'openVerifiedTlsSocket') === false) {
					$violations[] = $file . ': stream_socket_client at byte ' . $pos
						. ' has no peer verification before first protocol write';
				}
			}
		}
		self::assertSame([], $violations, implode("\n", $violations));
	}

	public function testImapOpenCallsArePinnedInsideTheVerifiedFunnel(): void
	{
		$violations = [];
		$funnelCount = 0;
		foreach (self::libPhpFiles() as $file) {
			$code = self::maskNonCode((string)file_get_contents($file));
			$offset = 0;
			while (($pos = strpos($code, 'imap_open(', $offset)) !== false) {
				$offset = $pos + 1;
				$bounds = self::functionBounds($code, $pos);
				if ($bounds === null) {
					$violations[] = $file . ': imap_open outside any function body';
					continue;
				}
				$decl = substr($code, $bounds[0], 160);
				if (strpos($decl, 'openVerifiedImapStream') === false) {
					$violations[] = $file . ': imap_open at byte ' . $pos
						. ' outside MailboxSyncService::openVerifiedImapStream';
					continue;
				}
				$funnelCount++;
			}
		}
		self::assertSame([], $violations, implode("\n", $violations));
		self::assertGreaterThan(0, $funnelCount, 'verified imap funnel must exist');

		$sync = self::maskNonCode(
			(string)file_get_contents(self::appRoot() . '/lib/Service/MailboxSyncService.php'),
		);
		$decl = strpos($sync, 'function openVerifiedImapStream');
		self::assertNotFalse($decl, 'openVerifiedImapStream missing');
		$bounds = self::functionBounds($sync, (int)strpos($sync, '{', (int)$decl));
		self::assertNotNull($bounds);
		$body = substr($sync, $bounds[0], $bounds[1] - $bounds[0]);
		self::assertStringContainsString('verifiedDialIps', $body,
			'funnel must refuse hosts without verified dial IPs');
		self::assertStringContainsString('specHostForIp', $body,
			'funnel must pin the novalidate leg to verified IP literals');
	}

	public function testVerifiedDialIpsFailsClosedOnUnresolvableHosts(): void
	{
		// isBlockedMailHost() intentionally allows save-time "unresolvable"
		// (provider DNS may appear later) — the dial path must not inherit
		// that allowance: no verified IPs, no socket.
		$conn = (string)file_get_contents(self::appRoot() . '/lib/Support/ImapConnection.php');
		$code = self::maskNonCode($conn);
		$decl = strpos($code, 'function verifiedDialIps');
		self::assertNotFalse($decl);
		$bounds = self::functionBounds($code, (int)strpos($code, '{', (int)$decl));
		self::assertNotNull($bounds);
		$body = substr($code, $bounds[0], $bounds[1] - $bounds[0]);
		self::assertStringContainsString('resolveCheckedIps', $body);
	}
}
