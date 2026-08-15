<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Companies page must resolve duplicate-name keys via CompanyService —
 * calling a missing method fatals the whole list (prod 2026-08-13).
 */
final class CompaniesPageDuplicateKeysContractTest extends TestCase
{
	public function testPageControllerCompaniesCallsActiveDuplicateNameKeys(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString(
			'activeDuplicateNameKeys($wsId)',
			$src,
			'companies() must request duplicate name keys for the workspace'
		);
		$svc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CompanyService.php');
		$this->assertMatchesRegularExpression(
			'/function\s+activeDuplicateNameKeys\s*\(\s*int\s+\$workspaceId\s*\)/',
			$svc,
			'CompanyService must expose activeDuplicateNameKeys (PageController crash otherwise)'
		);
	}
}
