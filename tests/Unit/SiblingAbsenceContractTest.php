<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * D6 / WP-S1-CUC-ABSENCE — hub pages must degrade when siblings are missing.
 * Contract tests (no live HTTP): templates mute panels; facades soft-fail.
 */
final class SiblingAbsenceContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testCompanyDetailHasMutedSiblingCopy(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString('TicketCheck is not available', $src);
		$this->assertStringContainsString('ProjectCheck is not available', $src);
		$this->assertStringContainsString('id="crm-glance"', $src);
		$this->assertStringContainsString('Unavailable', $src);
	}

	public function testFacadeProbeReturnsEmptyOnMissingApps(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/FacadeProbeService.php');
		$this->assertStringContainsString('projectcheckAvailable', $src);
		$this->assertStringContainsString('return []', $src);
		$this->assertStringNotContainsString('throw new \\RuntimeException', $src);
	}

	public function testCompaniesEmptyStateHasImportCta(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/companies.php');
		$this->assertStringContainsString('crm-empty-state', $src);
		$this->assertStringContainsString('Add company', $src);
		$this->assertStringContainsString('Import', $src);
		$this->assertStringContainsString('optional', $src);
	}

	public function testDegradeProfileDocumentedInSuiteAssert(): void
	{
		// Host monorepo path (not mounted into the Nextcloud container).
		$path = dirname($this->root, 2) . '/docker/suite/assert-profile.sh';
		if (!is_file($path)) {
			$this->assertStringContainsString(
				'not available',
				(string)file_get_contents($this->root . '/templates/company-detail.php'),
				'Degrade copy must live in company-detail when suite assert script is not mounted',
			);
			return;
		}
		$body = (string)file_get_contents($path);
		$this->assertStringContainsString('degrade', $body);
		$this->assertStringContainsString('customercheck', $body);
	}

	public function testHubPageRoutesDoNotHardRequireSiblings(): void
	{
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		foreach (['page#today', 'page#companies', 'page#companyDetail', 'page#money'] as $name) {
			$this->assertStringContainsString($name, $routes, $name);
		}
		$page = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('FacadeProbeService', $page);
		$this->assertStringContainsString('capabilityMatrix', $page);
		// Soft degradation: no fatal when sibling apps are missing.
		$this->assertStringNotContainsString('throw new \\RuntimeException', $page);
	}

	public function testFacadeProbeNeverThrowsOnMissingSibling(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/FacadeProbeService.php');
		$this->assertStringContainsString('Never throws into a page render', $src);
		$this->assertStringContainsString("return ['available' => false", $src);
		$this->assertStringContainsString('warnOnce', $src);
	}
}
