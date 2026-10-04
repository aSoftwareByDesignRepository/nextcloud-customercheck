<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Permanent probe for must_fix `cuc-settings-knob-bounds-and-bool-validation`:
 * SettingsService::update must reject out-of-range numerics (digest_hour=99
 * would silently disable digests forever) and non-boolean garbage on bool
 * knobs with a field-level ValidationException (400), never persist them.
 */
final class SettingsServiceKnobBoundsTest extends TestCase
{
	/** @param array<string, string> $stored */
	private function service(array &$stored): SettingsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, ?string $default = null) use (&$stored): string {
				if (isset($stored[$key])) {
					return $stored[$key];
				}
				return match ($key) {
					Constants::CONFIG_ICS_TOKEN, Constants::CONFIG_LEAD_TOKEN, Constants::CONFIG_ORDER_TOKEN => str_repeat('a', 64),
					default => (string)($default ?? Constants::CONFIG_DEFAULTS[$key] ?? '[]'),
				};
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);
		$config->method('getUserValue')->willReturn('table');

		$access = $this->createMock(AccessControlService::class);
		$access->method('canManageAppConfiguration')->willReturn(true);
		$access->method('isSystemAdmin')->willReturn(true);
		$access->method('isAppAdmin')->willReturn(true);
		$access->method('isFailClosedWithEmptyAllowLists')->willReturn(false);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/x');

		return new SettingsService(
			$config,
			$access,
			$this->createMock(ISecureRandom::class),
			$url,
			$this->createMock(IUserManager::class),
		);
	}

	/** @return iterable<string, array{0: string, 1: mixed}> */
	public static function outOfRangeKnobs(): iterable {
		yield 'digest_hour above max' => [Constants::CONFIG_DIGEST_HOUR, 99];
		yield 'digest_hour below min' => [Constants::CONFIG_DIGEST_HOUR, -1];
		yield 'digest_hour non-integer' => [Constants::CONFIG_DIGEST_HOUR, 9.5];
		yield 'digest_hour garbage string' => [Constants::CONFIG_DIGEST_HOUR, 'soon'];
		yield 'reminder_hour above max' => [Constants::CONFIG_REMINDER_HOUR, 24];
		yield 'digest_weekday below min' => [Constants::CONFIG_DIGEST_WEEKDAY, 0];
		yield 'digest_weekday above max' => [Constants::CONFIG_DIGEST_WEEKDAY, 8];
		yield 'stale_deal_days below min' => [Constants::CONFIG_STALE_DEAL_DAYS, 0];
		yield 'stale_deal_days above max' => [Constants::CONFIG_STALE_DEAL_DAYS, 366];
	}

	/** @dataProvider outOfRangeKnobs */
	public function testOutOfRangeNumericKnobRejected(string $key, mixed $value): void
	{
		$stored = [];
		$svc = $this->service($stored);
		try {
			$svc->update('admin', [$key => $value]);
			$this->fail("update() accepted out-of-range {$key}");
		} catch (ValidationException) {
			$this->assertArrayNotHasKey($key, $stored, 'rejected knob must not persist');
		}
	}

	public function testInRangeNumericKnobPersists(): void
	{
		$stored = [];
		$svc = $this->service($stored);
		$svc->update('admin', [
			Constants::CONFIG_DIGEST_HOUR => 7,
			Constants::CONFIG_DIGEST_WEEKDAY => 5,
			Constants::CONFIG_STALE_DEAL_DAYS => 30,
		]);
		$this->assertSame('7', (string)($stored[Constants::CONFIG_DIGEST_HOUR] ?? ''));
		$this->assertSame('5', (string)($stored[Constants::CONFIG_DIGEST_WEEKDAY] ?? ''));
		$this->assertSame('30', (string)($stored[Constants::CONFIG_STALE_DEAL_DAYS] ?? ''));
	}

	public function testNonBooleanGarbageOnBoolKnobRejected(): void
	{
		$stored = [];
		$svc = $this->service($stored);
		try {
			$svc->update('admin', [Constants::CONFIG_DIGEST_ENABLED => 'banana']);
			$this->fail('update() accepted non-boolean digest_enabled');
		} catch (ValidationException) {
			$this->assertArrayNotHasKey(Constants::CONFIG_DIGEST_ENABLED, $stored);
		}
	}

	public function testBoolKnobAcceptsBoolAndStringTokens(): void
	{
		$stored = [];
		$svc = $this->service($stored);
		$svc->update('admin', [Constants::CONFIG_DIGEST_ENABLED => false]);
		$this->assertArrayHasKey(Constants::CONFIG_DIGEST_ENABLED, $stored);
		$svc->update('admin', [Constants::CONFIG_DIGEST_ENABLED => 'true']);
		$this->assertArrayHasKey(Constants::CONFIG_DIGEST_ENABLED, $stored);
	}
}
