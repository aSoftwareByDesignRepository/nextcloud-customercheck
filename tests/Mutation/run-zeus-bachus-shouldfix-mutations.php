<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Zeus Bachus Should-Fix remediations (SF-01..SF-03).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-zeus-bachus-shouldfix-mutations.php
 *
 * Host (when vendor present):
 *   php tests/Mutation/run-zeus-bachus-shouldfix-mutations.php
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
function zeus_bachus_shouldfix_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/utils/glance-summary.js',
			'from' => "tone: moneyGlanceTone(billingState, !!coverageRaw.gap),",
			'to' => "tone: billingTone(billingState),",
			'label' => 'money_tone_ignores_coverage_gap_utils',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testMoneyGlanceElevatesCoverageGapAbovePaidUpSuccess|GlanceSummaryContractTest::testUtilsAndBootstrapShareCriticalCoverageBranch',
		],
		[
			'file' => $appRoot . '/js/common/glance-summary.js',
			'from' => "tone: moneyGlanceTone(billingState, !!coverageRaw.gap),",
			'to' => "tone: billingTone(billingState),",
			'label' => 'money_tone_ignores_coverage_gap_bootstrap',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testMoneyGlanceElevatesCoverageGapAbovePaidUpSuccess|GlanceSummaryContractTest::testUtilsAndBootstrapShareCriticalCoverageBranch',
		],
		[
			'file' => $appRoot . '/js/utils/glance-summary.js',
			'from' => "return bill === 'danger' ? 'danger' : 'warning';",
			'to' => 'return bill;',
			'label' => 'moneyGlanceTone_noop_passthrough',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testMoneyGlanceElevatesCoverageGapAbovePaidUpSuccess',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'let mailPreviewGeneration = 0;',
			'to' => 'let mailPreviewGenerationUnused = 0;',
			'label' => 'review_mail_preview_generation_renamed',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testReviewMailPreviewUsesGenerationAndAssignSubmitLocks',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'previewGeneration: previewReq.gen,',
			'to' => '/* previewGeneration dropped */ quietFocus: !!(opts && opts.quietFocus),',
			'label' => 'review_mail_preview_generation_not_passed',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testReviewMailPreviewUsesGenerationAndAssignSubmitLocks',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'mailPreviewAbort.abort();',
			'to' => '/* abort dropped */;',
			'label' => 'review_mail_preview_abort_dropped',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testReviewMailPreviewUsesGenerationAndAssignSubmitLocks|ArgusBachusMailSecurityContractTest::testMailPreviewAbortControllerCancelsPriorFetch',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "form.setAttribute('aria-busy', 'true');",
			'to' => "/* assign submit lock removed */ form.setAttribute('data-crm-busy-ignored', '1');",
			'label' => 'assign_submit_loses_aria_busy',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testReviewMailPreviewUsesGenerationAndAssignSubmitLocks',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'let talkMailPreviewGeneration = 0;',
			'to' => 'let talkMailPreviewGenerationUnused = 0;',
			'label' => 'talk_mail_preview_generation_renamed',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testTalkMailPreviewUsesGenerationAgainstArrowStampede',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'return previewGen !== talkMailPreviewGeneration || !previewWrap.isConnected;',
			'to' => 'return false;',
			'label' => 'talk_stale_preview_never_stale',
			'filter' => 'ZeusBachusUxArchitectureContractTest::testTalkMailPreviewUsesGenerationAgainstArrowStampede',
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

$mutations = zeus_bachus_shouldfix_mutations($appRoot);
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
	$count = 0;
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
fwrite(STDOUT, "MSI (zeus-bachus-shouldfix): {$msi}% killed={$killed} survived={$failed} skipped={$skipped}\n");
exit($failed > 0 || $skipped > 0 ? 1 : 0);
