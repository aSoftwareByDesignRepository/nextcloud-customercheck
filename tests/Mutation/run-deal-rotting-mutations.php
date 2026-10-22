<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Deal rotting freshness + owner reminders.
 *
 * Usage: php tests/Mutation/run-deal-rotting-mutations.php
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
function deal_rotting_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/DealFreshnessLogic.php',
			'from' => 'return self::daysIdle($stageChangedAt, $today) >= $threshold;',
			'to' => 'return self::daysIdle($stageChangedAt, $today) > $threshold;',
			'label' => 'rotting_uses_exclusive_threshold',
			'filter' => 'DealFreshnessLogicTest::testIsRottingUsesInclusiveThreshold',
		],
		[
			'file' => $appRoot . '/lib/Service/DealFreshnessLogic.php',
			'from' => "return intdiv(\$stageIdleDays, \$threshold);",
			'to' => 'return $stageIdleDays >= $threshold ? 1 : 0;',
			'label' => 'nudge_index_never_escalates',
			'filter' => 'DealFreshnessLogicTest::testNudgeIndexAndSnapKey',
		],
		[
			'file' => $appRoot . '/lib/Service/DealRottingReminderService.php',
			'from' => "if (\$owner === '') {\n\t\t\t// Unowned rotting deals stay visible in Today/Pipeline; no owner to ping.\n\t\t\treturn 'skipped';\n\t\t}",
			'to' => "// mutated: notify without owner\n\t\t\$owner = 'admin';",
			'label' => 'rotting_notifies_without_owner',
			'filter' => 'DealRottingReminderServiceTest::testRunSkipsUnownedDeals',
		],
		[
			'file' => $appRoot . '/lib/Service/DealRottingReminderService.php',
			'from' => "Constants::NOTIFY_DEAL_ROTTING,",
			'to' => "Constants::NOTIFY_DEAL_CHECKLIST,",
			'label' => 'rotting_uses_checklist_subject',
			'filter' => 'DealRottingReminderServiceTest::testRunNotifiesOwnerOncePerSnap',
		],
		[
			'file' => $appRoot . '/lib/Service/DealRottingReminderService.php',
			'from' => "if (\$this->ledger->exists(\$dealId, \$snapKey)) {\n\t\t\treturn 'skipped';\n\t\t}",
			'to' => "// mutated: skip ledger exists guard",
			'label' => 'rotting_drops_ledger_exists',
			'filter' => 'DealRottingReminderServiceTest::testRunSkipsWhenLedgerExists',
		],
		[
			'file' => $appRoot . '/lib/Service/DealRottingReminderService.php',
			'from' => "if (!\$ok) {\n\t\t\t\t\$this->releaseClaim(\$row, \$dealId, \$snapKey);\n\n\t\t\t\treturn 'skipped';\n\t\t\t}",
			'to' => "if (false && !\$ok) {\n\t\t\t\t\$this->releaseClaim(\$row, \$dealId, \$snapKey);\n\n\t\t\t\treturn 'skipped';\n\t\t\t}",
			'label' => 'rotting_keeps_claim_when_notify_fails',
			'filter' => 'DealRottingReminderServiceTest::testRunReleasesLedgerWhenNotifyFails',
		],
		[
			'file' => $appRoot . '/lib/Constants.php',
			'from' => "self::NOTIFY_DEAL_CHECKLIST,\n\t\tself::NOTIFY_DEAL_ROTTING,\n\t\tself::NOTIFY_EDIT_CONFLICT,",
			'to' => "self::NOTIFY_DEAL_CHECKLIST,\n\t\tself::NOTIFY_EDIT_CONFLICT,",
			'label' => 'notification_subjects_drop_deal_rotting',
			'filter' => 'DealRottingContractTest::testNotificationSubjectsIncludeDealRotting',
		],
		[
			'file' => $appRoot . '/lib/Service/MobilePushService.php',
			'from' => "Constants::NOTIFY_DEAL_CHECKLIST,\n\t\tConstants::NOTIFY_DEAL_ROTTING,\n\t];",
			'to' => "Constants::NOTIFY_DEAL_CHECKLIST,\n\t];",
			'label' => 'push_subjects_drop_deal_rotting',
			'filter' => 'MobilePushServiceTest',
		],
		[
			'file' => $appRoot . '/templates/common/crm-deal-freshness.php',
			'from' => "\$l->t('Needs follow-up')",
			'to' => "\$l->t('Stale')",
			'label' => 'freshness_badge_reverts_to_stale_jargon',
			'filter' => 'DealRottingContractTest::testPipelineShowsNeedsFollowUpNotStaleJargon',
		],
		[
			'file' => $appRoot . '/lib/BackgroundJob/RenewalReminderJob.php',
			'from' => "if (\$this->dealRotting->shouldRunNow()) {\n\t\t\t\$this->dealRotting->run();\n\t\t}",
			'to' => "// mutated: deal rotting job not wired",
			'label' => 'renewal_job_drops_deal_rotting',
			'filter' => 'DealRottingContractTest::testRenewalJobWiresDealRotting',
		],
	];
}

$mutations = deal_rotting_mutations($appRoot);
$killed = 0;
$survived = 0;
$skipped = 0;

foreach ($mutations as $m) {
	$file = $m['file'];
	$src = (string)file_get_contents($file);
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
	// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
	// a kill-test that is red unmutated turns nonzero exits into fake kills.
	require_once __DIR__ . '/MutationBaseline.php';
	mutationBaselineSelfCheck(__FILE__);
	file_put_contents($file, $mutated);
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($m['filter'])
		. ' 2>&1';
	exec($cmd, $out, $code);
	file_put_contents($file, $src);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED  {$m['label']}\n");
		$survived++;
	} else {
		fwrite(STDOUT, "KILLED  {$m['label']}\n");
		$killed++;
	}
}

$total = $killed + $survived;
$msi = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
fwrite(STDOUT, "\nMSI (deal rotting): {$msi}% ({$killed}/{$total} killed, {$skipped} skipped)\n");
exit($survived > 0 ? 1 : 0);
