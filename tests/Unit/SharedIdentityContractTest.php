<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shared identity (CHECK-SUITE I3): CustomerCheck links to ProjectCheck customers
 * via pc_customer_id — reuse, do not re-key a second commercial master.
 *
 * @see planning/check-productivity-suite/SHARED-IDENTITY.md
 */
final class SharedIdentityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testCompanySchemaHasPcCustomerIdLink(): void
	{
		$src = (string)file_get_contents(
			$this->root . '/lib/Migration/Version1000Date20260725120000.php'
		);
		$this->assertStringContainsString("createTable('crm_company')", $src);
		$this->assertStringContainsString("'pc_customer_id'", $src);
		$this->assertStringContainsString("'notnull' => false", $src);
	}

	public function testCompanyEntityExposesPcCustomerId(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/Company.php');
		$this->assertStringContainsString('setPcCustomerId', $src);
		$this->assertStringContainsString('pcCustomerId', $src);
	}

	public function testCompanyServiceAcceptsPcCustomerIdLinkInput(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/CompanyService.php');
		$this->assertStringContainsString("array_key_exists('pcCustomerId', \$input)", $src);
		$this->assertStringContainsString('setPcCustomerId', $src);
	}

	public function testSiblingContractsPointAtProjectAndInvoiceFacadesNotForeignSql(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Integration/SiblingFacadeContracts.php');
		$this->assertStringContainsString('CrmCustomerReadFacade', $src);
		$this->assertStringContainsString('CrmInvoiceReadFacade', $src);
		$this->assertStringNotContainsString('INSERT INTO pc_', $src);
		$this->assertStringNotContainsString('INSERT INTO ic_', $src);
	}

	public function testFacadeProbeListsProjectsAndInvoicesByPcCustomerId(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/FacadeProbeService.php');
		$this->assertStringContainsString('listProjectsForCustomer', $src);
		$this->assertStringContainsString('listInvoicesForPcCustomer', $src);
		$this->assertStringContainsString('listByPcCustomer', $src);
	}

	public function testCompanyMapperCanFindByPcCustomerId(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/CompanyMapper.php');
		$this->assertStringContainsString('function findByPcCustomerId', $src);
		$this->assertStringContainsString('pc_customer_id', $src);
	}

	public function testUniqueIndexesEnforceOneCompanyPerSiblingId(): void
	{
		$src = (string)file_get_contents(
			$this->root . '/lib/Migration/Version1003Date20260726193000.php'
		);
		$this->assertStringContainsString("addUniqueIndex(['pc_customer_id'], 'crm_co_pc_uq')", $src);
		$this->assertStringContainsString("addUniqueIndex(['tc_customer_id'], 'crm_co_tc_uq')", $src);
		$this->assertStringContainsString('dedupeSiblingLinks', $src);
	}

	public function testCompanyServiceMapsSiblingUniqueRacesToLinkConflict(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/CompanyService.php');
		$this->assertStringContainsString('classifySiblingUniqueViolation', $src);
		$this->assertStringContainsString('rethrowMappedUniqueViolation', $src);
		$this->assertStringContainsString('ERR_LINK_CONFLICT', $src);
		$this->assertStringContainsString('crm_co_pc_uq', $src);
		$this->assertStringContainsString('crm_co_tc_uq', $src);
	}
}
