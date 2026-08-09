<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Company 360 deal notes + working Edit deal (was a dead button without pipeline.js).
 */
final class DealNotesUxContractTest extends TestCase
{
	public function testCompanyDetailEditDealHandlerAndNotesFields(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("querySelectorAll('[data-crm-edit-chase]')", $js);
		$this->assertStringContainsString("t('Edit deal')", $js);
		$this->assertStringContainsString("t('Notes')", $js);
		$this->assertStringContainsString("t('Internal notes for your team — the customer does not see these.')", $js);
		$this->assertStringContainsString('maxlength: 4000', $js);
		$this->assertStringContainsString("Api.put('/deals/' + dealId", $js);
		$this->assertStringContainsString('notes: String(data.notes || \'\').trim()', $js);
		$this->assertStringContainsString("t('Deal saved.')", $js);
		// Add deal: title-only primary; notes live under More options (advanced).
		$this->assertMatchesRegularExpression(
			"/promptFields\(t\('Add deal'\), \[[\s\S]*?\{\s*name: 'notes',\s*label: t\('Notes'\),\s*type: 'textarea',\s*advanced:\s*true/",
			$js,
		);
		$this->assertDoesNotMatchRegularExpression(
			"/promptFields\(t\('Add deal'\), \[[\s\S]*?\{\s*name: 'notes',\s*label: t\('Notes'\),\s*type: 'textarea',\s*maxlength:\s*4000/",
			$js,
			'Add deal notes must not sit on the primary surface (maxlength without advanced)',
		);
	}

	public function testCompanyDetailDealRowsExposeNotesAndDemoteStopReminders(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('data-notes=', $html);
		$this->assertStringContainsString('crm-deal-notes-preview', $html);
		$this->assertStringContainsString('id="crm-deals-table"', $html);
		$this->assertStringContainsString("\$l->t('More deal actions')", $html);
		$dealsStart = strpos($html, 'id="crm-deals"');
		$dealsEnd = strpos($html, 'id="crm-promises"', (int)$dealsStart);
		$chunk = substr($html, (int)$dealsStart, (int)$dealsEnd - (int)$dealsStart);
		$this->assertStringContainsString('data-crm-edit-chase', $chunk);
		$this->assertStringContainsString('data-crm-dismiss-followups', $chunk);
		$this->assertStringContainsString('crm-more-menu', $chunk);
		// Stop reminders must not sit as a second always-visible primary next to Edit.
		$this->assertLessThan(
			strpos($chunk, 'data-crm-dismiss-followups'),
			strpos($chunk, 'crm-more-menu'),
		);
	}

	public function testPipelineEditDialogPersistsNotes(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString("t('Notes')", $js);
		$this->assertStringContainsString("name=\"notes\"", $js);
		$this->assertStringContainsString('notes: String(form.elements.notes.value || \'\').trim()', $js);
		// Bachus: Owner / quote date / PDF / win chance live under More — notes stay primary.
		$fn = strpos($js, 'async function openEditChase');
		$this->assertNotFalse($fn);
		$chunk = substr($js, (int)$fn, 3500);
		$notesPos = strpos($chunk, "t('Notes')");
		$morePos = strpos($chunk, "t('More options')");
		$ownerPos = strpos($chunk, "t('Owner')");
		$quotePos = strpos($chunk, "t('Quote sent on')");
		$this->assertNotFalse($notesPos);
		$this->assertNotFalse($morePos);
		$this->assertNotFalse($ownerPos);
		$this->assertNotFalse($quotePos);
		$this->assertLessThan($morePos, $notesPos, 'Notes must appear before More');
		$this->assertGreaterThan($morePos, $ownerPos, 'Owner must sit inside More');
		$this->assertGreaterThan($morePos, $quotePos, 'Quote date must sit inside More');
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertGreaterThanOrEqual(2, substr_count($html, 'data-notes='));
	}

	public function testMigrationAddsDealNotesColumn(): void
	{
		$mig = (string)file_get_contents(
			dirname(__DIR__, 2) . '/lib/Migration/Version1010Date20260809210000.php'
		);
		$this->assertStringContainsString("hasTable('crm_deal')", $mig);
		$this->assertStringContainsString("hasColumn('notes')", $mig);
		$this->assertStringContainsString("addColumn('notes', Types::TEXT", $mig);
	}

	public function testGlanceDensityCollapsesWhenCalm(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('$densityNeedsAttention', $html);
		$this->assertStringContainsString('crm-glance-density--calm', $html);
		$this->assertStringContainsString('All clear — expand if you need the numbers.', $html);
		$this->assertStringContainsString('crm-360-role', $html);
		$this->assertStringContainsString('crm-callout--info crm-360-role', $html);
		$this->assertStringContainsString('$showRoleCue', $html);
		$this->assertStringContainsString('!$showWelcomeCoach', $html);
	}

	public function testTextareaPromptHonoursMaxlength(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertMatchesRegularExpression(
			"/f\.type === 'textarea'[\s\S]{0,200}f\.maxlength !== undefined/",
			$js,
		);
	}
}
