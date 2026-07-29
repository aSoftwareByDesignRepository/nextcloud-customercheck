<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\FacadeProbeService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * WP-S1-CUC-ABSENCE — runtime soft-fail when siblings are disabled (no HTTP).
 */
final class FacadeProbeAbsenceTest extends TestCase
{
	public function testMissingSiblingsReportUnavailableWithoutThrowing(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);

		$probe = new FacadeProbeService($apps, new NullLogger(), $this->createMock(IURLGenerator::class));

		$this->assertFalse($probe->projectcheckAvailable());
		$this->assertFalse($probe->ticketcheckAvailable());
		$this->assertFalse($probe->invoicecheckAvailable());
		$matrix = $probe->capabilityMatrix();
		$this->assertIsArray($matrix);
		foreach (['projectcheck', 'ticketcheck', 'invoicecheck'] as $key) {
			$this->assertArrayHasKey($key, $matrix);
			$this->assertFalse((bool)$matrix[$key]);
		}
	}
}
