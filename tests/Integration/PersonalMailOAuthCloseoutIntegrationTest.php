<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use Test\TestCase;

/**
 * Boots real DI for personal-mail closeout surfaces (US-002 notify + OAuth native).
 */
final class PersonalMailOAuthCloseoutIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (set NEXTCLOUD_ROOT or run inside Docker).');
		}
	}

	public function testMailboxServiceResolvesWithOptionalNotifications(): void
	{
		$svc = \OC::$server->get(MailboxService::class);
		$this->assertInstanceOf(MailboxService::class, $svc);
		$ref = new \ReflectionClass($svc);
		$this->assertTrue($ref->hasMethod('recordAuthFailure'));
		$notify = $ref->getMethod('notifyMailboxAuthQuarantine');
		$this->assertTrue($notify->isPrivate());
	}

	public function testOAuthServiceExposesNativeStateProbe(): void
	{
		$svc = \OC::$server->get(PersonalMailOAuthService::class);
		$this->assertInstanceOf(PersonalMailOAuthService::class, $svc);
		$this->assertFalse($svc->isNativeOAuthState(''));
		$this->assertFalse($svc->isNativeOAuthState('missing-state-token'));
	}
}
