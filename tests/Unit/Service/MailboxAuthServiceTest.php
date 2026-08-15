<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailboxAuthService;
use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

final class MailboxAuthServiceTest extends TestCase
{
	public function testXoauth2SaslFormat(): void
	{
		$sasl = MailboxAuthService::xoauth2Sasl('user@example.com', 'tok-abc');
		$raw = base64_decode($sasl, true);
		$this->assertNotFalse($raw);
		$this->assertSame("user=user@example.com\x01auth=Bearer tok-abc\x01\x01", $raw);
	}

	public function testResolvePasswordDecryptsSecret(): void
	{
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->with('enc')->willReturn('secret-pass');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new MailboxAuthService(
			$this->createMock(IDBConnection::class),
			$crypto,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IAppConfig::class),
			$l10n,
			$this->createMock(MailboxService::class),
		);
		$out = $svc->resolve([
			'auth_kind' => 'imap',
			'username' => 'user@example.com',
			'email_address' => 'user@example.com',
			'secret_enc' => 'enc',
		]);
		$this->assertSame(MailboxAuthService::MODE_PASSWORD, $out['mode']);
		$this->assertSame('secret-pass', $out['secret']);
		$this->assertSame('user@example.com', $out['user']);
	}

	public function testResolveGoogleUsesAccessToken(): void
	{
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static function (string $v): string {
			return $v === 'tokenc' ? 'access-token' : '';
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new MailboxAuthService(
			$this->createMock(IDBConnection::class),
			$crypto,
			$time,
			$this->createMock(IAppConfig::class),
			$l10n,
			$this->createMock(MailboxService::class),
		);
		$out = $svc->resolve([
			'id' => 9,
			'auth_kind' => 'google',
			'username' => 'user@gmail.com',
			'email_address' => 'user@gmail.com',
			'token_enc' => 'tokenc',
			'token_expires_at' => '2099-01-01 00:00:00',
			'refresh_enc' => '',
			'secret_enc' => '',
		]);
		$this->assertSame(MailboxAuthService::MODE_XOAUTH2, $out['mode']);
		$this->assertSame('access-token', $out['secret']);
	}

	public function testExpiredTokenWithoutRefreshBumpsAuthFailAndFailsClosed(): void
	{
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static function (string $v): string {
			return $v === 'tokenc' ? 'stale-token' : '';
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->expects($this->once())
			->method('recordAuthFailure')
			->with(9, 1)
			->willReturn('auth');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new MailboxAuthService(
			$this->createMock(IDBConnection::class),
			$crypto,
			$time,
			$this->createMock(IAppConfig::class),
			$l10n,
			$mailboxes,
		);
		$this->expectException(\OCA\CustomerCheck\Exception\ValidationException::class);
		$svc->resolve([
			'id' => 9,
			'auth_kind' => 'google',
			'username' => 'user@gmail.com',
			'email_address' => 'user@gmail.com',
			'token_enc' => 'tokenc',
			'token_expires_at' => '2000-01-01 00:00:00',
			'refresh_enc' => '',
			'secret_enc' => '',
			'auth_fail_count' => 1,
		]);
	}
}
