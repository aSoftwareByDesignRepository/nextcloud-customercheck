<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Bachus company / deal / log-email simplicity.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-bachus-simplicity-mutations.php
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
function bachus_simplicity_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/templates/company-form.php',
			'from' => '<details class="crm-advanced" id="crm-company-advanced">',
			'to' => '<details class="crm-advanced" id="crm-company-advanced"<?php if ($mode === \'edit\'): ?> open<?php endif; ?>>',
			'label' => 'company_edit_force_opens_advanced',
			'filter' => 'BachusSimplicityUxContractTest::testCompanyFormNeverForceOpensAdvancedOnEdit|ExtremeSimplicityUxContractTest::testCompanyFormDemotesSlugToAdvanced',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "name: 'notes',\n          label: t('Notes'),\n          type: 'textarea',\n          advanced: true,\n          maxlength: 4000,",
			'to' => "name: 'notes',\n          label: t('Notes'),\n          type: 'textarea',\n          maxlength: 4000,",
			'label' => 'add_deal_notes_leave_primary',
			'filter' => 'DealNotesUxContractTest::testCompanyDetailEditDealHandlerAndNotesFields',
		],
		[
			'file' => $appRoot . '/js/pages/pipeline.js',
			'from' => 'async function offerCreateCompanyFirst() {',
			'to' => "async function offerCreateCompanyFirstBroken() {\n    return;\n  }\n  async function offerCreateCompanyFirst() {\n    await C.alertDialog({ title: t('No companies yet'), message: t('Create a company first, then add a deal.'), variant: 'info' });\n    return;\n    // dead",
			'label' => 'new_deal_empty_reverts_to_alert_deadend',
			'filter' => 'NewDealDialogUxContractTest|BachusSimplicityUxContractTest::testPipelineEmptyStateOffersCreateCompanyWhenNoCompanies',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "confirmLabel: t('Add person'),\n        cancelLabel: t('Not now'),",
			'to' => "confirmLabel: t('OK'),\n        cancelLabel: t('Cancel'),",
			'label' => 'log_email_loses_add_person_cta',
			'filter' => 'EmailsSectionContractTest::testLogDialogUsesPersonAndSubjectOnly',
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

$mutations = bachus_simplicity_mutations($appRoot);
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
fwrite(STDOUT, "MSI (bachus-simplicity): {$msi}% killed={$killed} survived={$failed} skipped={$skipped}\n");
exit($failed > 0 || $skipped > 0 ? 1 : 0);
