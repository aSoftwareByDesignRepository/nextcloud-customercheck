<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Access settings must expose Open/Restricted clearly so operators do not
 * believe the door is open while restriction remains on (fail-closed default).
 */
final class OpenAccessModeContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testAccessTemplateUsesAccessModeSelect(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/settings/access.php');
		self::assertStringContainsString('crm-access-mode', $src);
		self::assertStringContainsString('Open — every logged-in user can open the app', $src);
		self::assertStringContainsString('Restricted — only allow-listed users and groups can open the app', $src);
		self::assertStringContainsString('Open shows CustomerCheck in the menu for everyone.', $src);
		self::assertStringNotContainsString('id="crm-access-restriction"', $src);
	}

	public function testSettingsJsWiresOpenModeConfirm(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		self::assertStringContainsString("modeSelect.value === 'open'", $js);
		self::assertStringContainsString('confirmOpenAccess', $js);
		self::assertStringNotContainsString('crm-access-restriction', $js);
	}

	public function testCanUseAppReturnsTrueWhenRestrictionOff(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/AccessControlService.php');
		$start = strpos($src, 'public function canUseApp(string $userId): bool');
		self::assertNotFalse($start);
		$end = strpos($src, 'public function denialReasonWhenCannotUseApp', $start);
		self::assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		self::assertStringContainsString('if (!$this->isAccessRestrictionEnabled())', $body);
		self::assertStringContainsString('return true;', $body);
		self::assertStringNotContainsString('getRoles', $body);
	}

	public function testBootDoesNotLetTokenSeedingBlockNavigation(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/AppInfo/Application.php');
		self::assertStringContainsString('ensureTokensExist()', $src);
		// Two separate try blocks: seeding must not wrap navigation.
		$bootStart = strpos($src, 'public function boot(IBootContext $context): void');
		self::assertNotFalse($bootStart);
		$boot = substr($src, $bootStart, 1200);
		self::assertGreaterThanOrEqual(
			2,
			substr_count($boot, 'try {'),
			'Token seeding and navigation must use separate try blocks',
		);
		$seedPos = strpos($boot, 'ensureTokensExist()');
		$navPos = strpos($boot, 'canUseApp');
		self::assertNotFalse($seedPos);
		self::assertNotFalse($navPos);
		self::assertLessThan($navPos, $seedPos);
	}
}
