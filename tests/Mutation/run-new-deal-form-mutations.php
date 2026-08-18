<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: shared new-deal form, personId/leadSource, post-create next step.
 *
 * Host apply/revert (UID 1000). PHPUnit via Docker when available; Vitest on host.
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function new_deal_form_phpunit_command(string $phpunit, string $filter, string $testFile): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	$composeDir = dirname($appRoot, 2);
	$dockerPhpunit = '/var/www/html/custom_apps/customercheck/vendor/bin/phpunit';
	$dockerConfig = '/var/www/html/custom_apps/customercheck/phpunit.xml';
	$dockerTestFile = '/var/www/html/custom_apps/customercheck/' . ltrim($testFile, '/');
	if (is_file($composeDir . '/docker-compose.yml')) {
		$probe = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";');
		exec($probe . ' 2>/dev/null', $probeOut, $probeCode);
		if ($probeCode === 0) {
			return 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
				. ' exec -T -u www-data nextcloud php ' . escapeshellarg($dockerPhpunit)
				. ' -c ' . escapeshellarg($dockerConfig)
				. ' ' . escapeshellarg($dockerTestFile)
				. ' --filter ' . escapeshellarg($filter);
		}
	}
	return escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' ' . escapeshellarg($appRoot . '/' . ltrim($testFile, '/'))
		. ' --filter ' . escapeshellarg($filter);
}

function new_deal_form_vitest_command(): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	return 'npm --prefix ' . escapeshellarg($appRoot)
		. ' run test:js -- tests/js/new-deal-form-core.test.js';
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
 * @return list<array{file:string,from:string,to:string,label:string,tool:string,filter?:string}>
 */
function new_deal_form_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/DealService.php',
			'from' => '$this->applyPersonId($deal, $companyId, $data[\'personId\'] ?? null);',
			'to' => '',
			'label' => 'create_drops_person_id_binding',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/Service/DealServicePersonLeadSourceTest.php',
			'filter' => 'testCreateSetsPersonAndLeadSource',
		],
		[
			'file' => $appRoot . '/lib/Service/DealService.php',
			'from' => '$deal->setLeadSource($this->resolveLeadSource($data[\'leadSource\'] ?? null, true));',
			'to' => '',
			'label' => 'create_drops_lead_source_default',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/Service/DealServicePersonLeadSourceTest.php',
			'filter' => 'testCreateDefaultsLeadSourceManual',
		],
		[
			'file' => $appRoot . '/lib/Support/LeadSource.php',
			'from' => "return 'other';",
			'to' => "return 'manual';",
			'label' => 'unknown_source_no_longer_maps_to_other',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/Support/LeadSourceTest.php',
			'filter' => 'testUnknownMapsToOther',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => 'name="personId"',
			'to' => 'name="person"',
			'label' => 'new_deal_form_loses_person_id_field',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealFormUxContractTest.php',
			'filter' => 'testNewDealFormJsExportsOpenAndNextStep',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => 'name="leadSource"',
			'to' => 'name="lead_source"',
			'label' => 'new_deal_form_loses_lead_source_field',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealFormUxContractTest.php',
			'filter' => 'testNewDealFormJsExportsOpenAndNextStep',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => "await Api.post('/activities', body);",
			'to' => '',
			'label' => 'next_step_nudge_skips_activity_create',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealFormUxContractTest.php',
			'filter' => 'testNewDealFormJsExportsOpenAndNextStep',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => "const OPEN_STAGES = ['lead', 'contacted', 'quoted', 'negotiation'];",
			'to' => "const OPEN_STAGES = ['lead', 'contacted', 'quoted', 'negotiation', 'won'];",
			'label' => 'open_stages_allow_won_on_create',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealFormUxContractTest.php',
			'filter' => 'testNewDealFormJsExportsOpenAndNextStep',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => 'crm-form-section crm-new-deal__section',
			'to' => 'crm-new-deal__section',
			'label' => 'form_loses_section_semantics',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealFormUxContractTest.php',
			'filter' => 'testNewDealFormJsExportsOpenAndNextStep',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => '<fieldset class="crm-form-section crm-new-deal__section crm-new-deal__section--notes">',
			'to' => '<details class="crm-advanced"><summary>\' + escapeHtml(t(\'More options\')) + \'</summary></details><fieldset class="crm-form-section crm-new-deal__section crm-new-deal__section--notes">',
			'label' => 'notes_section_moves_after_more_landmark',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/DealNotesUxContractTest.php',
			'filter' => 'testCompanyDetailEditDealHandlerAndNotesFields',
		],
		[
			'file' => $appRoot . '/js/common/person-picker.js',
			'from' => 'if (p && p.isPrimary) {',
			'to' => 'if (false && p && p.isPrimary) {',
			'label' => 'person_picker_skips_primary_preselect',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealFormUxContractTest.php',
			'filter' => 'testPersonPickerLoadsCompanyPersons',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => "open: openNewDealForm,\n    offerNextStep: offerNextStep,",
			'to' => "open: openNewDealForm,",
			'label' => 'module_stops_exporting_offer_next_step',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/css/common/new-deal-form.css',
			'from' => 'min-height: var(--crm-touch, 44px);',
			'to' => 'min-height: 28px;',
			'label' => 'new_deal_form_loses_touch_target',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealDialogUxContractTest.php',
			'filter' => 'testCompanyPickerCssImported',
		],
		[
			'file' => $appRoot . '/lib/Support/WinChance.php',
			'from' => "'100' => 'certain',",
			'to' => "'100' => 'commit',",
			'label' => 'hundred_percent_collapses_to_commit',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/Support/WinChanceTest.php',
			'filter' => 'testHundredPercentIsNotWon',
		],
		[
			'file' => $appRoot . '/js/common/new-deal-form.js',
			'from' => "    { value: 'certain', percent: 100, label: '100%' },",
			'to' => '',
			'label' => 'form_drops_100_percent_option',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/NewDealFormUxContractTest.php',
			'filter' => 'testNewDealFormJsExportsOpenAndNextStep',
		],
		[
			'file' => $appRoot . '/lib/Service/DisplayLabelService.php',
			'from' => "public function forecastBucketOptions(): array\n\t{\n\t\t\$out = [];",
			'to' => "public function forecastBucketOptions(): array\n\t{\n\t\t\$out = [['value' => '', 'label' => \$this->l10n->t('None')]];",
			'label' => 'forecast_options_reintroduce_empty_none',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/Service/DisplayLabelServiceTest.php',
			'filter' => 'testForecastBucketLabelsArePercentages',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => 'if (WinChance::isHighConfidence(is_string($bucket) ? $bucket : null)) {',
			'to' => 'if (($bucket ?? \'\') === \'commit\') {',
			'label' => 'coaching_ignores_certain_as_commit',
			'tool' => 'phpunit',
			'testFile' => 'tests/Unit/Service/CoachingBoardTest.php',
			'filter' => 'testManagerSeesAllRowsAndStuckSummary',
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
foreach (new_deal_form_mutations($appRoot) as $m) {
	fwrite(STDOUT, "=== MUTATION: {$m['label']} ===\n");
	$original = apply_mutation($m);
	if (($m['tool'] ?? 'phpunit') === 'vitest') {
		$cmd = new_deal_form_vitest_command();
	} else {
		$cmd = new_deal_form_phpunit_command(
			$phpunit,
			(string)($m['filter'] ?? 'testNewDealFormJsExportsOpenAndNextStep'),
			(string)($m['testFile'] ?? 'tests/Unit/NewDealFormUxContractTest.php'),
		);
	}
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
