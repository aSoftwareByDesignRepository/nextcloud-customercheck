<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Extreme-simplicity UX contracts: chips, progressive 360, demoted slug, live filters.
 */
final class ExtremeSimplicityUxContractTest extends TestCase
{
	public function testBriefChromeUsesPlainLanguageNotWipJargon(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString("t('This week')", $js);
		$this->assertStringContainsString("t('promises')", $js);
		$this->assertStringContainsString("t('Money customers still owe')", $js);
		$this->assertStringContainsString("t('Your week is full. Finish or drop a promise first.')", $js);
		$this->assertStringNotContainsString("t('Overdue AR')", $js);
		$this->assertStringNotContainsString("t('WIP')) + ' ' + (data.wipUsed", $js);
		$this->assertStringContainsString("t('New lead waiting too long')", $js);
		$this->assertStringNotContainsString("t('Lead SLA overdue')", $js);
	}

	public function testPipelineTableIsLeanAndEditDealNotChase(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString("\$l->t('Edit deal')", $html);
		$this->assertStringNotContainsString("\$l->t('Edit chase')", $html);
		$this->assertStringContainsString('Tap New deal to add one', $html);
		$this->assertStringContainsString('crm-empty-state', $html);
		$this->assertStringContainsString('id="crm-pipeline-empty-new"', $html);
		$this->assertStringContainsString("\$l->t('Deals view')", $html);
		$this->assertStringNotContainsString("\$l->t('Pipeline view')", $html);
		$this->assertStringNotContainsString('website lead webhook', $html);
		$this->assertStringContainsString("\$l->t('Action')", $html);
		$this->assertStringContainsString('crm-btn--primary', $html);
		$this->assertLessThan(
			10,
			substr_count($html, '<th scope="col">'),
			'Pipeline table must stay lean (granny column budget)'
		);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString("t('Edit deal')", $js);
		$this->assertStringContainsString("t('More options')", $js);
		$this->assertStringContainsString('crm-pipeline-empty-new', $js);
		$this->assertStringNotContainsString("t('Edit chase')", $js);
	}

	public function testCompanyTalkedCallTomorrowIsOneClick(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString("\$l->t('Call tomorrow')", $detail);
		$this->assertStringNotContainsString("\$l->t('Call back')", $detail);
		$this->assertStringContainsString('What you told the customer.', $detail);
		$this->assertStringNotContainsString('not internal weekly WIP', $detail);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("t('Call tomorrow')", $js);
		$this->assertStringContainsString("t('No moves yet — add one below.')", $js);
		$this->assertStringContainsString("t('What should happen next?')", $js);
		$this->assertStringContainsString('crm-map-step-title" name="title" rows="3"', $js);
		$this->assertStringContainsString("t('Add move')", $js);
		$this->assertStringNotContainsString("t('No MAP steps yet.')", $js);
		$this->assertStringNotContainsString('type="text" required maxlength="255"', $js);
		$this->assertStringContainsString("t('High')", $js);
		$this->assertStringNotContainsString("promptFields(t('Schedule callback')", $js);
	}

