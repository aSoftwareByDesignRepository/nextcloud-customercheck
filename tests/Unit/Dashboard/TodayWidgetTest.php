<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Dashboard;

use OCA\CustomerCheck\Dashboard\TodayWidget;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AppIconService;
use OCA\CustomerCheck\Service\TodayService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TodayWidgetTest extends TestCase
{
	private AccessControlService&MockObject $access;
	private TodayService&MockObject $today;
	private IUserSession&MockObject $session;
	private TodayWidget $widget;

	protected function setUp(): void
	{
		parent::setUp();
		$this->access = $this->createMock(AccessControlService::class);
		$this->today = $this->createMock(TodayService::class);
		$this->session = $this->createMock(IUserSession::class);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/customercheck/img/app-dashboard.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $p) => 'https://nc.test' . $p);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/customercheck/today');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => vsprintf(str_replace('%%', '%', $s), $a));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.0.4');
		$icons = new AppIconService($url, $apps);
		$this->widget = new TodayWidget($l10n, $url, $this->today, $this->access, $this->session, $icons);
	}

	public function testIconUrlUsesDarkSurfaceAsset(): void
	{
		$this->assertStringContainsString('app-dashboard.svg', $this->widget->getIconUrl());
	}

	public function testDisabledWhenUserCannotUseApp(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->session->method('getUser')->willReturn($user);
		$this->access->method('canUseApp')->with('bob')->willReturn(false);
		$this->assertFalse($this->widget->isEnabled());
	}

	public function testItemsEmptyForDeniedUser(): void
	{
		$this->access->method('canUseApp')->with('bob')->willReturn(false);
		$this->today->expects($this->never())->method('build');
		$items = $this->widget->getItemsV2('bob');
		$this->assertSame([], $items->getItems());
	}

	public function testItemsFromTodayQueue(): void
	{
		$this->access->method('canUseApp')->with('alice')->willReturn(true);
		$this->today->method('build')->willReturn([
			'items' => [
				['kind' => 'agreement_expiry', 'label' => 'AZC renewal', 'dueDate' => '2026-08-01', 'companyId' => 1],
			],
			'meta' => ['truncated' => false, 'total' => 1],
		]);
		$items = $this->widget->getItemsV2('alice', null, 7);
		$this->assertCount(1, $items->getItems());
		$this->assertSame('AZC renewal', $items->getItems()[0]->getTitle());
	}
}
