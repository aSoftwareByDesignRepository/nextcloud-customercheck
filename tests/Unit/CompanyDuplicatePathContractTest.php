<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Companies list + import must expose a clear path when name twins exist.
 */
final class CompanyDuplicatePathContractTest extends TestCase
{
	public function testCompaniesListSurfacesDuplicatesAndMergePath(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		$this->assertStringContainsString('duplicateNameKeys', $html);
		$this->assertStringContainsString('crm-companies-dup-title', $html);
		$this->assertStringContainsString("t('Same name')", $html);
		$this->assertStringContainsString('Merge into…', $html);
		$this->assertStringContainsString('crm-callout--warning', $html);
		$this->assertStringContainsString('role="status"', $html);
	}

	public function testPageControllerLoadsDuplicateNameKeysForActiveList(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('activeDuplicateNameKeys', $src);
		$this->assertStringContainsString("'duplicateNameKeys' => \$duplicateNameKeys", $src);
	}

	public function testImportMatcherSkipsArchivedAndBlockedSlugs(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ImportMatcher.php');
		$this->assertStringContainsString('$slugBlockedForCreate', $src);
		$this->assertStringContainsString('isArchived()', $src);
		$this->assertStringContainsString('!$slugBlockedForCreate', $src);
	}

	public function testImportUiDistinguishesCandidatesAndExplainsMatchOrder(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/import.js');
		$this->assertStringContainsString('function candidateOptionLabel(c) {', $js);
		$this->assertStringContainsString('candidateOptionLabel(c)', $js);
		$this->assertStringContainsString("t('matched by id')", $js);
		$this->assertStringContainsString("t('matched by name')", $js);
		$this->assertStringContainsString("t('matched by email')", $js);
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/import.php');
		$this->assertStringContainsString('We match by company id, exact name, then contact email', $html);
	}

	public function testMapperExposesActiveDuplicateNameKeys(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Db/CompanyMapper.php');
		$this->assertStringContainsString('function listActiveNames', $src);
		$this->assertStringContainsString('function activeDuplicateNameKeys', $src);
		$this->assertStringContainsString('CompanyDuplicateLogic::duplicateKeysFromNames', $src);
	}
}