	public function testCompanyFormDemotesSlugToAdvanced(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringContainsString('crm-advanced', $html);
		$this->assertStringContainsString('crm-company-essentials', $html);
		$this->assertStringContainsString('crm-company-slug', $html);
		$this->assertLessThan(
			strpos($html, 'crm-company-slug'),
			strpos($html, 'crm-company-name'),
		);
		$this->assertStringContainsString('Just enter a name', $html);
		$this->assertStringContainsString('companyStatus', $html);
		$this->assertStringContainsString('crm-company-owner', $html);
		$this->assertStringContainsString('accountOwnerUserId', $html);
		// Status / owner / language / notes live under Advanced on create (name-first).
		$namePos = (int)strpos($html, 'crm-company-name');
		$advancedPos = (int)strpos($html, 'id="crm-company-advanced"');
		$statusPos = (int)strpos($html, 'crm-company-status');
		$this->assertGreaterThan($namePos, $advancedPos);
		$this->assertGreaterThan($advancedPos, $statusPos);
		$addressIncludePos = (int)strpos($html, 'crm-company-address-fields.php');
		$this->assertNotFalse($addressIncludePos);
		$this->assertGreaterThan($namePos, $addressIncludePos);
		$this->assertLessThan($advancedPos, $addressIncludePos, 'Postal address must sit with the name, not under Advanced');
		// Bachus: edit never force-opens Advanced (agreement parity).
		$this->assertStringNotContainsString("if (\$mode === 'edit'): ?> open", $html);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-company-advanced"[^>]*\sopen/',
			$html,
		);
		$this->assertStringContainsString('Owner, status, and links stay under Advanced', $html);
	}

	public function testCompanyDetailOneLogEmailAndSimplePeople(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$emails = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertSame(1, substr_count($detail, 'id="crm-log-email"') + substr_count($emails, 'id="crm-log-email"'));
		$this->assertStringContainsString('id="crm-log-email"', $detail);
		$this->assertStringNotContainsString('id="crm-log-email"', $emails);
		$this->assertStringContainsString('crm-card--collapsible', $detail);
		$this->assertStringContainsString('Where this customer should go', $detail);
		$this->assertStringNotContainsString('MAP steps', $detail);
		$this->assertStringContainsString('crm-persons-table', $detail);
		$this->assertStringNotContainsString("\$l->t('Phone')); ?></th>", $detail);
		$this->assertStringNotContainsString("\$l->t('Buying role')); ?></th>", $detail);
		// Lean roster: Name / Email / Action table; extras under closed More (design-system §3.7 / §12.5).
		$this->assertStringContainsString('crm-person-more', $detail);
		$this->assertStringNotContainsString('crm-person-more" open', $detail);
		$this->assertStringContainsString('<table class="table table--hover" id="crm-persons-table">', $detail);
		$this->assertStringNotContainsString('crm-table--responsive" id="crm-persons-table"', $detail);
		$this->assertStringNotContainsString('id="crm-persons-table" class="table table--hover crm-table--responsive"', $detail);
		$this->assertStringContainsString("\$l->t('Address book')", $detail);
		$this->assertStringContainsString("\$l->t('Last touch')", $detail);
		$this->assertStringContainsString("\$l->t('Role in buying')", $detail);
		$this->assertStringContainsString("\$l->t('Change person')", $detail);
		$this->assertStringContainsString('data-crm-edit-person', $detail);
		$this->assertStringNotContainsString("\$l->t('Address book')); ?></th>", $detail);
		$this->assertStringNotContainsString("\$l->t('Last touch')); ?></th>", $detail);
		$this->assertStringNotContainsString("\$l->t('Role in buying')); ?></th>", $detail);
		$this->assertStringContainsString("\$l->t('Log talk')", $detail);
		$this->assertStringContainsString('data-crm-trigger="crm-log-email"', $detail);
		$this->assertStringContainsString('$showWelcomeCoach', $detail);
		$this->assertStringContainsString('data-crm-360-focus="1"', $detail);
		$personsStart = strpos($detail, 'id="crm-persons-table"');
		$personsEnd = strpos($detail, 'id="crm-deals"', (int)$personsStart);
		$personsChunk = substr($detail, (int)$personsStart, (int)$personsEnd - (int)$personsStart);
		$this->assertStringNotContainsString('crm-more-menu', $personsChunk);
		$this->assertStringNotContainsString('showWelcomeCoach && $personsEmpty', $detail);
	}

	public function testCompanyIdentitySurfacesPeopleAddressAndEdit(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-change-company"', $detail);
		$this->assertStringContainsString('id="crm-edit-address"', $detail);
		$this->assertStringContainsString('id="crm-address-form"', $detail);
		$this->assertStringContainsString('id="crm-person-form"', $detail);
		$this->assertStringContainsString('id="crm-company-name-form"', $detail);
		$this->assertStringContainsString('id="crm-company-more-settings"', $detail);
		$this->assertStringNotContainsString('id="crm-identity-people"', $detail);
		$this->assertStringNotContainsString('id="crm-identity-people-table"', $detail);
		$this->assertStringNotContainsString('crm-identity-people__head', $detail);
		$this->assertStringNotContainsString('#crm-identity [data-crm-trigger="crm-add-person"]', $detail);
		$this->assertSame(1, substr_count($detail, 'id="crm-persons"'));
		$this->assertSame(1, substr_count($detail, 'id="crm-add-person"'));
		$this->assertSame(1, substr_count($detail, 'id="crm-person-form"'));
		$this->assertStringContainsString('id="crm-identity-title"', $detail);
		$this->assertStringContainsString("\$l->t('Change company')", $detail);
		$this->assertStringContainsString("\$l->t('Change address')", $detail);
		$this->assertStringContainsString("\$l->t('Add postal address')", $detail);
		$this->assertStringContainsString("\$l->t('No address yet.')", $detail);
		$this->assertStringContainsString("\$l->t('More company settings')", $detail);
		$this->assertStringContainsString('crm-company-address-fields.php', $detail);
		$this->assertStringNotContainsString('crm-card--compact', $detail);
		$this->assertStringNotContainsString("\$l->t('Company status')", $detail);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('crm-edit-address', $js);
		$this->assertStringContainsString('data-crm-edit-person', $js);
		$this->assertStringNotContainsString("promptFields(t('Add person')", $js);
		$this->assertStringNotContainsString("promptFields(t('Change person')", $js);
		$this->assertStringContainsString("t('Change person')", $js);
		$this->assertStringContainsString("t('Address saved.')", $js);
		$this->assertStringContainsString("t('Company saved.')", $js);
		$this->assertStringContainsString('revealPeopleFocusTab', $js);
		$this->assertStringContainsString('[data-crm-focus-tab="people"]', $js);
		$this->assertStringContainsString("} else if (which === 'person') {\n      revealPeopleFocusTab();", $js);
		$this->assertStringContainsString("getElementById('crm-360-panel-people')", $js);
		$this->assertStringContainsString("t('Reload page')", $js);
		$this->assertStringContainsString("t('Person updated.')", $js);
		$this->assertStringContainsString("t('Person added.')", $js);
		$this->assertStringContainsString('contactsSuggestGen', $js);
		$this->assertStringContainsString('contactsSuggestGen += 1;', $js);
		$this->assertStringContainsString('restoreIdentityOpenerFocus', $js);
		$this->assertStringContainsString("Api.put('/companies/' + companyId", $js);
		$this->assertStringContainsString('payload.contactsUid', $js);
		$this->assertStringContainsString('if (identitySaveBusy) return;', $js);
		$this->assertStringNotContainsString("q=aa", $js);
		$this->assertStringNotContainsString('#crm-identity [data-crm-trigger="crm-add-person"]', $js);
		$this->assertStringContainsString("advanced.open = !!(role || phone)", $js);
		$this->assertStringContainsString("const phone = row ? (row.getAttribute('data-person-phone') || '').trim() : '';", $js);
		$form = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringContainsString('Change the name or postal address', $form);
		$formJs = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-form.js');
		$this->assertStringContainsString('#crm-company-address', $formJs);
		$this->assertStringContainsString('addrStreet', $formJs);
		$fields = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-company-address-fields.php');
		$this->assertStringContainsString('crmAddrPrefix', $fields);
		$this->assertStringContainsString('addrStreet', $fields);
		$this->assertStringContainsString('id="<?php p($crmAddrPrefix); ?>-street"', $fields);
		$this->assertStringContainsString('crm-identity-strip__address', $detail);
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/crm-identity-form\[hidden\][^{]*\{[^}]*display:\s*none\s*!important/s',
			$css,
			'Identity inline forms must stay display:none when [hidden] — flex must not unhide them',
		);
		$this->assertStringContainsString('crm-identity-title-row', $css);
		$this->assertStringContainsString('#app-content.crm-app--company-detail #crm-persons [id]', $css);
		$this->assertStringContainsString('#crm-identity .crm-identity-body:not(:has(.crm-identity-form:not([hidden])))', $css);
		$this->assertStringNotContainsString('crm-identity-person__more', $css);
		$this->assertStringNotContainsString('crm-identity-people__head', $css);
		$this->assertStringNotContainsString('#app-content.crm-app .crm-identity-people .table-container', $css);
		$this->assertStringNotContainsString('crm-identity-person__well', $css);
		$this->assertStringNotContainsString('crm-identity-people__list', $css);
		$this->assertMatchesRegularExpression(
			'/crm-identity-strip__address\s*\{[^}]*max-width:\s*60ch/s',
			$css,
			'Identity address lead must stay on the design-system reading measure',
		);
		$this->assertMatchesRegularExpression(
			'/#crm-identity\.crm-card\s*>\s*\.crm-card__header\s*\{[^}]*flex-direction:\s*column/s',
			$css,
			'Identity header must stack so name and actions are not parked at opposite edges',
		);
		$this->assertDoesNotMatchRegularExpression(
			'/#crm-identity \.crm-card__header-text\s*\{[^}]*flex:\s*1/s',
			$css,
			'Identity header-text must not keep the 14rem row basis — in a column header that becomes empty height',
		);
		$this->assertDoesNotMatchRegularExpression(
			'/crm-identity-title-row \.crm-card__title\s*\{[^}]*flex:\s*1/s',
			$css,
			'Title must not grow — that shoves the status badge across the card',
		);
		$this->assertStringContainsString('crm-card__header-actions crm-identity-actions', $detail);
		$this->assertStringContainsString('crm-identity-title-row', $detail);
		$this->assertStringContainsString('id="crm-persons"', $detail);
		$this->assertStringContainsString('id="crm-persons-table"', $detail);
		$this->assertStringContainsString("\$l->t('More options')", $detail);
		$this->assertStringContainsString('id="crm-person-advanced"', $detail);
		$personsPos = strpos($detail, 'id="crm-persons"');
		$focusPos = strpos($detail, 'data-crm-360-focus="1"');
		$personFormPos = strpos($detail, 'id="crm-person-form"');
		$peoplePanelPos = strpos($detail, 'data-crm-focus-panel="people"');
		$dealsPanelPos = strpos($detail, 'id="crm-360-panel-deals"');
		$this->assertNotFalse($personsPos);
		$this->assertNotFalse($focusPos);
		$this->assertNotFalse($personFormPos);
		$this->assertNotFalse($peoplePanelPos);
		$this->assertNotFalse($dealsPanelPos);
		$this->assertGreaterThan($focusPos, $personsPos, 'People roster lives in the People focus tab, not above the tabs');
		$this->assertGreaterThan($peoplePanelPos, $personsPos);
		$this->assertLessThan($dealsPanelPos, $personsPos, 'People tab sits between Today and Deals');
		$this->assertGreaterThan($personsPos, $personFormPos, 'Person form lives in the People card, not in Company');
		$this->assertLessThan($dealsPanelPos, $personFormPos);
		$identityChunk = substr($detail, (int)strpos($detail, 'id="crm-identity"'), max(0, $focusPos - (int)strpos($detail, 'id="crm-identity"')));
		$this->assertStringNotContainsString("\$l->t('People')", $identityChunk);
		$this->assertStringNotContainsString('data-crm-trigger="crm-add-person"', $identityChunk);
		$this->assertStringContainsString('<table class="table table--hover" id="crm-persons-table">', $detail);
		$this->assertStringNotContainsString('crm-identity-person__well', $detail);
		$this->assertStringNotContainsString('PersonInitialsPresenter', $detail);
		$this->assertStringNotContainsString('crm-identity-people__list', $detail);
		$this->assertStringContainsString('CompanyNotesPresenter::publicHeadline', $detail);
		$this->assertStringNotContainsString('p($company->getNotes())', $detail);
		$this->assertStringContainsString('crm-card__lead crm-identity-strip__address crm-identity-strip__address--empty', $detail);
		$glancePos = strpos($detail, 'id="crm-glance"');
		$identityPos = strpos($detail, 'id="crm-identity"');
		$this->assertNotFalse($glancePos);
		$this->assertNotFalse($identityPos);
		$this->assertLessThan($identityPos, $glancePos, 'At a glance must render above the identity card');
	}

	public function testBriefVerifyDefaultsEvidenceAndPrimaryDone(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('value="activity_done" selected', $js);
		$this->assertStringContainsString('crm-btn--primary" data-verify="\' + id + \'" data-action="done"', $js);
		$this->assertStringContainsString('crm-brief-verify__more', $js);
		$this->assertStringContainsString('crm-brief-verify__note', $js);
		// Primary Done stands alone — evidence lives under More (Bachus: one obvious action).
		$verifyFn = strpos($js, 'function verifyActionsHtml');
		$this->assertNotFalse($verifyFn);
		$chunk = substr($js, (int)$verifyFn, 1200);
		$donePos = strpos($chunk, 'data-action="done"');
		$morePos = strpos($chunk, 'crm-brief-verify__more');
		$evidencePos = strpos($chunk, 'data-evidence-for');
		$this->assertNotFalse($donePos);
		$this->assertNotFalse($morePos);
		$this->assertNotFalse($evidencePos);
		$this->assertLessThan($morePos, $donePos, 'Done must appear before More');
		$this->assertGreaterThan($morePos, $evidencePos, 'Evidence select must sit inside More');
		$this->assertStringContainsString("evidence || 'activity_done'", $js);
		$this->assertStringNotContainsString('Choose evidence before marking done.', $js);
	}

	public function testCompanyDetailEmptyStatesHavePrimaryCtasAndWelcomeCoach(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-welcome-coach', $detail);
		$this->assertStringContainsString('Next steps for this customer', $detail);
		$this->assertStringContainsString('Three short steps to make this customer useful.', $detail);
		$this->assertStringContainsString('crm-welcome-coach__step', $detail);
		$this->assertStringContainsString('data-crm-trigger="crm-add-person"', $detail);
		$this->assertStringContainsString('data-crm-trigger="crm-add-deal"', $detail);
		$this->assertStringContainsString('data-crm-trigger="crm-log-email"', $detail);
		$this->assertStringContainsString('id="crm-add-activity"', $detail);
		$this->assertStringContainsString('data-crm-trigger="crm-link-folder"', $detail);
		$this->assertStringContainsString('A deal is a quote or opportunity', $detail);
		$this->assertStringContainsString('Loading overview…', $detail);
		$this->assertStringNotContainsString('Loading command view', $detail);
		$this->assertStringContainsString('Stop reminders', $detail);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("t('What is this deal?')", $js);
		$this->assertStringContainsString("t('More options')", $js);
		$this->assertStringContainsString('advanced: true', $js);
		$this->assertStringContainsString('formatDisplayDate', $js);
		$this->assertStringContainsString("t('Role in buying updated.')", $js);
		$this->assertStringContainsString("\$l->t('Role in buying')", $detail);
		$this->assertStringContainsString('Please fill in: %s', $js);
		$this->assertStringContainsString('el.checked', $js);
		$pipeline = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString("t('Almost there')", $pipeline);
		$this->assertStringContainsString("t('Deal saved.')", $pipeline);
		$this->assertStringNotContainsString("t('Deal chase fields saved.')", $pipeline);
		$this->assertStringNotContainsString("t('Products / SKUs')", $pipeline);
		$this->assertStringContainsString("t('Products (optional)')", $pipeline);
		$form = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringContainsString("\$l->t('Short address')", $form);
		$this->assertStringNotContainsString("\$l->t('Slug')", $form);
	}

	public function testCompanyGlanceShowsVisibleRetryAndBansGapSlugPipelineJargon(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("t('Needs links')", $js);
		$this->assertStringNotContainsString("t('Gap')", $js);
		$this->assertStringContainsString("[t('Primary contact'),", $js);
		$this->assertStringNotContainsString("[t('Company'), dto.name || '—', '']", $js);
		$this->assertStringNotContainsString("dto.slug || ''", $js);
		$this->assertStringContainsString("crm-callout--danger", $js);
		$this->assertStringContainsString("t('Try again')", $js);
		$this->assertStringContainsString('crm-glance-summary', $js);
		$this->assertStringContainsString('paintGlanceSummary', $js);
		$this->assertStringContainsString('function paintHydrateFailure', $js);
		$this->assertStringContainsString("paintHydrateFailure('crm-glance-summary'", $js);
		$this->assertStringContainsString("paintHydrateFailure('crm-communication-feed'", $js);
		$this->assertStringContainsString("paintHydrateFailure('crm-open-tasks-body'", $js);
		$this->assertStringContainsString("paintHydrateFailure('crm-billing-body'", $js);
		$this->assertMatchesRegularExpression(
			'/Some overview data could not be loaded\.[\s\S]{0,400}paintHydrateFailure\(\'crm-glance-summary\'/',
			$js,
			'Glance load errors must paint Try again via paintHydrateFailure on #crm-glance-summary',
		);
		$glance = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/glance-summary.js');
		$this->assertStringContainsString("t('Needs links')", $glance);
		$this->assertStringNotContainsString("t('Gap')", $glance);
		$this->assertStringContainsString('crm-stat-strip', $glance);
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString("\$l->t('Quotes and opportunities for this customer.')", $detail);
		$this->assertStringNotContainsString('Pipeline opportunities for this company.', $detail);
		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString("t('Open deals')", $persona);
		$this->assertStringNotContainsString("t('Pipeline')", $persona);
	}

	public function testPromiseDoneIsOneClickWithoutEvidenceDialog(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("data-crm-promise-done", $js);
		$this->assertStringContainsString("/promises/' + id + '/done'", $js);
		$this->assertStringContainsString("evidence: t('Done')", $js);
		$this->assertStringNotContainsString("Evidence (what was delivered?)", $js);
		$this->assertStringNotContainsString("promptFields(t('Mark promise done')", $js);
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-add-promise"', $detail);
	}

	public function testPersonaBootOffersTryAgainOnFailure(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('function showMountLoadError', $js);
		$this->assertMatchesRegularExpression(
			'/function showMountLoadError\(mount, err\) \{(?![\s\S]{0,60}return;)[\s\S]{0,800}t\(\'Try again\'\)/',
			$js,
		);
		$this->assertStringContainsString('showMountLoadError(mount, e)', $js);
		$this->assertStringContainsString("t('Collect overdue money')", $js);
		$this->assertStringNotContainsString("t('Chase overdue money')", $js);
		$this->assertStringNotContainsString("c.slug ? ' (' + c.slug + ')'", $js);
		$this->assertStringNotContainsString("meta.textContent = c.slug || ''", $js);
	}

	public function testAppWideGrannyEmptyCtasAndPlainTodayCopy(): void
	{
		$money = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/money.php');
		$this->assertStringContainsString('Open agreements', $money);
		$this->assertStringContainsString('Open companies', $money);
		$this->assertStringContainsString('Show all money', $money);
		$this->assertStringContainsString('Nothing in this group', $money);
		$this->assertStringContainsString('Try another filter, or show all money again.', $money);
		$activities = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/activities.php');
		$this->assertStringContainsString('Nothing open right now', $activities);
		$this->assertStringContainsString('Open companies', $activities);
		$this->assertStringNotContainsString('Inbox is clear', $activities);
		$this->assertStringNotContainsString("\$l->t('Origin')", $activities);
		$today = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$this->assertStringContainsString('Assign mail', $today);
		$this->assertStringContainsString('Help teammate', $today);
		$this->assertStringContainsString("I\\'ll take it", $today);
		$board = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CommandBoardService.php');
		$this->assertStringContainsString('Follow up on deals', $board);
		$this->assertStringContainsString('Customers to contact', $board);
		$this->assertStringContainsString('My promises this week', $board);
		$this->assertStringNotContainsString("'My Chase'", $board);
		$this->assertStringNotContainsString("'KAM touches'", $board);
		$qs = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageQuickStartCatalog.php');
		$this->assertStringContainsString('Open Today', $qs);
		$this->assertStringNotContainsString('Open Command Board', $qs);
		$this->assertStringContainsString('Follow-ups, renewals, invoices, promises', $qs);
		$this->assertStringNotContainsString('Chase, renewals', $qs);
		$import = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/import.php');
		$this->assertStringContainsString('Import now', $import);
		$this->assertStringContainsString('crm-import-tile', $import);
		$this->assertStringContainsString('crm-import-stats', $import);
		$this->assertStringNotContainsString('customerId,companyName', $import);
		$importJs = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/import.js');
		$this->assertStringContainsString('crm-import-stat', $importJs);
		$this->assertStringContainsString('actionLabel', $importJs);
		$this->assertStringContainsString('Import %s ready companies', $importJs);
		$this->assertStringNotContainsString('%s rows · %s ready', $importJs);
		$pipeline = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString('stageLabel', $pipeline);
		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('formatDisplayDate', $persona);
		$this->assertStringContainsString('Money this month', $persona);
		$this->assertStringContainsString('Promises left', $persona);
		$this->assertStringContainsString('Renewals in the next 90 days', $persona);
		$this->assertStringNotContainsString('Renewals ≤90d', $persona);
	}

	public function testCompaniesExposeOneClickQuickFilters(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		$this->assertStringContainsString('crm-quick-filters', $html);
		$this->assertStringContainsString("'status' => 'active'", $html);
		$this->assertStringContainsString("'archived' => '1'", $html);
		$this->assertStringContainsString('data-crm-live-filter', $html);
		$this->assertStringContainsString('crm-companies-search', $html);
		$this->assertStringContainsString('data-crm-filter-swap="main"', $html);
		$this->assertStringContainsString('id="crm-companies-q"', $html);
	}

	public function testCompanyDetailCollapsesEmptyLinkedApps(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-linked-bundle', $html);
		$this->assertStringContainsString('crm-linked-status', $html);
		$this->assertStringContainsString('One ProjectCheck or TicketCheck link unlocks', $html);
		$this->assertStringContainsString('Email & log hours', $html);
		$this->assertStringContainsString('data-crm-partner-session', $html);
		foreach (['id="crm-tickets"', 'id="crm-invoices"', 'id="crm-projects"', 'id="crm-timeline"'] as $needle) {
			$this->assertStringContainsString($needle, $html);
		}
		$this->assertStringContainsString("linkToRoute('invoicecheck.page.show'", $html);
		$this->assertStringContainsString('icInvoiceId', $html);
	}

	public function testMoneyAgreementsActivitiesHaveQuickFilters(): void
	{
		foreach (['money.php', 'agreements.php', 'activities.php'] as $file) {
			$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
			$this->assertStringContainsString('crm-quick-filters', $html, $file);
			$this->assertStringContainsString('data-crm-live-filter', $html, $file);
			$this->assertStringContainsString('data-crm-filter-swap="main"', $html, $file);
			$this->assertStringContainsString('data-crm-filter-swap="nav"', $html, $file);
			$formPos = strpos($html, 'data-crm-live-filter');
			$mainPos = strpos($html, 'data-crm-filter-swap="main"');
			$this->assertNotFalse($formPos, $file);
			$this->assertNotFalse($mainPos, $file);
			$this->assertLessThan($mainPos, $formPos, $file . ': filter form must stay outside swapped results');
		}
	}

	public function testTodaySupportsInlineDone(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$this->assertStringContainsString('data-crm-complete-activity', $html);
		$this->assertStringContainsString('table-container crm-table--responsive', $html);
		$this->assertStringContainsString('crm-today-row', $html);
		$this->assertStringContainsString('crm-today-focus-line', $html);
		$this->assertStringContainsString('%s need you', $html);
		$this->assertStringNotContainsString('class="crm-card crm-today-role"', $html);
		$this->assertStringNotContainsString('crm-today-role__stats', $html);
		$this->assertStringContainsString('Jump to queue', $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/today.js');
		$this->assertStringContainsString('/activities/', $js);
		$this->assertStringContainsString('crm-today-row--done', $js);
	}

	public function testTodayUsesSuiteTableColumns(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		foreach (['What', 'Company', 'Due', 'Action'] as $col) {
			$this->assertStringContainsString("\$l->t('$col')", $html, $col);
		}
		$this->assertStringContainsString('crm-table-actions', $html);
		$this->assertStringContainsString('crm-company-link.php', $html);
	}

	public function testShellExposesRoleWriteAndTeamNav(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageShellTrait.php');
		$this->assertStringContainsString("'canWrite'", $src);
		$this->assertStringContainsString("'showTeamNav'", $src);
		$this->assertStringContainsString('WorkspaceCapability::canOpenTeamBoard', $src);
		$nav = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString("showTeamNav", $nav);
	}

	public function testPersonaJsDoesNotUseRemovedOcUtilEscapeHtml(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringNotContainsString('OC.Util.escapeHTML', $js);
		$this->assertMatchesRegularExpression('/function\s+escapeHtml\s*\(/', $js);
		$this->assertGreaterThan(20, substr_count($js, 'escapeHtml('));
	}

	public function testPulseUsesSuiteShellAndTables(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pulse.php');
		$this->assertStringContainsString('page-start.php', $html);
		$this->assertStringContainsString('data-crm-persona-mount="pulse"', $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-today-stat', $js);
		$this->assertStringContainsString('table-container crm-table--responsive', $js);
		$this->assertStringContainsString('crm-pulse-metrics', $js);
		$this->assertStringContainsString('crm-pulse-glance', $js);
		$this->assertStringContainsString("t('At a glance')", $js);
		$this->assertStringContainsString("t('Cash')", $js);
		$this->assertStringContainsString("t('Work')", $js);
		$this->assertStringContainsString("t('Unbilled hours')", $js);
		$this->assertStringContainsString("t('Paid lately')", $js);
		$this->assertStringContainsString("t('Renewals soon')", $js);
		$this->assertStringContainsString('crm-today-stat--alert', $js);
		$this->assertStringNotContainsString("t('Health metrics')", $js);
		$this->assertStringNotContainsString("t('Paid 30d')", $js);
		$this->assertStringNotContainsString("t('Renewals ≤30d')", $js);
		$this->assertStringNotContainsString("t('Not available yet')", $js);
		$this->assertStringNotContainsString("t('Book health')", $js);
		$this->assertStringContainsString('renderSuiteTable', $js);
		// Ops glance chrome: never plaster tax/GoBD legal lectures on Pulse or Money.
		$this->assertStringNotContainsString('crm-pulse-money-disclaimer', $js);
		$this->assertStringNotContainsString('Ops overview — not tax/GoBD certification.', $js);
		$this->assertStringNotContainsString('not tax filing', $js);
		$this->assertStringNotContainsString('GoBD', $js);
		$moneyHtml = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/money.php');
		$this->assertStringNotContainsString('crm-money-ops-disclaimer', $moneyHtml);
		$this->assertStringNotContainsString('Ops overview — not tax/GoBD certification.', $moneyHtml);
		$this->assertStringNotContainsString('not tax filing', $moneyHtml);
		$this->assertStringNotContainsString('GoBD', $moneyHtml);
		$moneyPhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('Overdue, unpaid, paid and unknown invoice states.', $moneyPhp);
		$this->assertStringNotContainsString('day-to-day ops, not tax filing', $moneyPhp);
		$this->assertStringNotContainsString('moneyOpsDisclaimer', $moneyPhp);
		$pulsePhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PulseService.php');
		$this->assertStringNotContainsString("'disclaimer'", $pulsePhp);
		$this->assertStringNotContainsString('Day-to-day ops amounts — not for tax filing.', $pulsePhp);
		$this->assertStringNotContainsString('Ops overview — not tax/GoBD certification.', $pulsePhp);
		$this->assertStringNotContainsString('not for tax filing', $pulsePhp);
		$this->assertStringNotContainsString("registerFrontEndAssets('pages/persona', null, [], ['pages/persona'])", (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php'));
		$this->assertStringContainsString('unbilledHint', $js);
		$this->assertStringContainsString('displayValue', $js);
		$this->assertStringNotContainsString("t('Not available yet')", $js);
		$this->assertDoesNotMatchRegularExpression('/t\(\s*[\'"]honesty_blocked/', $js);
		$this->assertStringContainsString('unbilledHint', $pulsePhp);
		$this->assertStringContainsString('honestOpenBillableMinutes', $pulsePhp);
		$facadeProbe = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FacadeProbeService.php');
		$this->assertStringContainsString('FACADE_VERSION', $facadeProbe);
		$this->assertStringContainsString('if ($version < 2)', $facadeProbe);
		$this->assertStringContainsString('sumOpenBillableMinutes($id)', $facadeProbe);
		$this->assertStringNotContainsString('sumOpenBillableMinutes()', $facadeProbe);
		$this->assertStringContainsString('commitmentStatusLabel', $js);
		$this->assertStringContainsString('commitmentKindLabel', $js);
		$this->assertStringContainsString('crm-brief-add', $js);
		$this->assertStringContainsString("t('Add a commitment')", $js);
		$this->assertStringContainsString("t('What will you finish?')", $js);
		$this->assertStringContainsString("t('Add to this week')", $js);
		$this->assertStringContainsString('data-crm-brief-suggest', $js);
		$this->assertStringContainsString('crm-form--stack', $js);
		$this->assertStringNotContainsString("t('Confirm commitment')", $js);
		$this->assertStringNotContainsString("t('New commitment')", $js);
		$this->assertStringNotContainsString("t('Verify commitment')", $js);
		$this->assertStringContainsString("t('Check this promise')", $js);
		$this->assertStringContainsString('crm-form--stack', $js);
		$this->assertStringContainsString("t('What did you discuss?')", $js);
		$this->assertStringContainsString('data-crm-11-form', $js);
		$this->assertDoesNotMatchRegularExpression(
			"/form class=\"crm-form\"(?![\\s\\S]{0,40}crm-form--stack)/",
			$js,
			'Persona forms must use crm-form--stack, not bare crm-form stubs'
		);
		$this->assertStringContainsString('pulseAttentionKindLabel', $js);
		$this->assertStringContainsString('actionHtml', $js);
		$this->assertStringContainsString('data-crm-mail-triage-body', $js);
		$this->assertStringContainsString('data-crm-brief-last', $js);
		$this->assertStringContainsString('verifyActionsHtml', $js);
		$this->assertDoesNotMatchRegularExpression("/data-crm-brief-last[\\s\\S]{0,400}ul\\.className = 'crm-list'/", $js);
		$this->assertStringContainsString("data-crm-mail-triage-body", $js);
		$this->assertStringContainsString('kindLabel', $pulsePhp);
		$this->assertStringContainsString('pulseAttentionKind', $pulsePhp);
		$this->assertStringNotContainsString("' · ' . \$health", $pulsePhp);
		$preparePhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PrepareMeetingService.php');
		$this->assertStringContainsString('flagLabels', $preparePhp);
		$this->assertStringContainsString('buyingRoleLabel', $preparePhp);
		$this->assertStringContainsString('statusLabel', $preparePhp);
		$this->assertStringContainsString('missingLabels', $preparePhp);
		$coveragePhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CoverageService.php');
		$this->assertStringContainsString('missingLabels', $coveragePhp);
		$this->assertStringContainsString('coverageMissing', $coveragePhp);
		$companyDetailJs = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('coverageMissingLabel', $companyDetailJs);
		$this->assertStringContainsString('missingLabels', $companyDetailJs);
		$this->assertStringNotContainsString('cov.missing.join', $companyDetailJs);
	}

	public function testFilterLiveScriptIsRegistered(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FrontEndAssetService.php');
		$this->assertStringContainsString("addScript(Application::APP_ID, 'common/filter-live-core')", $src);
		$this->assertStringContainsString("common/filter-live'", $src);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/filter-live.js');
		$this->assertMatchesRegularExpression('/(?<![\\w.])fetch\\(url\\.href/', $js);
		$this->assertStringContainsString('AbortController', $js);
		$this->assertStringContainsString('data-crm-live-filter', $js);
		$this->assertStringContainsString('Search-as-you-type must never requestSubmit', $js);
	}

	public function testJumpNavRevealsCollapsedLinkedBundle(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/jump-nav.js');
		$this->assertStringContainsString('revealAncestorDetails', $js);
		$this->assertStringContainsString("tagName === 'DETAILS'", $js);
	}

	public function testTouchTargetsBumpOnMobile(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('crm-quick-filter', $css);
		$this->assertStringContainsString('@media (max-width: 767px)', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		// Suite UX6: desktop interactive .crm-btn--sm must also meet 44px (px units —
		// rem-based sizing under-delivers with Nextcloud's 15px root font).
		$this->assertMatchesRegularExpression(
			'/\.crm-btn--sm[^{]*\{[^}]*min-height:\s*44px/s',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-btn--sm[^{]*\{[^}]*min-height:\s*2\.25rem/s',
			$css,
		);
	}

	public function testChoiceControlsAlignWithLabels(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.crm-choice[^{]*\{[^}]*display:\s*flex\s*!important/s',
			$css,
			'crm-choice must flex-align control + label (!important against stale caches)'
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-choice[^{]*\{[^}]*align-items:\s*center\s*!important/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-choice[^{]*\{[^}]*flex-direction:\s*row\s*!important/s',
			$css,
			'crm-choice must stay horizontal (control beside label)'
		);
		$this->assertStringContainsString('.crm-choice input[type="radio"]', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-choice input\[type="radio"\][\s\S]{0,500}max-width:\s*1\.25rem\s*!important/s',
			$css,
			'Native radios must stay a fixed control size (label is the touch target)'
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-import-tile[^{]*\{[^}]*flex-direction:\s*row\s*!important/s',
			$css,
			'Import source tiles must put radio beside title (not stacked/absolute)'
		);
		$this->assertStringContainsString('position: static !important', $css);
		$import = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/import.php');
		$this->assertStringContainsString('class="crm-choice"', $import);
		$this->assertStringContainsString('crm-import-tile__text', $import);
		$this->assertStringContainsString('type="radio"', $import);
		$info = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$this->assertMatchesRegularExpression('/<version>1\.(5\.(1[6-9]|[2-9]\d)|[6-9]\.\d+)/', $info, 'Bump app version when shipping CSS cache-sensitive choice alignment');
	}

	public function testAgreementFormUsesTypeAndProductNotKindCode(): void
	{
		$form = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/agreement-form.php');
		$this->assertStringContainsString("\$l->t('Type')", $form);
		$this->assertStringContainsString("\$l->t('Product')", $form);
		$this->assertStringNotContainsString("\$l->t('Kind')", $form);
		$this->assertStringNotContainsString("\$l->t('Product code')", $form);
		$this->assertStringNotContainsString('(<?php p($code); ?>)', $form);
	}

	public function testSettingsMailboxAndTokensUsePlainLabels(): void
	{
		$mailbox = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString("\$l->t('Team & mailbox')", $mailbox);
		$this->assertStringNotContainsString('crm-ws-create-form', $mailbox);
		$this->assertStringNotContainsString("\$l->t('Slug')", $mailbox);
		$this->assertStringContainsString("\$l->t('Quoted deals without a follow-up show on Today after this many days.')", $mailbox);
		$tokens = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/tokens.php');
		$this->assertStringContainsString("\$l->t('First reply within (hours)')", $mailbox);
		$this->assertStringContainsString("\$l->t('Calendar feed for renewals')", $mailbox);
		$this->assertStringContainsString("\$l->t('Website lead link')", $tokens);
		$this->assertStringContainsString("\$l->t('Calendar feed for renewals')", $tokens);
		$this->assertStringContainsString("\$l->t('Website order link')", $tokens);
		$this->assertStringContainsString('customercheck_order_url', $tokens);
		$this->assertStringContainsString('customercheck_order_token', $tokens);
		$this->assertStringContainsString('crm-order-url-help', $tokens);
		$this->assertStringNotContainsString("\$l->t('Lead webhook')", $tokens);
		$settingsJs = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$this->assertStringContainsString("tr('Promises')", $settingsJs);
		$this->assertStringNotContainsString("tr('WIP')", $settingsJs);
		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString("t('Needs attention')", $persona);
		$this->assertStringContainsString("t('Up to date')", $persona);
		$this->assertStringContainsString("t('Open customer')", $persona);
		$this->assertStringNotContainsString("t('Open company 360')", $persona);
		$this->assertStringNotContainsString("t('Missing company id for prepare meeting.')", $persona);
		$this->assertStringNotContainsString('chase hygiene', $persona);
		$this->assertStringContainsString("t('Logged without a deal — not counted as sales work.')", $persona);
		$this->assertStringContainsString("t('Kept what was already saved.')", $persona);
		$settingsPage = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings.php');
		$mailboxPos = (int)strpos($settingsPage, 'workspace-mailbox.php');
		$prefsPos = (int)strpos($settingsPage, 'preferences.php');
		$reviewPos = (int)strpos($settingsPage, 'workspace-review-sources.php');
		$this->assertNotFalse(strpos($settingsPage, 'workspace-mailbox.php'));
		$this->assertTrue($mailboxPos < $prefsPos, 'People/mailbox before personal preferences');
		$this->assertTrue($prefsPos < $reviewPos, 'Preferences before expert Review sources');
	}

	public function testCompany360RetriesAndHumanPlanLabels(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("t('Could not load account plan.')", $js);
		$this->assertStringContainsString("t('Could not load promises.')", $js);
		$this->assertMatchesRegularExpression(
			'/Could not load account plan\.[\s\S]{0,500}t\(\'Try again\'\)/',
			$js,
		);
		$this->assertMatchesRegularExpression(
			'/Could not load promises\.[\s\S]{0,500}t\(\'Try again\'\)/',
			$js,
		);
		$this->assertStringContainsString('plan.healthLabel || plan.health', $js);
		$this->assertStringContainsString('s.statusLabel || s.status', $js);
		$this->assertStringContainsString('data-crm-trigger="crm-log-email"', $js);
		$this->assertStringContainsString('data-crm-trigger="crm-link-folder"', $js);
		$this->assertStringContainsString("closest('[data-crm-trigger]')", $js);
		$planPhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccountPlanService.php');
		$this->assertStringContainsString("'statusLabel' => \$this->labels->mapStepStatus", $planPhp);
		$this->assertStringContainsString("'healthLabel' => \$this->labels->planHealth", $planPhp);
		$this->assertStringContainsString("'priorityLabel' => \$this->labels->planPriority", $planPhp);
		$this->assertStringContainsString("'statusLabel' => \$this->labels->planStatus", $planPhp);
		$emails = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('Logged messages', $emails);
		$this->assertStringContainsString('data-crm-trigger="crm-log-email"', $emails);
		$this->assertStringNotContainsString('CRM email log', $emails);
	}
}
