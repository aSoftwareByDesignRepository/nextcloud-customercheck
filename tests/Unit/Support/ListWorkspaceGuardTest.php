<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ListWorkspaceGuard;
use PHPUnit\Framework\TestCase;

final class ListWorkspaceGuardTest extends TestCase
{
	public function testZeroAndNegativeAreNotListable(): void
	{
		$this->assertFalse(ListWorkspaceGuard::isListable(0));
		$this->assertFalse(ListWorkspaceGuard::isListable(-1));
	}

	public function testPositiveWorkspaceIsListable(): void
	{
		$this->assertTrue(ListWorkspaceGuard::isListable(1));
		$this->assertTrue(ListWorkspaceGuard::isListable(42));
	}

	public function testCompanyListRequiresWorkspace(): void
	{
		$this->assertFalse(ListWorkspaceGuard::companyListIsScoped([]));
		$this->assertFalse(ListWorkspaceGuard::companyListIsScoped(['workspaceId' => 0]));
		$this->assertFalse(ListWorkspaceGuard::companyListIsScoped(['workspaceId' => -3]));
		$this->assertFalse(ListWorkspaceGuard::companyListIsScoped(['search' => 'acme']));
		$this->assertTrue(ListWorkspaceGuard::companyListIsScoped(['workspaceId' => 1]));
		$this->assertTrue(ListWorkspaceGuard::companyListIsScoped(['workspaceIds' => []]));
		$this->assertTrue(ListWorkspaceGuard::companyListIsScoped(['workspaceIds' => [4, 8]]));
	}

	public function testChildListAllowsCompanyOrDealWithoutWorkspace(): void
	{
		$this->assertFalse(ListWorkspaceGuard::childListIsScoped([]));
		$this->assertFalse(ListWorkspaceGuard::childListIsScoped(['openOnly' => true]));
		$this->assertFalse(ListWorkspaceGuard::childListIsScoped(['companyId' => 0]));
		$this->assertFalse(ListWorkspaceGuard::childListIsScoped(['dealId' => 0]));
		$this->assertFalse(ListWorkspaceGuard::childListIsScoped(['companyIds' => []]));
		$this->assertFalse(ListWorkspaceGuard::childListIsScoped(['companyIds' => [0, -1]]));
		$this->assertTrue(ListWorkspaceGuard::childListIsScoped(['companyId' => 9]));
		$this->assertTrue(ListWorkspaceGuard::childListIsScoped(['dealId' => 12]));
		$this->assertTrue(ListWorkspaceGuard::childListIsScoped(['companyIds' => [3]]));
		$this->assertTrue(ListWorkspaceGuard::childListIsScoped(['workspaceId' => 2]));
		$this->assertTrue(ListWorkspaceGuard::childListIsScoped(['workspaceIds' => [2]]));
	}
}
