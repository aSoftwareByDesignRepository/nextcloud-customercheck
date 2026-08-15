<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OAuth client secrets must not rest in plaintext appconfig (Q3 / security).
 */
final class PersonalMailOAuthSecretEncryptTest extends TestCase
{
	public function testSaveAdminConfigEncryptsClientSecrets(): void
	{
		$stored = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$stored): string {
				return $stored[$key] ?? $default;
			},
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			static fn (string $plain): string => 'enc:' . $plain,
		);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $cipher): string {
				if (str_starts_with($cipher, 'enc:')) {
					return substr($cipher, 4);
				}
				throw new \RuntimeException('not encrypted');
			},
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/customercheck/oauth');
		$random = $this->createMock(ISecureRandom::class);
		$time = $this->createMock(ITimeFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$personal = $this->createMock(PersonalMailService::class);
		$workspaces = $this->createMock(WorkspaceService::class);
		$db = $this->createMock(IDBConnection::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$rate = $this->createMock(RateLimitService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$svc = new PersonalMailOAuthService(
			$appConfig,
			$url,
			$crypto,
			$random,
			$time,
			$l10n,
			$personal,
			$workspaces,
			$db,
			$cacheFactory,
			$rate,
			$logger,
		);

		$status = $svc->saveAdminConfig([
			'googleClientId' => 'gid',
			'microsoftClientId' => 'mid',
			'googleClientSecret' => 'g-secret',
			'microsoftClientSecret' => 'm-secret',
		]);

		$this->assertSame('enc:g-secret', $stored['oauth_google_client_secret']);
		$this->assertSame('enc:m-secret', $stored['oauth_microsoft_client_secret']);
		$this->assertTrue($status['googleHasSecret']);
		$this->assertTrue($status['microsoftHasSecret']);
		$this->assertStringNotContainsString('g-secret', json_encode($status));
	}

	public function testClientSecretAcceptsLegacyPlaintextUntilResave(): void
	{
		$stored = ['oauth_google_client_secret' => 'legacy-plain'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$stored): string {
				return $stored[$key] ?? $default;
			},
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			},
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new \RuntimeException('bad'));
		$crypto->method('encrypt')->willReturnCallback(static fn (string $p): string => 'enc:' . $p);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.example/cb');
		$svc = new PersonalMailOAuthService(
			$appConfig,
			$url,
			$crypto,
			$this->createMock(ISecureRandom::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
			$this->createMock(PersonalMailService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(LoggerInterface::class),
		);

		$status = $svc->adminConfigStatus();
		$this->assertTrue($status['googleHasSecret']);
	}
}
