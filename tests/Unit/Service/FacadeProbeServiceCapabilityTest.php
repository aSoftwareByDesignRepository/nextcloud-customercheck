<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\FacadeProbeService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FacadeProbeServiceCapabilityTest extends TestCase
{
	public function testCapabilityMatrixMarksDisabledAppsUnusableWithReason(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);

		$service = new FacadeProbeService(
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IURLGenerator::class),
		);

		$matrix = $service->capabilityMatrix();

		$this->assertFalse($matrix['sbdlicenseops']);
		$this->assertFalse($matrix['invoicecheck']);
		$this->assertFalse($matrix['projectcheck']);
		$this->assertFalse($matrix['ticketcheck']);
		$this->assertFalse($matrix['mail']);

		foreach ([
			'sbdlicenseops_detail',
			'invoicecheck_detail',
			'projectcheck_detail',
			'ticketcheck_detail',
			'maintenancecheck_detail',
			'mail_detail',
		] as $key) {
			$detail = $matrix[$key];
			$this->assertIsArray($detail);
			$this->assertFalse($detail['usable']);
			$this->assertSame('disabled', $detail['reason']);
		}
		$this->assertFalse($matrix['maintenancecheck']);
		$this->assertFalse($matrix['projectcheckWrite']);
		$this->assertFalse($matrix['ticketcheckWrite']);
	}

	public function testMailComposeUrlUsesPageIndexRoute(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('mail')->willReturn(true);

		// mail registers 'page#index'; 'mail.home.index' never existed —
		// linkToRoute would throw RouteNotFoundException (drift class
		// dead_platform_deeplink, sweep-20261023)
		$url = $this->createMock(IURLGenerator::class);
		$url->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('mail.page.index')
			->willReturn('https://cloud.example.org/apps/mail/');

		$service = new FacadeProbeService(
			$apps,
			$this->createMock(LoggerInterface::class),
			$url,
		);

		$out = $service->mailComposeUrl('a@b.c', 'Sub ject', 'Body text');

		$this->assertIsString($out);
		$this->assertStringStartsWith('https://cloud.example.org/apps/mail/#compose?', $out);
		$this->assertStringContainsString('to=a%40b.c', $out);
		$this->assertStringContainsString('subject=Sub%20ject', $out);
	}

	public function testMailComposeUrlReturnsNullWhenMailDisabled(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('mail')->willReturn(false);

		$service = new FacadeProbeService(
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IURLGenerator::class),
		);

		$this->assertNull($service->mailComposeUrl('a@b.c', 's', 'b'));
	}
}
