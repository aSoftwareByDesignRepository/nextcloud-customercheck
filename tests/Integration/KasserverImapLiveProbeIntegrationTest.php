<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Support\ImapConnection;
use OCA\CustomerCheck\Support\ImapLiveProbe;
use PHPUnit\Framework\TestCase;

/**
 * Live TLS + IMAP LOGIN against All-Inkl/Kasserver — proves the network path
 * the app will use (without php-imap). Uses a deliberate wrong password so we
 * never need a real secret in CI; success criterion is reachability + auth NO.
 *
 * Skip when CRM_SKIP_LIVE_IMAP=1 (air-gapped CI) or when the host is unreachable.
 */
final class KasserverImapLiveProbeIntegrationTest extends TestCase
{
	private const HOST = 'w01d1cf3.kasserver.com';
	private const PORT = 993;
	private const USER = 'info@software-by-design.de (m06367b4)';

	public function testTlsReachableAndWrongPasswordIsAuthFailure(): void
	{
		if (getenv('CRM_SKIP_LIVE_IMAP') === '1') {
			$this->markTestSkipped('CRM_SKIP_LIVE_IMAP=1');
		}

		$result = ImapLiveProbe::probeLogin(
			self::HOST,
			self::PORT,
			self::USER,
			'definitely-not-the-real-password-' . bin2hex(random_bytes(4)),
			15.0,
		);

		if (!$result['reachable']) {
			$this->markTestSkipped('Kasserver IMAP unreachable from this environment: ' . $result['detail']);
		}

		$this->assertFalse($result['ok'], 'Wrong password must not authenticate');
		$this->assertSame('auth', $result['reason'], 'Expected auth classification, got detail=' . $result['detail']);
		$this->assertStringContainsString('OK', strtoupper($result['greeting']));
		// Username paste with (m0…) must have been stripped before LOGIN.
		$this->assertSame(
			'info@software-by-design.de',
			ImapConnection::normalizeUsername(self::USER),
		);
	}

	public function testMailboxSpecMatchesKasserverManualSheet(): void
	{
		$root = ImapConnection::buildMailboxRoot(self::HOST, self::PORT, 'ssl', false);
		$this->assertSame('{w01d1cf3.kasserver.com:993/imap/ssl}', $root);
		$this->assertSame('ssl', ImapConnection::resolveSmtpEnc('tls', 465));
		$this->assertSame('ssl', ImapConnection::resolveImapEnc('tls', 993));
	}
}
