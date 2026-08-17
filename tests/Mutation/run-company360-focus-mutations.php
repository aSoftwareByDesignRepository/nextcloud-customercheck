<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Company 360 focus tabs (Pipedrive-style progressive disclosure).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-company360-focus-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * Prefer Docker PHPUnit when the host cannot reach the NC DB (bootstrap.php loads OC).
 * File apply/revert stay on whatever user runs this script (prefer host developer UID).
 */
function company360_focus_phpunit_command(string $phpunit, string $config, string $filter): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	// .../apps/customercheck → .../nextcloud
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
				. ' --configuration ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter);
		}
	}
	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($config)
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
function company360_focus_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "  const ROOT_SELECTOR = '[data-crm-360-focus=\"1\"]';",
			'to' => "  import('./company360-focus-core.js');\n  const ROOT_SELECTOR = '[data-crm-360-focus=\"1\"]';",
			'label' => 'introduces_forbidden_dynamic_import',
			'filter' => 'Company360FocusContractTest::testFocusScriptIsClassicIifeWithoutDynamicImport',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => 'function revealAllPanels(root) {',
			'to' => 'function revealNonePanels(root) {',
			'label' => 'renames_fail_open_helper',
			'filter' => 'Company360FocusContractTest::testFocusScriptIsClassicIifeWithoutDynamicImport',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "'crm-billing': 'money',",
			'to' => "'crm-billing': 'today',",
			'label' => 'billing_maps_to_wrong_tab',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus-core.js',
			'from' => "'crm-billing': 'money',",
			'to' => "'crm-billing': 'today',",
			'label' => 'core_billing_maps_to_wrong_tab',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "if (id.indexOf('/') !== -1 || id.indexOf('%') !== -1 || id.indexOf(':') !== -1) {",
			'to' => "if (false && (id.indexOf('/') !== -1 || id.indexOf('%') !== -1 || id.indexOf(':') !== -1)) {",
			'label' => 'accepts_colon_hashes',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => 'if (companyId && /^\\d+$/.test(companyId)) {',
			'to' => 'if (companyId) {',
			'label' => 'session_storage_without_numeric_company_guard',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'data-crm-360-focus="1"',
			'to' => 'data-crm-360-focus="0"',
			'label' => 'drops_focus_root_marker',
			'filter' => 'Company360FocusContractTest::testFocusTabsReplaceJumpNavAndZones',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'data-crm-focus-panel="deals"',
			'to' => 'data-crm-focus-panel="dealz"',
			'label' => 'typos_deals_panel_id',
			'filter' => 'Company360FocusContractTest::testFocusTabsReplaceJumpNavAndZones',
		],
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => "'common/company360-focus'",
			'to' => "'common/jump-nav'",
			'label' => 'registers_old_jump_nav_instead_of_focus',
			'filter' => 'Company360FocusContractTest::testFocusScriptIsClassicIifeWithoutDynamicImport',
		],
		[
			'file' => $appRoot . '/templates/common/crm-360-focus-tabs.php',
			'from' => 'aria-orientation="horizontal"',
			'to' => 'aria-orientation="vertical"',
			'label' => 'wrong_tablist_orientation',
			'filter' => 'Company360FocusContractTest::testFocusTabsReplaceJumpNavAndZones',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "'crm-tickets': 'money',",
			'to' => "'crm-tickets': 'today',",
			'label' => 'tickets_map_drifts_in_bootstrap_only',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "Number.isFinite(rootScrollTop)",
			'to' => "isFinite(rootScrollTop)",
			'label' => 'reverts_to_coercing_isfinite',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "root.setAttribute('data-crm-focus-degraded', '1');",
			'to' => "/* mutated: no degraded latch */",
			'label' => 'drops_degraded_fail_open_latch',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
			'replace_all' => true,
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => 'return tabForSectionId(sectionId) !== null || isPersonRowDeepLinkSectionId(sectionId);',
			'to' => 'return true;',
			'label' => 'deep_link_allowlist_always_true',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "if (!/^[A-Za-z][A-Za-z0-9_-]*$/.test(id)) {\n      return null;\n    }",
			'to' => "/* mutated: no fragment charset gate */",
			'label' => 'drops_fragment_charset_gate',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => 'orderedPresentTabIds(tabs.keys(), ALLOWED_FOCUS_TABS)',
			'to' => 'Array.from(tabs.keys())',
			'label' => 'keyboard_uses_map_insertion_order',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => 'if (!hashBelongsToOtherTab(hash, tabId, section)) {',
			'to' => 'if (false /* mutated-never-clear-stale */) {',
			'label' => 'never_clears_stale_hash',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "#app-content.crm-app--company-detail .crm-360-focus__tab[aria-selected=\"true\"] {\n\tborder-color: var(--color-primary-element);\n\tborder-width: 3px;",
			'to' => "#app-content.crm-app--company-detail .crm-360-focus__tab[aria-selected=\"true\"] {\n\tborder-color: var(--color-primary-element);\n\tborder-width: 2px;",
			'label' => 'drops_selected_tab_non_color_cue',
			'filter' => 'Company360FocusContractTest::testFocusCssIsStickyAndAccessible',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => "'crm-emails': 'more',",
			'to' => "'crm-emails-missing': 'more',",
			'label' => 'map_points_at_missing_section_id',
			'filter' => 'Company360FocusContractTest::testSectionTabMapTargetsExistInCompanyDetailTree',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '@media (scripting: none)',
			'to' => '@media (scripting: enabled)',
			'label' => 'drops_noscript_panel_fail_open',
			'filter' => 'Company360FocusContractTest::testFocusCssIsStickyAndAccessible',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus.js',
			'from' => 'Promise.resolve().then(function () {',
			'to' => 'window.setTimeout(function () {',
			'label' => 'quiet_hash_uses_macrotask_again',
			'filter' => 'Company360FocusContractTest::testBootstrapHelpersStayInSyncWithCore',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string,replace_all?:bool} $m
 */
