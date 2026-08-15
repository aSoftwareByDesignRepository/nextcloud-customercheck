<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ImapConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pure IMAP helpers — username cleanup, enc/port resolution, error triage.
 */
final class ImapConnectionTest extends TestCase
{
	public function testNormalizeUsernameStripsAllInklAccountSuffix(): void
	{
		$this->assertSame(
			'info@software-by-design.de',
			ImapConnection::normalizeUsername('info@software-by-design.de (m06367b4)'),
		);
		$this->assertSame(
			'info@software-by-design.de',
			ImapConnection::normalizeUsername('  info@software-by-design.de (w01d1cf3)  '),
		);
		$this->assertSame('plainuser', ImapConnection::normalizeUsername('plainuser'));
		$this->assertSame('', ImapConnection::normalizeUsername('   '));
	}

	public function testNormalizeHostStripsSchemesAndPortPaste(): void
	{
		$this->assertSame('w01d1cf3.kasserver.com', ImapConnection::normalizeHost('w01d1cf3.kasserver.com'));
		$this->assertSame('w01d1cf3.kasserver.com', ImapConnection::normalizeHost('IMAPS://w01d1cf3.kasserver.com/'));
		$this->assertSame('w01d1cf3.kasserver.com', ImapConnection::normalizeHost('w01d1cf3.kasserver.com:993'));
		$this->assertSame('', ImapConnection::normalizeHost(''));
	}

	public function testResolveImapEncFromPortAndExplicit(): void
	{
		$this->assertSame('ssl', ImapConnection::resolveImapEnc(null, 993));
		$this->assertSame('tls', ImapConnection::resolveImapEnc(null, 143));
		// Standard ports win over conflicting explicit enc (legacy mis-saves).
		$this->assertSame('ssl', ImapConnection::resolveImapEnc('tls', 993));
		$this->assertSame('tls', ImapConnection::resolveImapEnc('ssl', 143));
		$this->assertSame('none', ImapConnection::resolveImapEnc('none', 110));
	}

	public function testResolveSmtpEncPrefers465SslAnd587Tls(): void
	{
		$this->assertSame('ssl', ImapConnection::resolveSmtpEnc(null, 465));
		$this->assertSame('tls', ImapConnection::resolveSmtpEnc(null, 587));
		$this->assertSame('tls', ImapConnection::resolveSmtpEnc('smtps', 587)); // port 587 wins
		$this->assertSame('ssl', ImapConnection::resolveSmtpEnc('tls', 465)); // legacy 465+tls → ssl
		$this->assertSame('tls', ImapConnection::resolveSmtpEnc('ssl', 587)); // legacy 587+ssl → tls
		$this->assertSame('ssl', ImapConnection::resolveSmtpEnc('ssl', 2525));
	}

	public function testBuildMailboxRootIncludesFlags(): void
	{
		$this->assertSame(
			'{w01d1cf3.kasserver.com:993/imap/ssl}',
			ImapConnection::buildMailboxRoot('w01d1cf3.kasserver.com', 993, 'ssl', false),
		);
		$this->assertSame(
			'{w01d1cf3.kasserver.com:993/imap/ssl/novalidate-cert}',
			ImapConnection::buildMailboxRoot('w01d1cf3.kasserver.com', 993, 'ssl', true),
		);
		$this->assertSame(
			'{mail.example.test:143/imap/tls}',
			ImapConnection::buildMailboxRoot('mail.example.test', 143, 'tls', false),
		);
		// Port 143 always uses STARTTLS; "none" needs a non-standard port.
		$this->assertSame(
			'{mail.example.test:8143/imap/notls}',
			ImapConnection::buildMailboxRoot('mail.example.test', 8143, 'none', false),
		);
	}

