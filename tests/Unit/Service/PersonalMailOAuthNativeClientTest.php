<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Companion AuthSession must not race the web callback on the same OAuth state.
 */
final class PersonalMailOAuthNativeClientTest extends TestCase
{
	public function testBeginAuthorizeStoresNativeClientFlag(): void
	{
		$stored = null;
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())
			->method('set')
			->with(
				$this->stringStartsWith('pm:'),
				$this->callback(static function (array $payload) use (&$stored): bool {
					$stored = $payload;
					return ($payload['client'] ?? '') === 'native'
						&& ($payload['workspaceId'] ?? 0) === 3
						&& ($payload['userId'] ?? '') === 'alex';
				}),
				600,
			);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$personalMail = $this->createMock(PersonalMailService::class);
		$personalMail->expects($this->once())->method('assertPersonalMailEnabled')->with(3);

		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('client-id');

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('state-token-aaaaaaaaaaaaaaaa');

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/oauth/callback');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new PersonalMailOAuthService(
			$appConfig,
			$url,
			$this->createMock(ICrypto::class),
			$random,
			$time,
			$l10n,
			$personalMail,
			$this->createMock(WorkspaceService::class),
			$this->createMock(IDBConnection::class),
			$cacheFactory,
			$rate,
			$this->createMock(LoggerInterface::class),
		);

		$out = $svc->beginAuthorize(3, 'alex', 'google', [
			'dsgvoAck' => true,
			'client' => 'native',
		]);
		$this->assertSame('native', $out['client'] ?? null);
		$this->assertSame('https://nc.test/oauth/callback', $out['callbackUrl']);
		$this->assertIsArray($stored);
	}

	public function testIsNativeOAuthStateTrueWhenFlagSet(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with('pm:abc')->willReturn([
			'client' => 'native',
			'exp' => 1_700_000_600,
			'workspaceId' => 1,
			'userId' => 'alex',
		]);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$svc = new PersonalMailOAuthService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ICrypto::class),
			$this->createMock(ISecureRandom::class),
			$time,
			$this->createMock(IL10N::class),
			$this->createMock(PersonalMailService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(IDBConnection::class),
			$cacheFactory,
			$this->createMock(RateLimitService::class),
			$this->createMock(LoggerInterface::class),
		);
		$this->assertTrue($svc->isNativeOAuthState('abc'));
	}

	public function testIsNativeOAuthStateFalseForWebClient(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with('pm:abc')->willReturn([
			'client' => 'web',
			'exp' => 1_700_000_600,
		]);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$svc = new PersonalMailOAuthService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ICrypto::class),
			$this->createMock(ISecureRandom::class),
			$time,
			$this->createMock(IL10N::class),
			$this->createMock(PersonalMailService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(IDBConnection::class),
			$cacheFactory,
			$this->createMock(RateLimitService::class),
			$this->createMock(LoggerInterface::class),
		);
		$this->assertFalse($svc->isNativeOAuthState('abc'));
	}
}
