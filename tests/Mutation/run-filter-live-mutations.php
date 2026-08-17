<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: companies list search must stay in-place (no per-keystroke reload).
 *
 * Host apply/revert (UID 1000). PHPUnit via Docker.
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function filter_live_phpunit_command(string $phpunit, string $appRoot, string $filter): string
{
	$composeDir = dirname($appRoot, 2);
	$dockerPhpunit = '/var/www/html/custom_apps/customercheck/vendor/bin/phpunit';
	$dockerConfig = '/var/www/html/custom_apps/customercheck/phpunit.xml';
	if (is_file($composeDir . '/docker-compose.yml')) {
		$probe = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";');
		exec($probe . ' 2>/dev/null', $probeOut, $probeCode);
		if ($probeCode === 0) {
			return 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
				. ' exec -T -u www-data nextcloud php ' . escapeshellarg($dockerPhpunit)
				. ' -c ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter)
				. ' --cache-result-file=/tmp/cc-phpunit-filter-live.cache';
		}
	}
	return escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
}

$lockPath = $appRoot . '/.mutation.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another mutation run holds {$lockPath} — aborting.\n");
	exit(1);
}
register_shutdown_function(static function () use ($lockFh, $lockPath): void {
	flock($lockFh, LOCK_UN);
	fclose($lockFh);
	@unlink($lockPath);
});

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function filter_live_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/common/filter-live-core.js',
			'from' => 'const SEARCH_DEBOUNCE_MS = 450;',
			'to' => 'const SEARCH_DEBOUNCE_MS = 0;',
			'label' => 'debounce_zero_reloads_per_key',
			'filter' => 'FilterLiveContractTest::testDebounceConstantIsGrannySafe',
		],
		[
			'file' => $appRoot . '/js/common/filter-live-core.js',
			'from' => 'if (nextUrl.origin !== loc.origin) {',
			'to' => 'if (nextUrl.origin === loc.origin && false) {',
			'label' => 'allows_cross_origin_filter_fetch',
			'filter' => 'FilterLiveContractTest::testDebounceConstantIsGrannySafe',
		],
		[
			'file' => $appRoot . '/templates/companies.php',
			'from' => 'data-crm-filter-swap="main"',
			'to' => 'data-crm-filter-swap="gone"',
			'label' => 'drops_main_swap_region',
			'filter' => 'FilterLiveContractTest::testCompaniesListHasStableSearchAndSwapMain',
		],
		[
			'file' => $appRoot . '/js/common/filter-live.js',
			'from' => 'fetch(url.href, init)',
			'to' => 'form.requestSubmit()',
			'label' => 'drops_in_place_fetch',
			'filter' => 'FilterLiveContractTest::testSearchNeverRequestSubmitsOnInput',
		],
		[
			'file' => $appRoot . '/js/common/filter-live.js',
			'from' => 'applyFilter(form, { immediate: false });',
			'to' => 'applyFilter(form, { immediate: true });',
			'label' => 'input_applies_immediately',
			'filter' => 'FilterLiveContractTest::testSearchNeverRequestSubmitsOnInput',
		],
		[
			'file' => $appRoot . '/js/common/filter-live.js',
			'from' => 'lists.push(overlay);',
			'to' => 'lists.push([]);',
			'label' => 'hidden_q_wipes_visible_search',
			'filter' => 'FilterLiveContractTest::testSearchNeverRequestSubmitsOnInput',
		],
		[
			'file' => $appRoot . '/lib/Service/FrontEndAssetService.php',
			'from' => "Util::addScript(Application::APP_ID, 'common/filter-live-core');\n\t\tUtil::addScript(Application::APP_ID, 'common/filter-live');",
			'to' => "Util::addScript(Application::APP_ID, 'common/filter-live');",
			'label' => 'drops_filter_live_core_script',
			'filter' => 'FilterLiveContractTest::testCoreIsRegisteredBeforeFilterLive',
		],
		[
			'file' => $appRoot . '/templates/activities.php',
			'from' => 'data-crm-filter-swap="main"',
			'to' => 'data-crm-filter-swap="gone"',
			'label' => 'drops_activities_main_swap',
			'filter' => 'FilterLiveContractTest::testLiveFilterPagesKeepFormsOutsideSwapAndDelegateRowActions',
		],
		[
			'file' => $appRoot . '/js/common/filter-live.js',
			'from' => "querySelectorAll('form[data-crm-live-filter]')",
			'to' => "querySelectorAll('form.crm-filter-panel__form, form[data-crm-live-filter]')",
			'label' => 'wires_pipeline_form_without_opt_in',
			'filter' => 'FilterLiveContractTest::testQuickFilterClicksAreInPlaceAndPipelineStaysApplyOnly',
		],
		[
			'file' => $appRoot . '/js/common/filter-live.js',
			'from' => 'a.crm-quick-filter, a#crm-companies-search-clear, a#btn-clear-filter',
			'to' => 'a#crm-companies-search-clear',
			'label' => 'drops_quick_filter_in_place_clicks',
			'filter' => 'FilterLiveContractTest::testQuickFilterClicksAreInPlaceAndPipelineStaysApplyOnly',
		],
		[
			'file' => $appRoot . '/js/pages/activities.js',
			'from' => "closest('[data-crm-complete-activity]')",
			'to' => "querySelector('[data-crm-complete-activity]')",
			'label' => 'drops_activity_click_delegation',
			'filter' => 'FilterLiveContractTest::testLiveFilterPagesKeepFormsOutsideSwapAndDelegateRowActions',
		],
		[
			'file' => $appRoot . '/js/common/filter-live-core.js',
			'from' => "return empty !== '' ? empty : t('No matches');",
			'to' => "return t('No companies match');",
			'label' => 'status_empty_hardcodes_companies',
			'filter' => 'FilterLiveContractTest::testDebounceConstantIsGrannySafe',
		],
	];
}

function apply_mutation(array $m): string
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('Mutation anchor missing for ' . $m['label'] . ' in ' . $m['file']);
	}
	$next = str_replace($m['from'], $m['to'], $src);
	if ($next === $src) {
		throw new RuntimeException('Mutation did not change file for ' . $m['label']);
	}
	file_put_contents($m['file'], $next);
	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

$killed = 0;
$failed = 0;
foreach (filter_live_mutations($appRoot) as $m) {
	fwrite(STDOUT, "=== MUTATION: {$m['label']} ===\n");
	$original = apply_mutation($m);
	$cmd = filter_live_phpunit_command($phpunit, $appRoot, $m['filter']);
	passthru($cmd, $code);
	restore_file($m['file'], $original);
	if ((int)$code === 0) {
		fwrite(STDOUT, "SURVIVED (bad)\n");
		$failed++;
	} else {
		fwrite(STDOUT, "CAUGHT (good)\n");
		$killed++;
	}
}

fwrite(STDOUT, "Result: {$killed}/" . ($killed + $failed) . " caught\n");
if ($failed > 0) {
	fwrite(STDOUT, "Survivors:\n");
	exit(1);
}
exit(0);
