<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: German + mother-tongue l10n quality.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-l10n-de-quality-mutations.php
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

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function l10n_de_quality_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/l10n/de.json',
			'from' => '"Advanced": "Erweitert"',
			'to' => '"Advanced": "Fortschrittlich"',
			'label' => 'advanced_reverts_to_fortschrittlich',
			'filter' => 'L10nGermanCopyQualityTest',
		],
		[
			'file' => $appRoot . '/l10n/de.json',
			'from' => '"Save": "Speichern"',
			'to' => '"Save": "Sparen"',
			'label' => 'save_reverts_to_sparen',
			'filter' => 'L10nGermanCopyQualityTest',
		],
		[
			'file' => $appRoot . '/l10n/de.json',
			'from' => '"Churned": "Abgewandert"',
			'to' => '"Churned": "Aufgewühlt"',
			'label' => 'churned_reverts_to_aufgewuehlt',
			'filter' => 'L10nGermanCopyQualityTest',
		],
		[
			'file' => $appRoot . '/l10n/de.json',
			'from' => '"Share with workspace": "Mit dem Arbeitsbereich teilen"',
			'to' => '"Share with workspace": "Mit dem Workspace teilen"',
			'label' => 'workspace_english_leaks_into_de',
			'filter' => 'L10nGermanCopyQualityTest',
		],
		[
			'file' => $appRoot . '/tests/fixtures/l10n-de-map.json',
			'from' => '"Advanced": "Erweitert"',
			'to' => '"Advanced": "Fortschrittlich"',
			'label' => 'de_map_advanced_mt_calque',
			'filter' => 'L10nGermanCopyQualityTest',
		],
		[
			'file' => $appRoot . '/scripts/check-l10n-de-quality.php',
			'from' => "'good' => 'Erweitert'",
			'to' => "'good' => 'Fortschrittlich'",
			'label' => 'quality_script_accepts_fortschrittlich_as_good',
			'filter' => 'L10nGermanCopyQualityTest',
		],
		[
			'file' => $appRoot . '/l10n/fr.json',
			'from' => '"Calendar events (opt-in)": "Événements calendrier (facultatif)"',
			'to' => '"Calendar events (opt-in)": "Événements calendrier (opt-in)"',
			'label' => 'fr_opt_in_jargon_leaks',
			'filter' => 'L10nNativeQualityContractTest',
		],
		[
			'file' => $appRoot . '/l10n/nl.json',
			'from' => '"Your preferences": "Jouw voorkeuren"',
			'to' => '"Your preferences": "Uw voorkeuren"',
			'label' => 'nl_formal_u_leaks',
			'filter' => 'L10nNativeQualityContractTest',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);

	return (int) $code;
}

$mutations = l10n_de_quality_mutations($appRoot);
$failed = 0;
$killed = 0;
$skipped = 0;

foreach ($mutations as $m) {
	$src = (string) file_get_contents($m['file']);
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
	file_put_contents($m['file'], $mutated);
	$code = run_phpunit($phpunit, $appRoot, $m['filter']);
	file_put_contents($m['file'], $src);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$m['label']}\n");
		$failed++;
	} else {
		fwrite(STDOUT, "KILLED  {$m['label']}\n");
		$killed++;
	}
}

$total = $killed + $failed;
$msi = $total > 0 ? round(100.0 * $killed / $total, 1) : 0.0;
fwrite(STDOUT, "MSI (l10n-de-quality): {$msi}% killed={$killed} survived={$failed} skipped={$skipped}\n");
exit($failed > 0 || $skipped > 0 ? 1 : 0);
