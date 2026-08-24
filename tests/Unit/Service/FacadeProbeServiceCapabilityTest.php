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
}
