<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: dedicated company postal address (UI + parser + import/webhook).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-company-address-mutations.php
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
function company_address_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Support/PostalAddressParser.php',
			'from' => "\$payload['addressStreet'] ?? null,\n\t\t\t// Website OrderProcessor::toCrmPayload (structured billing address).\n\t\t\t\$payload['streetAddress'] ?? null,\n\t\t\t\$company['streetAddress'] ?? null,",
			'to' => "\$payload['addressStreet'] ?? null,",
			'label' => 'drop_website_street_address_alias',
			'filter' => 'PostalAddressParserTest::testWebsiteStreetAddressWithPostalAndCityKeepsStreet',
		],
		[
			'file' => $appRoot . '/lib/Support/PostalAddressParser.php',
			'from' => "if (\$zip !== null && count(\$filtered) >= 2) {\n\t\t\t\t\$street = \$filtered[count(\$filtered) - 1];\n\t\t\t} else {\n\t\t\t\t\$street = implode(', ', \$filtered);\n\t\t\t}",
			'to' => "\$street = implode(', ', \$filtered);",
			'label' => 'drop_prefer_last_street_line',
			'filter' => 'PostalAddressParserTest::testParsesGermanWebsiteOrderBlockWithNameTypo',
		],
		[
			'file' => $appRoot . '/lib/Support/PostalAddressParser.php',
			'from' => "if (preg_match('/^(\\d{4,5})\\s+(.+)\$/u', \$tail, \$m) === 1) {\n\t\t\t\t\$zip = \$m[1];\n\t\t\t\t\$city = trim(\$m[2]);\n\t\t\t\tarray_pop(\$filtered);\n\t\t\t} elseif (preg_match('/^(.+?)\\s+(\\d{4,5})\$/u', \$tail, \$m) === 1\n\t\t\t\t&& preg_match('/\\d/', \$m[1]) !== 1) {\n\t\t\t\t// \"Kiel 24106\" rare variant\n\t\t\t\t\$city = trim(\$m[1]);\n\t\t\t\t\$zip = \$m[2];\n\t\t\t\tarray_pop(\$filtered);\n\t\t\t}",
			'to' => '',
			'label' => 'drop_plz_city_parse',
			'filter' => 'PostalAddressParserTest::testParsesGermanWebsiteOrderBlockWithNameTypo',
		],
		[
			'file' => $appRoot . '/templates/company-form.php',
			'from' => 'id="crm-company-street"',
			'to' => 'id="crm-company-street-missing"',
			'label' => 'rename_street_field_id',
			'filter' => 'CompanyAddressUxContractTest::testCompanyFormExposesDedicatedAddressFieldsUnderAdvanced',
		],
		[
			'file' => $appRoot . '/js/pages/company-form.js',
			'from' => 'addrStreet: (document.getElementById(\'crm-company-street\') || {}).value || \'\',',
			'to' => '',
			'label' => 'drop_js_addr_street_post',
			'filter' => 'CompanyAddressUxContractTest::testCompanyFormJsPostsAddressFields',
		],
		[
			'file' => $appRoot . '/lib/Service/ImportService.php',
			'from' => "foreach (['addrStreet', 'addrZip', 'addrCity'] as \$addrKey) {\n\t\t\t\t\t\t\$addrVal = trim((string)(\$row[\$addrKey] ?? ''));\n\t\t\t\t\t\tif (\$addrVal !== '') {\n\t\t\t\t\t\t\t\$createInput[\$addrKey] = \$addrVal;\n\t\t\t\t\t\t}\n\t\t\t\t\t}",
			'to' => '',
			'label' => 'drop_import_apply_address',
			'filter' => 'CompanyAddressUxContractTest::testImportApplyWritesAddressFields',
		],
		[
			'file' => $appRoot . '/lib/Service/WebsiteOrderWebhookService.php',
			'from' => "if (\$address['addrStreet'] !== null) {\n\t\t\t\$create['addrStreet'] = \$address['addrStreet'];\n\t\t}",
			'to' => '',
			'label' => 'drop_webhook_street_create',
			'filter' => 'CompanyAddressUxContractTest::testWebhookStoresParsedAddress',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'crm-identity-strip__address',
			'to' => 'crm-identity-nope',
			'label' => 'drop_360_address_class',
			'filter' => 'CompanyAddressUxContractTest::testCompanyDetailShowsAddressOrAddLink',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $m
 */
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

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = company_address_mutations($appRoot);
$caught = 0;
$failed = [];

echo 'Company address mutations: ' . count($mutations) . "\n";

foreach ($mutations as $m) {
	echo "\n=== MUTATION: {$m['label']} ===\n";
	$original = apply_mutation($m);
	try {
		$code = run_phpunit($phpunit, $appRoot, $m['filter']);
		if ($code === 0) {
			$failed[] = $m['label'] . ' (survived — tests still green)';
			echo "SURVIVED (bad)\n";
		} else {
			$caught++;
			echo "CAUGHT (good)\n";
		}
	} finally {
		restore_file($m['file'], $original);
	}
}

echo "\nResult: {$caught}/" . count($mutations) . " caught\n";
if ($failed !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $failed) . "\n");
	exit(1);
}
exit(0);
