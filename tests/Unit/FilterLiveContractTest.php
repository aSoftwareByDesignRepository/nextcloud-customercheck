<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Companies list search must update in place — never GET-navigate on each keystroke.
 */
final class FilterLiveContractTest extends TestCase
{
	public function testCoreIsRegisteredBeforeFilterLive(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FrontEndAssetService.php');
		$core = strpos($src, 'common/filter-live-core');
		$live = strpos($src, "common/filter-live'");
		$this->assertNotFalse($core);
		$this->assertNotFalse($live);
		$this->assertLessThan($live, (int)$core, 'filter-live-core must load before filter-live');
	}

	public function testSearchNeverRequestSubmitsOnInput(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/filter-live.js');
		$this->assertMatchesRegularExpression('/(?<![\\w.])fetch\\(url\\.href/', $js);
		$this->assertStringContainsString('AbortController', $js);
		$this->assertStringContainsString("credentials: 'same-origin'", $js);
		$this->assertStringContainsString('history.replaceState', $js);
		$this->assertStringContainsString("addEventListener('input'", $js);
		$this->assertStringContainsString('compositionstart', $js);
		$this->assertStringContainsString('input[type="search"]', $js);
		$this->assertStringContainsString('lists.push(overlay)', $js);
		$this->assertStringContainsString('applyFilter(form, { immediate: false })', $js);
		$this->assertStringNotContainsString('requestSubmit();', $this->inputHandlerChunk($js));
		$this->assertStringContainsString('Search-as-you-type must never requestSubmit', $js);
	}

	public function testCompaniesListHasStableSearchAndSwapMain(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		$this->assertStringContainsString('id="crm-companies-q"', $html);
		$this->assertStringContainsString('data-crm-filter-swap="main"', $html);
		$this->assertStringContainsString('data-crm-filter-swap="nav"', $html);
		$this->assertStringContainsString('id="crm-companies-results"', $html);
		$this->assertStringContainsString('id="crm-companies-table"', $html);
		$this->assertStringContainsString('id="crm-companies-search-clear"', $html);
		$this->assertStringContainsString("\$l->t('Results update as you type.')", $html);
		$this->assertStringContainsString('data-crm-filter-empty', $html);
		$this->assertStringContainsString('data-crm-filter-done', $html);
		$qPos = strpos($html, 'id="crm-companies-q"');
		$mainPos = strpos($html, 'data-crm-filter-swap="main"');
		$this->assertNotFalse($qPos);
		$this->assertNotFalse($mainPos);
		$this->assertLessThan($mainPos, $qPos, 'Search input must stay outside the swapped results');
	}

	public function testDebounceConstantIsGrannySafe(): void
	{
		$core = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/filter-live-core.js');
		$this->assertMatchesRegularExpression('/SEARCH_DEBOUNCE_MS\s*=\s*450/', $core);
		$this->assertStringContainsString('MAX_FILTER_URL_LENGTH = 2048', $core);
		$this->assertStringContainsString('isSafeFilterUrl', $core);
		$this->assertStringContainsString('nextUrl.origin !== loc.origin', $core);
		$this->assertStringContainsString('nextUrl.pathname !== loc.pathname', $core);
		$this->assertStringContainsString('Could not update the list. Try again.', $core);
		$this->assertStringContainsString("t('No matches')", $core);
		$this->assertStringContainsString("t('List updated.')", $core);
	}

	public function testLiveFilterPagesKeepFormsOutsideSwapAndDelegateRowActions(): void
	{
		$root = dirname(__DIR__, 2);
		foreach (['activities.php', 'money.php', 'agreements.php'] as $file) {
			$html = (string)file_get_contents($root . '/templates/' . $file);
			$this->assertStringContainsString('data-crm-filter-swap="main"', $html, $file);
			$this->assertStringContainsString('data-crm-filter-swap="nav"', $html, $file);
			$this->assertStringContainsString('data-crm-filter-empty', $html, $file);
			$formPos = strpos($html, 'data-crm-live-filter');
			$mainPos = strpos($html, 'data-crm-filter-swap="main"');
			$this->assertNotFalse($formPos, $file);
			$this->assertNotFalse($mainPos, $file);
			$this->assertLessThan($mainPos, $formPos, $file);
		}

		$activities = (string)file_get_contents($root . '/js/pages/activities.js');
		$this->assertStringContainsString("closest('[data-crm-complete-activity]')", $activities);
		$this->assertStringContainsString("closest('[data-crm-undo-activity]')", $activities);
		$this->assertStringNotContainsString("querySelectorAll('[data-crm-complete-activity]')", $activities);

		$money = (string)file_get_contents($root . '/js/pages/money.js');
		$this->assertStringContainsString("closest('[data-crm-payment-reminder]')", $money);
		$this->assertStringNotContainsString("querySelectorAll('[data-crm-payment-reminder]')", $money);

		$agreements = (string)file_get_contents($root . '/js/pages/agreements.js');
		$this->assertStringContainsString("closest('[data-crm-partner-session], [data-crm-partner-mail]')", $agreements);
		$this->assertStringContainsString("closest('[data-crm-log-hours]')", $agreements);
		$this->assertStringContainsString("closest('[data-crm-delivery-pack]')", $agreements);
		$this->assertStringNotContainsString("querySelectorAll('[data-crm-partner-session]", $agreements);
	}

