<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Bachus UX simplify — progressive Review, Conflicts, agreement create, settings. */
final class BachusSimplicityUxContractTest extends TestCase
{
	public function testReviewUsesProgressivePrimaryAndKindChips(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-review-primary', $js);
		$this->assertStringContainsString('crm-quick-filters', $js);
		$this->assertStringContainsString("t('More options')", $js);
		$this->assertStringContainsString("t('Suggested: %s'", $js);
		$this->assertStringContainsString('data-crm-review-none', $js);
		$this->assertStringContainsString("t('Shared mail')", $js);
		$this->assertStringNotContainsString("t('Review inbox')) + '</h2></header>'", $js);
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/review.php');
		$this->assertStringContainsString('crm-review-disclosure', $tpl);
		$this->assertStringNotContainsString('crm-review-board-title', $tpl);
	}

	public function testConflictsUseHumanCopyAndHideManual(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString("t('Keep what’s saved now')", $js);
		$this->assertStringContainsString("t('Take the other edit')", $js);
		$this->assertStringContainsString('crm-conflict-manual', $js);
		$this->assertStringContainsString('entityLabel', $js);
		$this->assertStringContainsString("t('Back to Today')", $js);
		$this->assertStringNotContainsString("t('Parallel edits are detected with a three-way compare", $js);
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/EditConflictService.php');
		$this->assertStringContainsString("'entityLabel'", $svc);
		$this->assertStringContainsString('entityLabelFromSnapshots', $svc);
	}

	public function testReviewSourcesAreFirstClassSettingsCard(): void
	{
		$settings = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings.php');
		$this->assertStringContainsString('workspace-review-sources.php', $settings);
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-review-sources.php');
		$this->assertStringContainsString('id="crm-review-sources"', $src);
		$this->assertStringContainsString('crm-card__title', $src);
		$mailbox = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringNotContainsString('id="crm-review-sources"', $mailbox);
	}

	public function testAgreementFormDemotesExpertFields(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/agreement-form.php');
		$this->assertStringContainsString('crm-agreement-advanced', $html);
		$this->assertStringContainsString('crm-ag-essentials', $html);
		$this->assertStringContainsString('What matters', $html);
		$this->assertStringContainsString('crm-agreement-end', $html);
		$this->assertStringContainsString('Renew for one year', $html);
		$this->assertStringNotContainsString("if (\$mode === 'edit'): ?> open", $html);
		// Essentials before More: label + amount + until; type/product/invoice inside More.
		$this->assertLessThan(
			(int)strpos($html, 'crm-agreement-advanced'),
			(int)strpos($html, 'crm-ag-label'),
		);
		$this->assertLessThan(
			(int)strpos($html, 'crm-agreement-advanced'),
			(int)strpos($html, 'crm-ag-amount'),
		);
		$this->assertLessThan(
			(int)strpos($html, 'crm-agreement-advanced'),
			(int)strpos($html, 'crm-ag-until'),
		);
		$this->assertGreaterThan(
			(int)strpos($html, 'crm-agreement-advanced'),
			(int)strpos($html, 'crm-ag-kind'),
		);
		$this->assertGreaterThan(
			(int)strpos($html, 'crm-agreement-advanced'),
			(int)strpos($html, 'crm-ag-invoice-status'),
		);
	}

	public function testEmailsSectionIsCollapsibleUnderTalked(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('<details', $html);
		$this->assertStringContainsString('id="crm-emails"', $html);
		$this->assertStringNotContainsString('id="crm-log-email"', $html);
	}

	public function testReviewAndConflictsHaveQuickStarts(): void
	{
		$this->assertContains('review', \OCA\CustomerCheck\Service\PageQuickStartCatalog::requiredPageIds());
		$this->assertContains('conflicts', \OCA\CustomerCheck\Service\PageQuickStartCatalog::requiredPageIds());
		$catalog = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageQuickStartCatalog.php');
		$this->assertStringContainsString("'review' => [", $catalog);
		$this->assertStringContainsString("'conflicts' => [", $catalog);
		$help = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString("'review' =>", $help);
		$this->assertStringContainsString("'conflicts' =>", $help);
	}

	public function testImportUsesSourceTilesAndPlainPrimaryCta(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/import.php');
		$this->assertStringContainsString('crm-import-tile', $tpl);
		$this->assertStringContainsString('Where should we look?', $tpl);
		$this->assertStringContainsString('Check my list', $tpl);
		$this->assertStringContainsString('Import ready', $tpl);
		$this->assertStringContainsString('crm-import-stats', $tpl);
		$this->assertStringContainsString('id="crm-import-file"', $tpl);
		$this->assertStringContainsString('crm-import-drop', $tpl);
		$this->assertStringContainsString('accept=".csv,.json,.txt', $tpl);
		$this->assertStringNotContainsString('Import clear rows', $tpl);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/import.js');
		$this->assertStringContainsString('Looking for matches', $js);
		$this->assertStringContainsString('runScan', $js);
		$this->assertStringContainsString('readImportFile', $js);
		$this->assertStringNotContainsString('%s rows · %s ready · %s need a choice · %s skip', $js);
		$help = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString('nothing is saved until you say yes', $help);
	}
}