function apply_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "MUTATION SETUP FAIL {$m['label']}: from-string not found in {$m['file']}\n");
		exit(2);
	}
	if (!empty($m['replace_all'])) {
		$next = str_replace($m['from'], $m['to'], $src, $count);
		if ($count < 1) {
			fwrite(STDERR, "MUTATION SETUP FAIL {$m['label']}: replace count 0\n");
			exit(2);
		}
	} else {
		// Replace first occurrence only — global replace of short tokens corrupts the tree.
		$pos = strpos($src, $m['from']);
		$next = substr_replace($src, $m['to'], $pos, strlen($m['from']));
	}
	file_put_contents($m['file'], $next);
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string,replace_all?:bool} $m
 */
function revert_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!empty($m['replace_all'])) {
		if (!str_contains($src, $m['to'])) {
			fwrite(STDERR, "MUTATION REVERT FAIL {$m['label']}: to-string not found\n");
			exit(2);
		}
		$next = str_replace($m['to'], $m['from'], $src);
	} else {
		$pos = strpos($src, $m['to']);
		if ($pos === false) {
			fwrite(STDERR, "MUTATION REVERT FAIL {$m['label']}: to-string not found — restoring from may be incomplete\n");
			exit(2);
		}
		$next = substr_replace($src, $m['from'], $pos, strlen($m['to']));
	}
	file_put_contents($m['file'], $next);
}

$config = $appRoot . '/phpunit.xml';
$failed = 0;
$killed = 0;

// Baseline must be green — otherwise every mutation looks “killed”.
$baselineFilter = 'Company360FocusContractTest';
$baselineCmd = company360_focus_phpunit_command($phpunit, $config, $baselineFilter);
exec($baselineCmd . ' 2>&1', $baselineOut, $baselineCode);
if ($baselineCode !== 0) {
	fwrite(STDERR, "BASELINE FAIL — refusing to run mutations while Company360FocusContractTest is red\n");
	fwrite(STDERR, implode("\n", array_slice($baselineOut, -40)) . "\n");
	exit(2);
}
fwrite(STDOUT, "BASELINE OK {$baselineFilter}\n");

foreach (company360_focus_mutations($appRoot) as $m) {
	apply_mutation($m);
	$cmd = company360_focus_phpunit_command($phpunit, $config, $m['filter']);
	exec($cmd . ' 2>&1', $out, $code);
	revert_mutation($m);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED mutation {$m['label']} — tests did not catch it\n");
		fwrite(STDERR, implode("\n", array_slice($out, -20)) . "\n");
		$failed++;
	} else {
		fwrite(STDOUT, "KILLED {$m['label']}\n");
		$killed++;
	}
	$out = [];
}

fwrite(STDOUT, "Company360 focus mutations: killed={$killed} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