	public function testQuickFilterClicksAreInPlaceAndPipelineStaysApplyOnly(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/filter-live.js');
		$this->assertStringContainsString("querySelectorAll('form[data-crm-live-filter]')", $js);
		$this->assertStringNotContainsString("form.crm-filter-panel__form, form[data-crm-live-filter]", $js);
		$this->assertStringContainsString('a.crm-quick-filter, a#crm-companies-search-clear, a#btn-clear-filter', $js);
		$this->assertStringContainsString('isModifiedClick', $js);
		$this->assertStringContainsString('isLiveFilterNavAnchor', $js);
		$this->assertStringContainsString('syncFormsFromUrl', $js);
		$this->assertStringContainsString('applyUrl(url, { immediate: true })', $js);
		$this->assertStringContainsString("dataset.crmFilterLiveDoc", $js);

		$pipeline = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString('crm-filter-panel__form', $pipeline);
		$this->assertStringNotContainsString('data-crm-live-filter', $pipeline);
		$this->assertStringNotContainsString('data-crm-filter-swap="main"', $pipeline);
		$this->assertStringContainsString("then Apply.", $pipeline);
	}

	public function testPickerSearchesDropStaleResponses(): void
	{
		$pipeline = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString('let searchGeneration = 0;', $pipeline);
		$this->assertStringContainsString('if (myGeneration !== searchGeneration)', $pipeline);

		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertGreaterThanOrEqual(2, substr_count($persona, 'if (myGeneration !== searchGeneration)'));

		$settings = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$this->assertStringContainsString('if (myGeneration !== searchGeneration)', $settings);

		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('ownerSearchGeneration', $detail);
		$this->assertStringContainsString('if (myGeneration !== ownerSearchGeneration)', $detail);
	}

	public function testFragmentFetchAndForcedRefresh(): void
	{
		$root = dirname(__DIR__, 2);
		$core = (string)file_get_contents($root . '/js/common/filter-live-core.js');
		$this->assertStringContainsString("FRAGMENT_HEADER = 'X-CustomerCheck-Fragment'", $core);
		$this->assertStringContainsString('function fragmentRequestHeaders', $core);
		$this->assertStringContainsString("headers[FRAGMENT_HEADER] = '1'", $core);
		$this->assertStringContainsString("PREFER_MINIMAL = 'return=minimal'", $core);
		$this->assertStringContainsString('headers[PREFER_HEADER] = PREFER_MINIMAL', $core);

		$js = (string)file_get_contents($root . '/js/common/filter-live.js');
		$this->assertStringContainsString('Core.fragmentRequestHeaders()', $js);
		$this->assertStringContainsString('const force = !!(opts && opts.force)', $js);
		$this->assertStringContainsString('if (!force && (Core.urlsEqual(href, lastAppliedHref) || Core.urlsEqual(url.href, window.location.href)))', $js);
		$this->assertStringContainsString('applyUrl(url, { immediate: true, force: true })', $js);
		$this->assertStringContainsString('if (immediate && !force)', $js);
		$this->assertStringContainsString('function refresh()', $js);
		$this->assertStringContainsString('refresh: refresh', $js);

		$trait = (string)file_get_contents($root . '/lib/Controller/PageShellTrait.php');
		$this->assertStringContainsString('ListFragmentRequest::wants', $trait);
		$this->assertStringContainsString('TemplateResponse::RENDER_AS_BLANK', $trait);
		$this->assertStringContainsString('TemplateResponse::RENDER_AS_USER', $trait);
		$this->assertStringContainsString("\$params['crmListFragment'] = true", $trait);
		$this->assertStringContainsString("addHeader('Cache-Control', 'no-store, private')", $trait);
		$this->assertStringContainsString('ListFragmentRequest::varyValue()', $trait);
		$this->assertStringContainsString("addHeader('X-Content-Type-Options', 'nosniff')", $trait);
		$this->assertStringContainsString('registerListPageAssets', $trait);
		$this->assertStringContainsString('if ($this->wantsListFragment())', $trait);
		$this->assertStringContainsString('function buildFragmentShellParams', $trait);
		$this->assertStringContainsString('function mergeListPageParams', $trait);
		$this->assertStringNotContainsString('PageQuickStartCatalog', $this->fragmentShellChunk($trait));

		$controller = (string)file_get_contents($root . '/lib/Controller/PageController.php');
		foreach (['pages/companies', 'pages/agreements', 'pages/money', 'pages/activities'] as $script) {
			$this->assertStringContainsString("registerListPageAssets('" . $script . "')", $controller);
		}
		$this->assertGreaterThanOrEqual(4, substr_count($controller, 'listPageResponse('));
		$this->assertGreaterThanOrEqual(4, substr_count($controller, 'mergeListPageParams('));
	}

