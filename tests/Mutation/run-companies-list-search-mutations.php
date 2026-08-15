<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: companies list search must stay multi-field + always visible.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-companies-list-search-mutations.php
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
function companies_list_search_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/templates/companies.php',
			'from' => 'class="crm-companies-search"',
			'to' => 'class="crm-companies-search-broken"',
			'label' => 'search_bar_class_removed',
			'filter' => 'CompaniesListSearchUxContractTest::testTemplateExposesAlwaysVisibleSearchPreservingWorkspace',
		],
		[
			'file' => $appRoot . '/lib/Db/CompanyMapper.php',
			'from' => '*PREFIX*crm_person` p',
			'to' => '*PREFIX*crm_person_x` p',
			'label' => 'person_exists_broken',
			'filter' => 'CompaniesListSearchUxContractTest::testMapperSearchCoversContactsOwnerStatusCallbacksAndEmail',
		],
		[
			'file' => $appRoot . '/lib/Support/CompanyListSearch.php',
			'from' => 'public const MAX_LEN = 100;',
			'to' => 'public const MAX_LEN = 1000;',
			'label' => 'search_length_cap_removed',
			'filter' => 'CompanyListSearchTest::testNormalizeTrimsAndCapsLength',
		],
		[
			'file' => $appRoot . '/lib/Support/CompanyListSearch.php',
			'from' => "if (\$q === '' || mb_strlen(\$q) < 4) {\n\t\t\treturn false;\n\t\t}",
			'to' => "if (\$q === '') {\n\t\t\treturn false;\n\t\t}",
			'label' => 'callback_keyword_too_short',
			'filter' => 'CompanyListSearchTest::testWantsOpenCallbacksRequiresClearKeyword',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = companies_list_search_mutations($appRoot);
$failed = 0;

fwrite(STDOUT, "Baseline companies list search tests…\n");
if (run_phpunit($phpunit, $appRoot, 'CompaniesListSearchUxContractTest|CompanyListSearchTest') !== 0) {
	fwrite(STDERR, "Baseline failed — aborting mutations.\n");
	exit(1);
}

foreach ($mutations as $m) {
	$orig = (string)file_get_contents($m['file']);
	if (!str_contains($orig, $m['from'])) {
		fwrite(STDERR, "SKIP/FAIL {$m['label']}: from-string not found in {$m['file']}\n");
		$failed++;
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $orig));
	fwrite(STDOUT, "Mutant {$m['label']}…\n");
	$code = run_phpunit($phpunit, $appRoot, $m['filter']);
	file_put_contents($m['file'], $orig);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$m['label']} — contract did not catch mutant.\n");
		$failed++;
	} else {
		fwrite(STDOUT, "Killed {$m['label']}.\n");
	}
}

if ($failed > 0) {
	fwrite(STDERR, "{$failed} mutation(s) failed.\n");
	exit(1);
}

fwrite(STDOUT, "All companies-list-search mutants killed.\n");
exit(0);
