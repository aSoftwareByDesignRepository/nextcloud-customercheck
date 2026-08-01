<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class SettingsServiceTokenTest extends TestCase
{
	public function testEnsureTokensExistUsesHexAlphabetWithoutCharHexConstant(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$stored = [];
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->exactly(3))->method('generate')->with(
			64,
			ISecureRandom::CHAR_DIGITS . 'abcdef',
		)->willReturnOnConsecutiveCalls(str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64));

		$access = $this->createMock(AccessControlService::class);
		$url = $this->createMock(IURLGenerator::class);
		$svc = new SettingsService($config, $access, $random, $url, $this->createMock(IUserManager::class));
		$svc->ensureTokensExist();

		$this->assertSame(str_repeat('a', 64), $stored[Constants::CONFIG_ICS_TOKEN] ?? null);
		$this->assertSame(str_repeat('b', 64), $stored[Constants::CONFIG_LEAD_TOKEN] ?? null);
		$this->assertSame(str_repeat('c', 64), $stored[Constants::CONFIG_ORDER_TOKEN] ?? null);
		$this->assertSame(Application::APP_ID, Application::APP_ID);
	}
}
