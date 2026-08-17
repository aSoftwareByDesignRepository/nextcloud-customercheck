<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: dedicated company postal address (UI + parser + import/webhook).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-company-address-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * Prefer Docker PHPUnit when the host cannot reach the NC DB (bootstrap.php loads OC).
 * File apply/revert stay on the developer UID.
 */
function company_address_phpunit_command(string $phpunit, string $appRoot, string $filter): string
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
				. ' --cache-result-file=/tmp/cc-phpunit-mut.cache';
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
			'file' => $appRoot . '/templates/common/crm-company-address-fields.php',
			'from' => 'id="<?php p($crmAddrPrefix); ?>-street"',
			'to' => 'id="<?php p($crmAddrPrefix); ?>-street-missing"',
			'label' => 'rename_street_field_id',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/js/pages/company-form.js',
			'from' => 'addrStreet: (document.getElementById(\'crm-company-street\') || {}).value || \'\',',
			'to' => '',
			'label' => 'drop_js_addr_street_post',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
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
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'id="crm-person-form"',
			'to' => 'id="crm-person-form-missing"',
			'label' => 'rename_person_form_id',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'id="crm-company-name-form"',
			'to' => 'id="crm-company-name-form-missing"',
			'label' => 'rename_company_name_form_id',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'if (identitySaveBusy) return;',
			'to' => 'if (false && identitySaveBusy) return;',
			'label' => 'drop_identity_save_busy_guard',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "if (liveRegion()) liveRegion().textContent = t('Person added.');",
			'to' => "if (liveRegion()) liveRegion().textContent = t('Person updated.');",
			'label' => 'add_person_announces_updated',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'contactsSuggestGen += 1;',
			'to' => '/* mutated: no contacts generation bump */',
			'label' => 'drop_contacts_suggest_generation',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "#app-content.crm-app .crm-identity-form[hidden] {\n\tdisplay: none !important;\n}",
			'to' => "/* mutated: identity forms stay flex when hidden */",
			'label' => 'unhide_identity_forms',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'href="#crm-person-<?php p((int)$ip[\'id\']); ?>"',
			'to' => 'href="#crm-persons"',
			'label' => 'identity_more_person_loses_row_anchor',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testCompanyIdentityShowsDetailsPeopleAndChangeActions',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'CompanyNotesPresenter::publicHeadline($company->getNotes())',
			'to' => '(string)$company->getNotes()',
			'label' => 'identity_dumps_raw_company_notes',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testCompanyIdentityShowsDetailsPeopleAndChangeActions',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'crm-card__lead crm-identity-strip__address crm-identity-strip__address--empty',
			'to' => 'crm-sr-only crm-identity-strip__address crm-identity-strip__address--empty',
			'label' => 'empty_address_hidden_from_sighted_users',
			'filter' => 'CompanyAddressUxContractTest::testCompanyDetailShowsAddressOrAddLink',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "#app-content.crm-app #crm-identity.crm-card > .crm-card__header {\n\tflex-direction: column;\n\talign-items: flex-start;\n\tjustify-content: flex-start;\n\tgap: var(--crm-space-3, 0.75rem);\n}",
			'to' => "#app-content.crm-app #crm-identity.crm-card > .crm-card__header {\n\tflex-direction: row;\n\talign-items: flex-start;\n\tjustify-content: space-between;\n\tgap: var(--crm-space-3, 0.75rem);\n}",
			'label' => 'identity_header_space_between_ocean',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "#app-content.crm-app .crm-identity-title-row .crm-card__title {\n\tflex: 0 1 auto;\n\tmin-width: 0;\n\toverflow-wrap: anywhere;\n}",
			'to' => "#app-content.crm-app .crm-identity-title-row .crm-card__title {\n\tflex: 1 1 8rem;\n\tmin-width: 0;\n\toverflow-wrap: anywhere;\n}",
			'label' => 'identity_title_grows_and_shoves_badge',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "#app-content.crm-app #crm-identity .crm-card__header-text {\n\tflex: 0 1 auto;\n\twidth: 100%;\n\tmin-width: 0;\n}",
			'to' => "#app-content.crm-app #crm-identity .crm-card__header-text {\n\tflex: 1 1 14rem;\n\twidth: 100%;\n\tmin-width: 0;\n}",
			'label' => 'identity_header_text_keeps_row_basis',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'id="crm-identity-people-table"',
			'to' => 'id="crm-identity-people-gone"',
			'label' => 'identity_drops_people_table',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testCompanyIdentityShowsDetailsPeopleAndChangeActions',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => '<table class="table table--hover crm-table--responsive" id="crm-identity-people-table">',
			'to' => '<table class="table" id="crm-identity-people-table">',
			'label' => 'identity_people_table_loses_hover_responsive',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '#app-content.crm-app .crm-identity-person__more {',
			'to' => '#app-content.crm-app .crm-identity-person__gone {',
			'label' => 'identity_renames_person_more',
			'filter' => 'ExtremeSimplicityUxContractTest::testCompanyIdentitySurfacesPeopleAddressAndEdit',
		],
		[
			'file' => $appRoot . '/lib/Support/CompanyNotesPresenter.php',
			'from' => "if (self::isMachineLine(\$line)) {\n\t\t\t\tcontinue;\n\t\t\t}",
			'to' => '',
			'label' => 'notes_presenter_keeps_machine_lines',
			'filter' => 'CompanyNotesPresenterTest::testWebsiteOrderFingerprintIsHidden',
		],
		[
			'file' => $appRoot . '/lib/Support/CompanyNotesPresenter.php',
			'from' => "if (self::isMachineLine(\$line)) {\n\t\t\t\tcontinue;\n\t\t\t}\n\t\t\t\$kept[] = \$line;",
			'to' => "if (self::isMachineLine(\$line)) {\n\t\t\t\tcontinue;\n\t\t\t}",
			'label' => 'notes_presenter_drops_human_lines',
			'filter' => 'CompanyNotesPresenterTest::testHumanNotesSurvive',
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
	$cmd = company_address_phpunit_command($phpunit, $appRoot, $filter);
	exec($cmd . ' 2>&1', $out, $code);
	$text = implode("\n", $out);
	if (str_contains($text, 'Failed to connect to the database') || str_contains($text, 'Error in bootstrap script')) {
		fwrite(STDERR, "MUTATION PHPUNIT BOOTSTRAP FAIL for filter {$filter}\n");
		fwrite(STDERR, implode("\n", array_slice($out, -20)) . "\n");
		exit(2);
	}
	echo $text . "\n";
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
