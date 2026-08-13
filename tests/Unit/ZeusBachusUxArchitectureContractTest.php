<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Zeus FULL AUDIT — Absolute No-Gos and Must-Fixes for Bachus UX simplify surface.
 */
final class ZeusBachusUxArchitectureContractTest extends TestCase
{
	public function testCompanyDetailClosesPageStackAndDoesNotDuplicateLiveRegion(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertSame(1, substr_count($html, 'class="crm-page-stack"'));
		$open = (int)strpos($html, 'class="crm-page-stack"');
		$tail = substr($html, $open);
		// After opening page-stack, page-end include must be preceded by an explicit close.
		$this->assertMatchesRegularExpression(
			'/\<\/div\>\s*<\?php\s*\n\s*include __DIR__ \. \'\/common\/page-end\.php\'/',
			$tail,
		);
		$this->assertStringNotContainsString('id="crm-live-region"', $html);
	}

	public function testTodayDoesNotDuplicateLiveRegionAndNeverLabelsNavigateAsDoneWhenReadOnly(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$this->assertStringNotContainsString('id="crm-live-region"', $html);
		$this->assertStringContainsString("(\$cta === 'done' && !\$allowDone) ? 'open' : \$cta", $html);
		$shell = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/page-start.php');
		$this->assertStringContainsString('id="crm-live-region"', $shell);
	}

	public function testMailboxFocusOpensServersDetailsBeforeFocusingRequiredHosts(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$fn = (int)strpos($js, 'function focusMailboxServersIfIncomplete');
		$this->assertGreaterThan(0, $fn);
		$chunk = substr($js, $fn, 700);
		$this->assertStringContainsString("getElementById('crm-mailbox-servers')", $chunk);
		$this->assertStringContainsString('servers.open = true', $chunk);
		$this->assertLessThan(
			(int)strpos($chunk, "getElementById('crm-mbox-imap-host')"),
			(int)strpos($chunk, 'servers.open = true'),
		);
	}

	public function testReviewBulkSelectionIgnoresHiddenRowsAndNeverFailOpensConfirm(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('function selectedReviewIds', $js);
		$sel = substr($js, (int)strpos($js, 'function selectedReviewIds'), 450);
		$this->assertStringContainsString('row.hidden', $sel);
		$this->assertStringContainsString("closest('li.crm-review-row')", $sel);

		$this->assertStringContainsString("Confirmation UI is unavailable. Reload the page and try again.", $js);
		$this->assertStringNotContainsString(': { confirmed: true };', $js);
		$this->assertStringContainsString("querySelector('select[data-crm-review-deal]')", $js);
	}

	public function testTalkListHasKeyboardRovingAndHonestEmptyCopy(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("data-crm-talk-kbd", $js);
		$this->assertStringContainsString("ev.key === 'ArrowDown'", $js);
		$this->assertStringContainsString("t('Select a message to read.')", $js);
		$this->assertStringNotContainsString("t('Select a message to read and decide.')", $js);
	}

	public function testIdentityLinksResolveViaDomPanelNotMapAlone(): void
	{
		$core = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-focus-core.js');
		$this->assertStringContainsString('function resolveTabForElement', $core);
		$this->assertStringContainsString("closest('[data-crm-focus-panel]')", $core);
		$this->assertStringContainsString("resolveTabForElement(DOM panel) is authoritative", $core);
	}

	public function testMoneyGlanceElevatesCoverageGapAbovePaidUpSuccess(): void
	{
		$utils = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/glance-summary.js');
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/glance-summary.js');
		foreach ([$utils, $boot] as $src) {
			$this->assertStringContainsString('function moneyGlanceTone', $src);
			$this->assertStringContainsString("bill === 'danger' ? 'danger' : 'warning'", $src);
			$this->assertStringContainsString('moneyGlanceTone(billingState, !!coverageRaw.gap)', $src);
			$this->assertStringContainsString('cov.meta ? (cov.label + \': \' + cov.meta) : cov.label', $src);
		}
	}

	public function testReviewMailPreviewUsesGenerationAndAssignSubmitLocks(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('let mailPreviewGeneration = 0', $js);
		$this->assertStringContainsString('function bumpMailPreviewRequest()', $js);
		$this->assertStringContainsString('mailPreviewAbort.abort()', $js);
		$this->assertStringContainsString('const previewReq = bumpMailPreviewRequest()', $js);
		$this->assertStringContainsString('previewGeneration: previewReq.gen', $js);
		$this->assertStringContainsString('signal: previewReq.signal', $js);
		$this->assertStringContainsString('function isStalePreview()', $js);
		$this->assertStringContainsString('expectedGen !== mailPreviewGeneration', $js);
		$this->assertStringContainsString('function isAbortError', $js);

		$submitAt = (int)strpos($js, "form.addEventListener('submit', async function (ev)");
		$this->assertGreaterThan(0, $submitAt);
		// Prefer the assign form submit (after wireReviewAssignForm), not brief confirm.
		$assignWire = (int)strpos($js, 'function wireReviewAssignForm');
		$this->assertGreaterThan(0, $assignWire);
		$chunkStart = (int)strpos($js, "form.addEventListener('submit', async function (ev)", $assignWire);
		$this->assertGreaterThan(0, $chunkStart);
		$chunk = substr($js, $chunkStart, 1400);
		$this->assertStringContainsString("form.getAttribute('aria-busy') === 'true'", $chunk);
		$this->assertStringContainsString("form.setAttribute('aria-busy', 'true')", $chunk);
		$this->assertStringContainsString("button[type=\"submit\"]", $chunk);
		$this->assertStringContainsString('b.disabled = true', $chunk);
		$this->assertStringContainsString("form.removeAttribute('aria-busy')", $chunk);
	}

	public function testTalkMailPreviewUsesGenerationAgainstArrowStampede(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('let talkMailPreviewGeneration = 0', $js);
		$this->assertStringContainsString('let talkMailPreviewAbort = null', $js);
		$this->assertStringContainsString('talkMailPreviewAbort.abort()', $js);
		$this->assertStringContainsString('const previewGen = ++talkMailPreviewGeneration', $js);
		$this->assertStringContainsString('function isStaleTalkPreview()', $js);
		$this->assertStringContainsString(
			'return previewGen !== talkMailPreviewGeneration || !previewWrap.isConnected;',
			$js,
		);
		$this->assertStringContainsString('signal: previewSignal', $js);
	}
}
