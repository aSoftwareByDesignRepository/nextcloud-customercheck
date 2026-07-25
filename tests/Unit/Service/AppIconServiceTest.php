<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\AppIconService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Theme-surface icon resolution — header (white) vs dashboard/search (dark).
 */
final class AppIconServiceTest extends TestCase
{
	private function service(IURLGenerator $url, string $version = '1.0.4'): AppIconService
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->with('customercheck')->willReturn($version);
		return new AppIconService($url, $apps);
	}

	public function testHeaderIconUsesWhiteAppSvgWithVersionBust(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->expects($this->once())
			->method('imagePath')
			->with('customercheck', 'app.svg')
			->willReturn('/apps/customercheck/img/app.svg');

		$svc = $this->service($url, '1.0.4');
		$this->assertSame('/apps/customercheck/img/app.svg?v=1.0.4', $svc->headerIconPath());
	}

	public function testHeaderIconSkipsBustWhenVersionEmpty(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/customercheck/img/app.svg');

		$svc = $this->service($url, '');
		$this->assertSame('/apps/customercheck/img/app.svg', $svc->headerIconPath());
	}

	public function testSurfaceIconPrefersDashboardThenDark(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturnCallback(static function (string $app, string $file): string {
			if ($file === 'app-dashboard.svg') {
				throw new RuntimeException('missing');
			}
			if ($file === 'app-dark.svg') {
				return '/apps/customercheck/img/app-dark.svg';
			}
			throw new RuntimeException('unexpected ' . $file);
		});

		$svc = $this->service($url, '2.0.0');
		$this->assertSame('/apps/customercheck/img/app-dark.svg?v=2.0.0', $svc->surfaceIconPath());
	}

	public function testSurfaceIconFallsBackToAppSvgThenCore(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturnCallback(static function (string $app, string $file): string {
			if (in_array($file, ['app-dashboard.svg', 'app-dark.svg'], true)) {
				throw new RuntimeException('missing');
			}
			if ($app === 'customercheck' && $file === 'app.svg') {
				throw new RuntimeException('missing white');
			}
			if ($app === 'core' && $file === 'places/contacts.svg') {
				return '/core/img/places/contacts.svg';
			}
			throw new RuntimeException('unexpected ' . $app . '/' . $file);
		});

		$svc = $this->service($url, '1.0.4');
		// Core fallback is not app-owned — no version bust.
		$this->assertSame('/core/img/places/contacts.svg', $svc->surfaceIconPath());
	}

	public function testAbsoluteSurfaceIconUrlPrefixesHost(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/customercheck/img/app-dashboard.svg');
		$url->method('getAbsoluteURL')
			->with('/apps/customercheck/img/app-dashboard.svg?v=1.0.4')
			->willReturn('https://nc.test/apps/customercheck/img/app-dashboard.svg?v=1.0.4');

		$svc = $this->service($url, '1.0.4');
		$this->assertSame(
			'https://nc.test/apps/customercheck/img/app-dashboard.svg?v=1.0.4',
			$svc->absoluteSurfaceIconUrl()
		);
	}

	public function testWithCacheBustPreservesExistingQuery(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/customercheck/img/app.svg?existing=1');

		$svc = $this->service($url, '1.0.4');
		$this->assertSame('/apps/customercheck/img/app.svg?existing=1&v=1.0.4', $svc->headerIconPath());
	}
}
