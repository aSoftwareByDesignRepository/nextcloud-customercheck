<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: settings hygiene (no legacy redirects, promises slug, jobs keys).
 *
 * Usage: php tests/Mutation/run-settings-hygiene-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
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

/** @return list<array{file:string,from:string,to:string,label:string,filter:string}> */
function settings_hygiene_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/templates/settings/integrations.php',
			'from' => "if (!is_bool(\$raw)) {\n\t\tcontinue;\n\t}",
			'to' => "if (false && !is_bool(\$raw)) {\n\t\tcontinue;\n\t}",
			'label' => 'integrations_includes_detail_arrays',
			'filter' => 'SettingsHygieneContractTest::testIntegrationsListsOnlyBooleanCapabilitiesWithHumanLabels',
		],
		[
			'file' => $appRoot . '/templates/settings/jobs.php',
			'from' => '<input type="checkbox" name="identity_rename_push" value="1"',
			'to' => '<input type="hidden" name="identity_rename_push" value="0"',
			'label' => 'jobs_drops_identity_rename_toggle',
			'filter' => 'SettingsHygieneContractTest::testJobsFormExposesHeadlessConfigKeys',
		],
		[
			'file' => $appRoot . '/appinfo/routes.php',
			'from' => "'requirements' => ['section' => 'my-email|mailbox|preferences|checklists|review-sources|promises|integrations']],",
			'to' => "'requirements' => ['section' => 'my-email|mailbox|preferences|checklists|review-sources|circles|integrations']],",
			'label' => 'route_reverts_circles_slug',
			'filter' => 'SettingsPagesContractTest::testWorkspaceRouteAllowlistMatchesCatalog',
		],
		[
			'file' => $appRoot . '/js/pages/settings.js',
			'from' => "const res = await Api.get('/promises-roll-call');",
			'to' => "const res = await Api.get('/circles-roll-call');",
			'label' => 'settings_js_uses_old_circles_api',
			'filter' => 'SettingsHygieneContractTest::testPromisesSectionUsesClearSlugAndTemplate',
		],
		[
			'file' => $appRoot . '/lib/Service/FacadeProbeService.php',
			'from' => "'maintenancecheck' => \$mn['usable'],",
			'to' => "// mutated: drop maintenancecheck from matrix",
			'label' => 'capability_matrix_drops_maintenancecheck',
			'filter' => 'FacadeProbeServiceCapabilityTest',
		],
	];
}

$mutations = settings_hygiene_mutations($appRoot);
$killed = 0;
$survived = 0;
$skipped = 0;

foreach ($mutations as $m) {
	$file = $m['file'];
	$src = (string)file_get_contents($file);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "SKIP missing needle: {$m['label']}\n");
		$skipped++;
		continue;
	}
	$mutated = str_replace($m['from'], $m['to'], $src, $count);
	if ($count < 1) {
		fwrite(STDERR, "SKIP replace failed: {$m['label']}\n");
		$skipped++;
		continue;
	}
	// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
	// a kill-test that is red unmutated turns nonzero exits into fake kills.
	require_once __DIR__ . '/MutationBaseline.php';
	mutationBaselineSelfCheck(__FILE__);
	file_put_contents($file, $mutated);
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($m['filter'])
		. ' 2>&1';
	exec($cmd, $out, $code);
	file_put_contents($file, $src);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED  {$m['label']}\n");
		$survived++;
	} else {
		fwrite(STDOUT, "KILLED  {$m['label']}\n");
		$killed++;
	}
}

$total = $killed + $survived;
$msi = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
fwrite(STDOUT, "\nMSI (settings hygiene): {$msi}% ({$killed}/{$total} killed, {$skipped} skipped)\n");
exit($survived > 0 || $skipped > 0 ? 1 : 0);
