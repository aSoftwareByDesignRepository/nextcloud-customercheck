<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus extreme simplify — Deal unburied, people roster lean, Review chrome quiet.
 */
final class BachusExtremeSimplifyUxContractTest extends TestCase
{
	public function testDealFieldLivesWithAssignNotInsideSnooze(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$dealPos = strpos($js, 'crm-review-deal-field');
		$laterPos = strpos($js, "t('Remind me later')");
		$this->assertNotFalse($dealPos);
		$this->assertNotFalse($laterPos);
		// dealField is concatenated before laterBlock in the decide form.
		$decideChunkStart = strpos($js, 'companySection +');
		$this->assertNotFalse($decideChunkStart);
		$chunk = substr($js, $decideChunkStart, 2200);
		$this->assertStringContainsString('dealField +', $chunk);
		$this->assertStringContainsString('laterBlock +', $chunk);
		$dealInChunk = strpos($chunk, 'dealField +');
		$laterInChunk = strpos($chunk, 'laterBlock +');
		$this->assertLessThan($laterInChunk, $dealInChunk);
		$snoozeBody = substr($js, (int)strpos($js, "t('Remind me later')"), 900);
		$this->assertStringNotContainsString('Deal (optional)', $snoozeBody);
		$this->assertStringContainsString('Bring back in', $snoozeBody);
	}

	public function testReviewOpenIsScreenReaderOnlyAndStatusSortGone(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-sr-only crm-review-open', $js);
		$this->assertStringNotContainsString("crm-btn--ghost crm-btn--sm crm-review-open", $js);
		$this->assertStringContainsString('role="list"', $js);
		$this->assertStringContainsString('role="listitem"', $js);
		$this->assertStringNotContainsString("sortBtn('status', t('Status'))", $js);
		$this->assertStringContainsString("sortBtn('sent', t('Date'))", $js);
		$this->assertStringNotContainsString("sortBtn('sent', t('Sent'))", $js);
		$this->assertStringNotContainsString("aria-sort=\"", $js);
		$this->assertStringContainsString('Sorted ascending', $js);
		$this->assertStringContainsString('crm-mail-3pane__reader crm-review-detail-row', $js);
		$this->assertStringContainsString('row.after(detailTr)', $js);
		$this->assertStringContainsString("role', 'listitem'", $js);
		$this->assertStringNotContainsString('role="presentation"', $js);
		$this->assertStringContainsString('More search options', $js);
		$this->assertStringContainsString('crm-review-toolbar__extra', $js);
		$this->assertStringContainsString('Delete selected', $js);
		$this->assertStringContainsString('Mark as spam', $js);
		$this->assertStringNotContainsString('Delete / remove selected', $js);
	}

	public function testPeopleRosterIsThreeColumnsWithMoreDisclosure(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = strpos($html, 'id="crm-persons-table"');
		$end = strpos($html, 'id="crm-deals"', (int)$start);
		$chunk = substr($html, (int)$start, (int)$end - (int)$start);
		$this->assertStringContainsString('crm-person-more', $chunk);
		$this->assertStringContainsString('More about this person', $chunk);
		$this->assertStringContainsString('Last touch', $chunk);
		$this->assertStringContainsString('Role in buying', $chunk);
		$this->assertStringNotContainsString("\$l->t('Last touch')); ?></th>", $chunk);
		$this->assertStringNotContainsString("\$l->t('Address book')); ?></th>", $chunk);
		$this->assertStringNotContainsString("\$l->t('Role in buying')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Name')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Email')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Action')); ?></th>", $chunk);
		$this->assertStringNotContainsString('crm-more-menu', $chunk);
	}

	public function testMailboxSettingsLeadWithMembersThenMailbox(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$members = strpos($html, 'id="crm-ws-members-card"');
		$mailbox = strpos($html, 'id="crm-ws-mailbox-card"');
		$limits = strpos($html, 'id="crm-ws-limits-card"');
		$this->assertNotFalse($members);
		$this->assertNotFalse($mailbox);
		$this->assertNotFalse($limits);
		$this->assertLessThan($mailbox, $members);
		$this->assertLessThan($limits, $mailbox);
	}

	public function testTodayChecklistDoneHasNoSiblingOpen(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$pos = strpos($tpl, 'data-crm-complete-checklist="<?php p($checklistItemId); ?>"');
		$this->assertNotFalse($pos);
		$chunk = substr($tpl, $pos, 500);
		$this->assertStringContainsString('Done', $chunk);
		$this->assertStringNotContainsString("\$l->t('Open')", $chunk);
	}

