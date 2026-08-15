<?php

declare(strict_types=1);

/**
 * Focused mutations for company duplicate import/list path.
 *
 * Usage (from nextcloud/): docker compose exec -u www-data nextcloud php \
 *   /var/www/html/custom_apps/customercheck/tests/Mutation/run-company-duplicate-path-mutations.php
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';
$config = $root . '/phpunit.xml';

/**
 * @param list<string> $filters
 */
function run_phpunit(string $phpunit, string $config, array $filters): int
{
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($config);
	if ($filters !== []) {
		// Single regex — multiple --filter flags keep only the last one in PHPUnit.
		$cmd .= ' --filter ' . escapeshellarg(implode('|', $filters));
	}
	passthru($cmd, $code);
	return (int)$code;
}

/**
 * @param array{file:string,find:string,replace:string,filters:list<string>,label:string} $m
 */
function apply_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['find'])) {
		fwrite(STDERR, "MISS find for {$m['label']}\n");
		exit(2);
	}
	$mut = str_replace($m['find'], $m['replace'], $src, $count);
	if ($count < 1) {
		fwrite(STDERR, "REPLACE failed for {$m['label']}\n");
		exit(2);
	}
	file_put_contents($m['file'], $mut);
}

/**
 * @param array{file:string,find:string,replace:string,filters:list<string>,label:string} $m
 */
function restore_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	file_put_contents($m['file'], str_replace($m['replace'], $m['find'], $src));
}

$mutations = [
	[
		'file' => $root . '/lib/Service/ImportMatcher.php',
		'find' => 'if ($company->isArchived()) {' . "\n\t\t\treturn;\n\t\t}",
		'replace' => 'if (false && $company->isArchived()) {' . "\n\t\t\treturn;\n\t\t}",
		'filters' => ['ImportMatcherTest', 'CompanyDuplicatePathContractTest'],
		'label' => 'import links archived companies',
	],
	[
		'file' => $root . '/lib/Service/ImportMatcher.php',
		'find' => '$suggestedSlug = ($slug !== \'\' && SlugService::isValid($slug) && !$slugBlockedForCreate)',
		'replace' => '$suggestedSlug = ($slug !== \'\' && SlugService::isValid($slug) && true)',
		'filters' => ['ImportMatcherTest'],
		'label' => 'import suggests blocked archived slug',
	],
	[
		'file' => $root . '/lib/Support/CompanyDuplicateLogic.php',
		'find' => 'if ($count > 1) {',
		'replace' => 'if ($count > 2) {',
		'filters' => ['CompanyDuplicateLogicTest'],
		'label' => 'duplicate detection requires three hits',
	],
	[
		'file' => $root . '/templates/companies.php',
		'find' => "t('Same name')",
		'replace' => "t('Same nameX')",
		'filters' => ['CompanyDuplicatePathContractTest'],
		'label' => 'companies list drops Same name badge copy',
	],
	[
		'file' => $root . '/js/pages/import.js',
		'find' => 'function candidateOptionLabel(c) {',
		'replace' => 'function candidateOptionLabelBroken(c) {',
		'filters' => ['CompanyDuplicatePathContractTest'],
		'label' => 'import candidate labels renamed away',
	],
];

if (run_phpunit($phpunit, $config, [
	'ImportMatcherTest',
	'CompanyDuplicateLogicTest',
	'CompanyDuplicatePathContractTest',
]) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}

$killed = 0;
$survived = [];
foreach ($mutations as $m) {
	apply_mutation($m);
	$code = run_phpunit($phpunit, $config, $m['filters']);
	restore_mutation($m);
	if ($code === 0) {
		$survived[] = $m['label'];
		fwrite(STDERR, "SURVIVED  {$m['label']}\n");
	} else {
		$killed++;
		echo "KILLED  {$m['label']}\n";
	}
}

$total = count($mutations);
$msi = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
echo "MSI (duplicate-path mutants): {$msi}% ({$killed}/{$total} killed)\n";
if ($survived !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $survived) . "\n");
	exit(1);
}
exit(0);
