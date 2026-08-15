<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: German UI copy quality (Advanced ≠ Fortschrittlich).
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
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function l10n_de_quality_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/l10n/de.json',
			'from' => '"Advanced": "Erweitert"',
			'to' => '"Advanced": "Fortschrittlich"',
			'label' => 'advanced_reverts_to_fortschrittlich',
		],
		[
			'file' => $appRoot . '/l10n/de.json',
			'from' => '"Save": "Speichern"',
			'to' => '"Save": "Sparen"',
			'label' => 'save_reverts_to_sparen',
		],
		[
			'file' => $appRoot . '/tests/fixtures/l10n-de-map.json',
			'from' => '"Advanced": "Erweitert"',
			'to' => '"Advanced": "Fortschrittlich"',
			'label' => 'de_map_advanced_mt_calque',
		],
		[
			'file' => $appRoot . '/scripts/check-l10n-de-quality.php',
			'from' => "'good' => 'Erweitert'",
			'to' => "'good' => 'Fortschrittlich'",
			'label' => 'quality_script_accepts_fortschrittlich_as_good',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter L10nGermanCopyQualityTest';
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
	$code = run_phpunit($phpunit, $appRoot);
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
