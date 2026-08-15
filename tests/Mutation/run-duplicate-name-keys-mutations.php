<?php

declare(strict_types=1);

/**
 * Focused mutation gauntlet for companies-list duplicate-name keys (prod crash 2026-08-13).
 *
 * Usage (Docker):
 *   docker compose exec -u 1000:1000 nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-duplicate-name-keys-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline ==\n";
$baseline = run_filters($appRoot, $phpunit, [
	'CompanyServiceActiveDuplicateNameKeysTest',
	'CompaniesPageDuplicateKeysContractTest',
	'CompanyDuplicateLogicTest',
]);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline failed; aborting mutations\n");
	exit(1);
}

$mutations = [
	[
		'file' => $appRoot . '/lib/Service/CompanyService.php',
		'from' => "public function activeDuplicateNameKeys(int \$workspaceId): array\n\t{\n\t\tif (\$workspaceId <= 0) {",
		'to' => "public function activeDuplicateNameKeys(int \$workspaceId): array\n\t{\n\t\tif (\$workspaceId < 0) {",
		'filter' => ['CompanyServiceActiveDuplicateNameKeysTest'],
		'label' => 'accept workspaceId 0',
	],
	[
		'file' => $appRoot . '/lib/Service/CompanyService.php',
		'from' => "return \$this->companyMapper->activeDuplicateNameKeys(\$workspaceId);",
		'to' => 'return [];',
		'filter' => ['CompanyServiceActiveDuplicateNameKeysTest'],
		'label' => 'drop mapper delegation',
	],
	[
		'file' => $appRoot . '/lib/Support/CompanyDuplicateLogic.php',
		'from' => 'if ($count > 1) {',
		'to' => 'if ($count > 2) {',
		'filter' => ['CompanyDuplicateLogicTest'],
		'label' => 'require three name hits',
	],
	[
		'file' => $appRoot . '/lib/Controller/PageController.php',
		'from' => "\$duplicateNameKeys = \$this->companies->activeDuplicateNameKeys(\$wsId);",
		'to' => '$duplicateNameKeys = [];',
		'filter' => ['CompaniesPageDuplicateKeysContractTest'],
		'label' => 'page drops activeDuplicateNameKeys call',
	],
];

$failed = [];
foreach ($mutations as $m) {
	$source = $m['file'];
	$backup = $source . '.mutation-bak';
	echo "\n== mutation: {$m['label']} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $m['from'])) {
		$failed[] = $m['label'] . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($m['from'], $m['to'], $original);
	file_put_contents($source, $mutated);
	$code = run_filters($appRoot, $phpunit, $m['filter']);
	restore($source, $backup);
	if ($code === 0) {
		$failed[] = $m['label'];
		echo "MUTATION SURVIVED: {$m['label']}\n";
	} else {
		echo "killed {$m['label']}\n";
	}
}

if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll duplicate-name-keys mutations killed.\n";
exit(0);
