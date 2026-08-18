<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\PlatformLinksService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Platform chips must never advertise apps the user cannot open.
 */
final class PlatformLinksServiceTest extends TestCase
{
	/**
	 * @param list<string> $enabled
	 */
	private function service(array $enabled): PlatformLinksService
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $enabled, true),
		);
		$apps->method('isEnabledForUser')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $enabled, true),
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.test' . $path,
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		return new PlatformLinksService($apps, $urls, $l10n);
	}

	public function testEmptyWhenNoSiblingAppsEnabled(): void
	{
		$svc = $this->service([]);
		$this->assertSame([], $svc->forUser('alice'));
	}

	public function testEmptyUserGetsNoChips(): void
	{
		$svc = $this->service(['files', 'calendar', 'tasks']);
		$this->assertSame([], $svc->forUser(''));
		$this->assertSame([], $svc->forUser('   '));
	}

	public function testFilesIsNotForcedWhenDisabled(): void
	{
		$svc = $this->service(['calendar']);
		$out = $svc->forUser('alice');
		$this->assertArrayHasKey('calendar', $out);
		$this->assertArrayNotHasKey('files', $out);
		$this->assertArrayNotHasKey('tasks', $out);
		$this->assertSame('https://nc.test/apps/calendar/', $out['calendar']['url']);
		$this->assertTrue($out['calendar']['available']);
		$this->assertSame('Calendar', $out['calendar']['label']);
	}

	public function testTasksRequiresTheTasksAppNotCalDav(): void
	{
		$svc = $this->service(['files']);
		$out = $svc->forUser('alice', 42);
		$this->assertSame(['files'], array_keys($out));
		$this->assertSame('https://nc.test/apps/files/?fileid=42', $out['files']['url']);
		$this->assertArrayNotHasKey('tasks', $out);
	}

	public function testTalkUsesSpreedAppId(): void
	{
		$svc = $this->service(['spreed']);
		$out = $svc->forUser('alice');
		$this->assertSame(['talk'], array_keys($out));
		$this->assertSame('https://nc.test/apps/spreed/', $out['talk']['url']);
		$this->assertSame('Talk', $out['talk']['label']);
	}

	public function testInstalledButNotEnabledForUserIsOmitted(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(false);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->never())->method('getAbsoluteURL');
		$l10n = $this->createMock(IL10N::class);
		$svc = new PlatformLinksService($apps, $urls, $l10n);
		$this->assertSame([], $svc->forUser('alice'));
	}

	public function testNotInstalledAppIsOmittedEvenIfEnabledFlagLies(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(false);
		$apps->method('isEnabledForUser')->willReturn(true);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->never())->method('getAbsoluteURL');
		$l10n = $this->createMock(IL10N::class);
		$svc = new PlatformLinksService($apps, $urls, $l10n);
		$this->assertSame([], $svc->forUser('alice'));
	}

	public function testContractForbidsFilesShortcutAndCalDavTasksLie(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PlatformLinksService.php');
		$this->assertStringNotContainsString('$appId === \'files\'', $src);
		$this->assertStringNotContainsString('VTodoService', $src);
		$this->assertStringNotContainsString('vTodo', $src);
		$this->assertStringContainsString('isInstalled', $src);
		$this->assertStringContainsString('isEnabledForUser', $src);
		$this->assertStringContainsString('appOpenForUser', $src);
		$this->assertStringContainsString('$this->l10n->t($app[\'labelKey\'])', $src);
	}
}