	public function testClassifyAndSanitizeErrors(): void
	{
		$this->assertSame('auth', ImapConnection::classifyError('AUTHENTICATIONFAILED Login failed'));
		$this->assertSame('cert', ImapConnection::classifyError('Certificate verify failed'));
		$this->assertSame('network', ImapConnection::classifyError('Connection timed out'));
		$this->assertSame('unknown', ImapConnection::classifyError('weird boom'));
		$this->assertSame(
			'pass=*** value',
			ImapConnection::sanitizeError('pass=supersecret value'),
		);
		$this->assertTrue(ImapConnection::isAuthError('Can not authenticate to IMAP server'));
		$this->assertTrue(ImapConnection::isCertificateError('SSL: certificate verify failed'));
		$this->assertFalse(ImapConnection::isCertificateError('AUTHENTICATIONFAILED'));
	}

	public function testCollectErrorsDedupesAndLimits(): void
	{
		$joined = ImapConnection::collectErrors(
			['Authentication failed', 'Authentication failed', 'extra1', 'extra2'],
			'Authentication failed',
		);
		$parts = explode(' · ', $joined);
		$this->assertSame(['Authentication failed', 'extra1', 'extra2'], $parts);
		$this->assertSame('', ImapConnection::collectErrors(false, null));
	}

	public function testIsBlockedMailHostRejectsLoopbackAndPrivateLiterals(): void
	{
		$this->assertTrue(ImapConnection::isBlockedMailHost('127.0.0.1'));
		$this->assertTrue(ImapConnection::isBlockedMailHost('localhost'));
		$this->assertTrue(ImapConnection::isBlockedMailHost('10.0.0.5'));
		$this->assertTrue(ImapConnection::isBlockedMailHost('192.168.1.1'));
		$this->assertTrue(ImapConnection::isBlockedMailHost('169.254.169.254'));
		$this->assertTrue(ImapConnection::isBlockedIp('172.16.0.1'));
		$this->assertFalse(ImapConnection::isBlockedIp('8.8.8.8'));
		$this->assertTrue(ImapConnection::isBlockedMailHost('not a host!!'));
		$this->assertTrue(ImapConnection::isBlockedMailHost('::1'));
		$this->assertTrue(ImapConnection::isBlockedIp('fc00::1'));
		$this->assertTrue(ImapConnection::isBlockedIp('fe80::1'));
	}

	public function testIsLiveUidAcceptsOnlyDecimalImapUids(): void
	{
		$this->assertTrue(ImapConnection::isLiveUid('1'));
		$this->assertTrue(ImapConnection::isLiveUid('1703'));
		$this->assertTrue(ImapConnection::isLiveUid(' 42 '));
		$this->assertFalse(ImapConnection::isLiveUid(''));
		$this->assertFalse(ImapConnection::isLiveUid('outbound:crm-send-1'));
		$this->assertFalse(ImapConnection::isLiveUid('1703a'));
		$this->assertFalse(ImapConnection::isLiveUid('17.03'));
		$this->assertFalse(ImapConnection::isLiveUid('-1'));
	}

	public function testIsSafeImapFolderRejectsControlChars(): void
	{
		$this->assertTrue(ImapConnection::isSafeImapFolder('INBOX'));
		$this->assertTrue(ImapConnection::isSafeImapFolder('INBOX.Archive'));
		$this->assertTrue(ImapConnection::isSafeImapFolder('INBOX/Ärger'));
		$this->assertTrue(ImapConnection::isSafeImapFolder('INBOX/&AMQ-rger'));
		$this->assertFalse(ImapConnection::isSafeImapFolder("INBOX\nBAD"));
		$this->assertFalse(ImapConnection::isSafeImapFolder(''));
		$this->assertFalse(ImapConnection::isSafeImapFolder('INBOX"evil'));
		$this->assertFalse(ImapConnection::isSafeImapFolder('INBOX{breakout'));
		$this->assertFalse(ImapConnection::isSafeImapFolder('INBOX\\r'));
	}

	public function testFolderToWireEncodesUmlautsForSelect(): void
	{
		$this->assertSame('INBOX', ImapConnection::folderToWire('INBOX'));
		$this->assertSame('INBOX/&AMQ-rger', ImapConnection::folderToWire('INBOX/Ärger'));
		$this->assertSame('INBOX/&AMQ-rger', ImapConnection::folderToWire('INBOX/&AMQ-rger'));
	}
}