	public function testListTemplatesSkipChromeOnFragment(): void
	{
		$root = dirname(__DIR__, 2);
		foreach (['companies.php', 'activities.php', 'money.php', 'agreements.php'] as $file) {
			$html = (string)file_get_contents($root . '/templates/' . $file);
			$this->assertStringContainsString('$crmListFragment = !empty($_[\'crmListFragment\'])', $html, $file);
			$this->assertStringContainsString('if (!$crmListFragment)', $html, $file);
			$this->assertMatchesRegularExpression(
				'/if\s*\(\s*!\$crmListFragment\s*\)\s*\{\s*include\s+__DIR__\s*\.\s*\'\/common\/page-start\.php\'/',
				$html,
				$file . ' must skip page-start on fragment'
			);
			$this->assertStringContainsString("include __DIR__ . '/common/page-end.php'", $html, $file);
			$this->assertStringContainsString('data-crm-filter-swap="main"', $html, $file);
			$this->assertStringContainsString('data-crm-filter-swap="nav"', $html, $file);
		}
	}

	public function testActivitiesDoneRefreshesInPlace(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/activities.js');
		$this->assertStringContainsString('CustomerCheckFilterLive.refresh', $js);
		$this->assertStringContainsString('await window.CustomerCheckFilterLive.refresh()', $js);
		$start = strpos($js, 'async function markDone');
		$this->assertNotFalse($start);
		$chunk = substr($js, $start, 1600);
		$this->assertStringContainsString('CustomerCheckFilterLive.refresh', $chunk);
		$this->assertStringContainsString('function patchActivityRow', $js);
		$this->assertMatchesRegularExpression(
			'/if \(window\.CustomerCheckFilterLive && typeof window\.CustomerCheckFilterLive\.refresh === \'function\'\)/',
			$chunk
		);
		$this->assertStringContainsString('location.reload()', $chunk, 'reload remains last-resort fallback');
		$this->assertFalse(
			(bool)preg_match('/live\.textContent[\s\S]{0,80}location\.reload\(\)/', $chunk),
			'Done must not reload unconditionally after announce'
		);
	}

	public function testAgreementsHoursRefreshInPlace(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/agreements.js');
		$start = strpos($js, 'async function openLogHours');
		$this->assertNotFalse($start);
		$end = strpos($js, "document.addEventListener('click'");
		$this->assertNotFalse($end);
		$chunk = substr($js, $start, $end - $start);
		$this->assertStringContainsString("t('Hours logged')", $chunk);
		$this->assertStringContainsString('await window.CustomerCheckFilterLive.refresh()', $chunk);
		$this->assertStringContainsString("submitBtn.setAttribute('aria-busy', 'true')", $chunk);
	}

	public function testCompanyDetailDonePatchesTodoWithoutReload(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$start = strpos($js, "document.querySelectorAll('[data-crm-complete-activity]')");
		$this->assertNotFalse($start);
		$end = strpos($js, "document.querySelectorAll('[data-crm-push-todo]')");
		$this->assertNotFalse($end);
		$chunk = substr($js, $start, $end - $start);
		$this->assertStringContainsString("createElement('span')", $chunk);
		$this->assertStringContainsString('crm-todo-list__item--closed', $chunk);
		$this->assertStringContainsString("textContent = t('Done')", $chunk);
		$this->assertStringContainsString("loadCommandCenter({ silent: true })", $chunk);
		$this->assertStringNotContainsString('location.reload()', $chunk);
		$this->assertStringNotContainsString('innerHTML', $chunk);

		$hoursStart = strpos($js, 'async function openLogHours');
		$this->assertNotFalse($hoursStart);
		$hoursEnd = strpos($js, "document.querySelectorAll('[data-crm-log-hours]')");
		$this->assertNotFalse($hoursEnd);
		$hours = substr($js, $hoursStart, $hoursEnd - $hoursStart);
		$this->assertStringContainsString("t('Hours logged')", $hours);
		$this->assertStringContainsString("loadCommandCenter({ silent: true })", $hours);
		$this->assertStringNotContainsString('location.reload()', $hours);
	}

	private function fragmentShellChunk(string $trait): string
	{
		$start = strpos($trait, 'function buildFragmentShellParams');
		$this->assertNotFalse($start);
		$end = strpos($trait, 'function mergeListPageParams');
		$this->assertNotFalse($end);

		return substr($trait, $start, $end - $start);
	}

	private function inputHandlerChunk(string $js): string
	{
		$start = strpos($js, "input.addEventListener('input'");
		$this->assertNotFalse($start);
		return substr($js, $start, 420);
	}
}
