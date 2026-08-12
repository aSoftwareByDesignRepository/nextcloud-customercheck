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
		$this->assertStringContainsString("t('Remind me later')", $js);
		$this->assertStringContainsString("t('Delete mail')", $js);
		$this->assertStringContainsString("t('Remove from Review')", $js);
		$this->assertStringContainsString("t('Link it to a company, mark it unrelated, or remove it from this inbox.')", $js);
		$this->assertStringContainsString("t('Suggested: %s'", $js);
		$this->assertStringContainsString('data-crm-review-none', $js);
		$this->assertStringContainsString("t('Shared mail')", $js);
		$this->assertStringContainsString('data-crm-review-quick-assign', $js);
		$this->assertStringContainsString("t('Assign to %s'", $js);
		$this->assertStringContainsString("t('Assign to a company')", $js);
		$this->assertStringContainsString('crm-review-pick-company', $js);
		$this->assertStringContainsString('crm-review-pick-other', $js);
		$this->assertStringContainsString('crm-review-zone--decide', $js);
		$this->assertStringContainsString('ensureMailPreviewsOpen', $js);
		$this->assertStringNotContainsString('details.crm-review-pick-company', $js);
		$this->assertStringContainsString('renderReviewBoard', $js);
		$this->assertStringContainsString('crm-review-row', $js);
		$this->assertStringContainsString('crm-review-toolbar', $js);
		$this->assertStringContainsString('crm-review-search__field', $js);
		$this->assertStringContainsString('crm-review-toolbar__extra', $js);
		$this->assertStringContainsString('crm-review-table', $js);
		$this->assertStringContainsString('data-crm-review-open', $js);
		$this->assertStringContainsString('data-crm-review-sort', $js);
		$this->assertStringContainsString("t('Open')", $js);
		$this->assertStringContainsString("t('Details')", $js);
		$this->assertStringContainsString('selectReviewItem', $js);
		$this->assertStringContainsString('Stay collapsed until the user opens a row', $js);
		$this->assertStringNotContainsString('threadRows[0].getAttribute', $js);
		$this->assertStringContainsString('let reviewLoadGeneration = 0', $js);
		$this->assertMatchesRegularExpression('/\+\+reviewLoadGeneration\b/', $js);
		$this->assertMatchesRegularExpression('/loadGen !== reviewLoadGeneration\b/', $js);
		$this->assertStringNotContainsString('crm-advanced crm-review-tools', $js);
		$this->assertStringNotContainsString('details.crm-review-tools', $js);
		$this->assertStringContainsString('bulkBar.hidden = n === 0', $js);
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

	public function testCompanyFormNeverForceOpensAdvancedOnEdit(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringContainsString('crm-company-essentials', $html);
		$this->assertStringContainsString('id="crm-company-advanced"', $html);
		$this->assertStringNotContainsString("if (\$mode === 'edit'): ?> open", $html);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-company-advanced"[^>]*\sopen/',
			$html,
		);
		$this->assertLessThan(
			(int)strpos($html, 'crm-company-advanced'),
			(int)strpos($html, 'crm-company-name'),
		);
		$this->assertGreaterThan(
			(int)strpos($html, 'crm-company-advanced'),
			(int)strpos($html, 'crm-company-status'),
		);
	}

	public function testPipelineEmptyStateOffersCreateCompanyWhenNoCompanies(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString('!$hasCompanies', $html);
		$this->assertStringContainsString("\$l->t('Create company')", $html);
		$this->assertStringContainsString("\$urls['companyNew']", $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString('offerCreateCompanyFirst', $js);
		$this->assertStringContainsString('companyNewUrl', $js);
		$this->assertStringContainsString("t('Create company')", $js);
		$this->assertStringContainsString('confirmDialog', $js);
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
		$this->assertStringContainsString('Add your list', $tpl);
		$this->assertStringContainsString('Check my list', $tpl);
		$this->assertStringContainsString('Import now', $tpl);
		$this->assertStringContainsString('crm-import-stats', $tpl);
		$this->assertStringContainsString('id="crm-import-file"', $tpl);
		$this->assertStringContainsString('crm-import-drop', $tpl);
		$this->assertStringContainsString('Paste instead', $tpl);
		$this->assertStringContainsString('accept=".csv,.json,.txt', $tpl);
		$this->assertStringNotContainsString('Import clear rows', $tpl);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/import.js');
		$this->assertStringContainsString('Looking for matches', $js);
		$this->assertStringContainsString('runScan', $js);
		$this->assertStringContainsString('readImportFile', $js);
		$this->assertStringContainsString('setPhase', $js);
		$this->assertStringContainsString("runScan({ auto: true })", $js);
		$this->assertStringNotContainsString('%s rows · %s ready · %s need a choice · %s skip', $js);
		$help = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString('nothing is saved yet', $help);
	}

	public function testSingleOrgChromeDropsWorkspaceJargonAndTechIds(): void
	{
		$help = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringNotContainsString('this workspace', $help);
		$this->assertStringNotContainsString('customer book', $help);
		$nav = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringNotContainsString('Your role:', $nav);
		$this->assertStringNotContainsString('crm-nav__role', $nav);
		$mailbox = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString('crm-mailbox-servers', $mailbox);
		$this->assertStringContainsString('Mail servers', $mailbox);
		$this->assertStringContainsString('IMAP encryption', $mailbox);
		$this->assertStringNotContainsString('Server settings (IMAP / SMTP)', $mailbox);
		$this->assertStringNotContainsString('for this book', $mailbox);
		$this->assertStringNotContainsString('colleagues in the workspace', $mailbox);
		$this->assertStringContainsString('shared team inbox', $mailbox);
		$reviewSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-review-sources.php');
		$this->assertStringContainsString('Scan as teammate', $reviewSrc);
		$this->assertStringNotContainsString('Scan as workspace member', $reviewSrc);
		$settingsJs = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$this->assertStringNotContainsString("' · #' + currentWorkspaceId", $settingsJs);
		$this->assertStringContainsString("tr('Add a colleague below so they can help with customers.')", $settingsJs);
		$this->assertStringContainsString("tr('Look only')", $settingsJs);
		$this->assertStringContainsString('focusMailboxServersIfIncomplete', $settingsJs);
		$this->assertStringContainsString('syncMailboxEncFromPorts', $settingsJs);
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringNotContainsString('Look-only role', $detail);
		$this->assertStringNotContainsString('You can edit. Work top to bottom', $detail);
		$triage = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/mail-triage.php');
		$this->assertStringNotContainsString('this workspace', $triage);
		$review = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/review.php');
		$this->assertStringNotContainsString('your workspace enabled', $review);
	}

	public function testCompany360CollapsesNoiseAndOneClickRenew(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString("\$l->t('Also on your phone')", $detail);
		$this->assertStringContainsString("\$l->t('Add to-do')", $detail);
		$this->assertStringContainsString("\$l->t('To-do')", $detail);
		$this->assertStringContainsString('id="crm-open-tasks"', $detail);
		$this->assertStringContainsString('<details class="crm-card crm-card--collapsible" id="crm-prepare-meeting">', $detail);
		$this->assertStringContainsString('<details class="crm-card crm-card--collapsible" id="crm-timeline">', $detail);
		$this->assertStringContainsString('id="crm-create-folder"', $detail);
		$this->assertStringContainsString("\$l->t('More document actions')", $detail);
		// Reminders: checklist rows (title + due meta + Done) — not spreadsheet tables.
		$actStart = (int)strpos($detail, 'id="crm-activities"');
		$actEnd = (int)strpos($detail, 'id="crm-promises"', $actStart);
		$actChunk = substr($detail, $actStart, max(0, $actEnd - $actStart));
		$this->assertStringContainsString('crm-todo-list', $actChunk);
		$this->assertStringContainsString("\$l->t('Due')", $actChunk);
		$this->assertStringContainsString("\$l->t('Done')", $actChunk);
		$this->assertStringNotContainsString('<table', $actChunk);
		$this->assertStringNotContainsString("\$l->t('Origin')", $actChunk);
		$this->assertStringNotContainsString('crm-more-menu', $actChunk);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("t('Renew for one year?')", $js);
		$this->assertStringContainsString("t('Renew for one year')", $js);
		$this->assertStringContainsString("/agreements/' + id + '/supersede'", $js);
		$this->assertStringNotContainsString("promptFields(t('Renew agreement')", $js);
		$this->assertStringContainsString('requireReason: true', $js);
		$this->assertStringNotContainsString("promptFields(t('Cancel agreement')", $js);
		$this->assertStringContainsString("promptFields(t('Add to-do')", $js);
		$this->assertStringContainsString("function paintHydrateFailure", $js);
		$this->assertStringContainsString("paintHydrateFailure('crm-billing-body'", $js);
	}
}