	public function testCompanyIdentityShowsDetailsPeopleAndChangeActions(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = (int)strpos($html, 'id="crm-identity"');
		$end = (int)strpos($html, 'data-crm-360-focus="1"', $start);
		$chunk = substr($html, $start, max(0, $end - $start));
		$this->assertNotFalse($start);
		$glancePos = strpos($html, 'id="crm-glance"');
		$this->assertNotFalse($glancePos);
		$this->assertLessThan($start, (int)$glancePos, 'Identity must sit below At a glance');
		$this->assertStringContainsString('id="crm-identity-title"', $chunk);
		$this->assertStringContainsString('$company->getName()', $chunk);
		$this->assertStringContainsString('id="crm-edit-address"', $chunk);
		$this->assertStringContainsString('id="crm-change-company"', $chunk);
		$this->assertStringContainsString('id="crm-identity-people"', $chunk);
		$this->assertStringContainsString('id="crm-address-form"', $chunk);
		$this->assertStringContainsString('id="crm-person-form"', $chunk);
		$this->assertStringContainsString('id="crm-company-name-form"', $chunk);
		$this->assertStringContainsString("\$l->t('More about this person')", $chunk);
		$this->assertStringContainsString('href="#crm-person-', $chunk);
		$this->assertStringNotContainsString('href="#crm-persons"', $chunk);
		$this->assertStringContainsString('crm-card__header-actions crm-identity-actions', $chunk);
		$this->assertStringContainsString('crm-identity-people__head', $chunk);
		$this->assertStringContainsString('crm-identity-title-row', $chunk);
		$this->assertStringContainsString('crm-identity-person__more', $chunk);
		$this->assertStringContainsString('id="crm-identity-people-table"', $chunk);
		$this->assertStringContainsString('table-container crm-table--responsive', $chunk);
		$this->assertStringContainsString('th scope="col"', $chunk);
		$this->assertStringContainsString("\$l->t('Name')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Email')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Action')); ?></th>", $chunk);
		$this->assertStringContainsString('crm-table-actions', $chunk);
		$this->assertStringNotContainsString('crm-identity-person__well', $chunk);
		$this->assertStringNotContainsString('PersonInitialsPresenter', $chunk);
		$this->assertStringNotContainsString('crm-identity-people__list', $chunk);
		$this->assertStringNotContainsString('crm-card--table-solo', $chunk);
		$this->assertStringContainsString("IconCatalog::render('map'", $chunk);
		$this->assertStringContainsString("IconCatalog::render('users'", $chunk);
		$this->assertStringContainsString('CompanyNotesPresenter::publicHeadline', $chunk);
		$this->assertStringNotContainsString('p($company->getNotes())', $chunk);
		$this->assertStringContainsString("\$l->t('Log talk')", $html);
		$this->assertStringNotContainsString("\$l->t('Log email')", $html);
	}

	public function testLicensesTableIsFourColumnsWithProductInMore(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = (int)strpos($html, 'id="crm-contracts"');
		$end = (int)strpos($html, 'id="crm-billing"', $start);
		$chunk = substr($html, $start, max(0, $end - $start));
		$this->assertStringContainsString("\$l->t('Name')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Until')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Status')); ?></th>", $chunk);
		$this->assertStringContainsString("\$l->t('Action')); ?></th>", $chunk);
		$this->assertStringNotContainsString("\$l->t('Product')); ?></th>", $chunk);
		$this->assertStringContainsString('crm-more-menu__meta', $chunk);
	}

	public function testReviewOptionalDealIsProgressiveDisclosure(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-review-deal-more', $js);
		$this->assertStringContainsString("t('Optional deal')", $js);
		$this->assertStringContainsString('data-crm-mail-auto-open="1"', $js);
		$this->assertMatchesRegularExpression(
			'/data-crm-mail-preview-toggle[^>]*(hidden|aria-expanded="true")/',
			$js,
		);
	}

	public function testGlanceSummaryIsFourTilesWithoutCompanyDuplicate(): void
	{
		$utils = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/glance-summary.js');
		$this->assertStringContainsString("key: 'primary'", $utils);
		$this->assertStringContainsString("key: 'nextAction'", $utils);
		$this->assertStringContainsString("key: 'money'", $utils);
		$this->assertStringContainsString("key: 'renewal'", $utils);
		$this->assertStringNotContainsString("key: 'company'", $utils);
		$this->assertStringNotContainsString("key: 'coverage'", $utils);
		$this->assertStringNotContainsString("key: 'nextTouch'", $utils);
		$this->assertStringNotContainsString("key: 'lastDiscussion'", $utils);
		$this->assertStringContainsString("t('Last talk')", $utils);
	}
}
