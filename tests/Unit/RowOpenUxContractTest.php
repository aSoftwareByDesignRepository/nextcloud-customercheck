<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every list table exposes an unmistakable per-row primary button
 * (Open / Edit / Edit deal / Include) — never name-link-only.
 */
final class RowOpenUxContractTest extends TestCase
{
	public function testSharedRowOpenPartialExists(): void
	{
		$path = dirname(__DIR__, 2) . '/templates/common/crm-row-open.php';
		$this->assertFileExists($path);
		$html = (string)file_get_contents($path);
		$this->assertStringContainsString('crm-btn--primary', $html);
		$this->assertStringContainsString('crmRowOpenHref', $html);
	}

	/**
	 * @return list<array{0: string, 1: list<string>}>
	 */
	public function listTemplatesProvider(): array
	{
		return [
			['companies.php', ['crm-row-open.php', "t('Open')", 'crm-table-actions']],
			['agreements.php', ['crm-row-open.php', 'crm-table-actions']],
			['money.php', ['crm-row-open.php', "t('Open')", 'crm-table-actions']],
			['activities.php', ['crm-row-open.php', "t('Open')", 'crm-table-actions']],
			['pipeline.php', ["t('Edit deal')", 'crm-btn--primary', 'crm-table-actions']],
			['today.php', ['crm-table-actions', 'crm-btn--primary']],
			['import.php', ["t('Include')", 'crm-table-actions-col']],
			['company-detail.php', ["t('Edit deal')", 'crm-row-open.php', 'crm-table-actions']],
		];
	}

	/**
	 * @dataProvider listTemplatesProvider
	 * @param list<string> $needles
	 */
	public function testListTemplateHasObviousRowCta(string $file, array $needles): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
		foreach ($needles as $needle) {
			$this->assertStringContainsString($needle, $html, $file . ' missing ' . $needle);
		}
	}

	public function testImportJsRendersIncludeAsButtonFace(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/import.js');
		$this->assertStringContainsString('crm-import-include', $js);
		$this->assertStringContainsString("t('Include')", $js);
		$this->assertStringContainsString('crm-btn--primary', $js);
		$this->assertStringContainsString('crm-table-actions', $js);
	}

	public function testPersonaSuiteTablesAlwaysRenderPrimaryOpen(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-btn crm-btn--sm crm-btn--primary', $js);
		$this->assertStringContainsString('crm-table-actions', $js);
		$this->assertStringContainsString("t('Coach')", $js);
		$this->assertMatchesRegularExpression(
			'/data-crm-one-on-one=.*crm-btn--sm crm-btn--primary|crm-btn--sm crm-btn--primary.*data-crm-one-on-one/s',
			$js,
		);
	}

	public function testEmailsRelatedRowsUseOpenButtonNotBareSubjectLink(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('crm-row-open.php', $html);
		$this->assertStringContainsString("t('Open in Mail')", $html);
		$this->assertStringContainsString('crm-table-actions-col', $html);
	}
}
