<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Activity assignee + targeted due reminders.
 *
 * Usage (host from apps/customercheck/):
 *   php tests/Mutation/run-activity-assignee-mutations.php
 *
 * Docker:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-activity-assignee-mutations.php
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
function activity_assignee_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/ActivityService.php',
			'from' => "if (!array_key_exists('assigneeUserId', \$input) && !array_key_exists('assignee', \$input)) {\n\t\t\t\$input['assigneeUserId'] = \$actor;\n\t\t}",
			'to' => "// mutated: no default assignee",
			'label' => 'create_skips_default_assignee_actor',
			'filter' => 'ActivityServiceAssigneeTest::testCreateDefaultsAssigneeToActor',
		],
		[
			'file' => $appRoot . '/lib/Service/ActivityService.php',
			'from' => "if (\$ws <= 0 || \$this->members->findMembershipOrNull(\$ws, \$uid) === null) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'assigneeUserId',\n\t\t\t\t\$this->l10n->t('Pick a colleague in this workspace.'),\n\t\t\t);\n\t\t}",
			'to' => "// mutated: accept any assignee uid",
			'label' => 'normalise_assignee_skips_membership',
			'filter' => 'ActivityServiceAssigneeTest::testCreateRejectsNonMemberAssignee',
		],
		[
			'file' => $appRoot . '/lib/Service/ActivityService.php',
			'from' => "if (\$uid === '' || \$uid === \$actor || \$uid === \$prev) {\n\t\t\treturn;\n\t\t}",
			'to' => "if (\$uid === '') {\n\t\t\treturn;\n\t\t}",
			'label' => 'assign_notify_pings_self_and_unchanged',
			'filter' => 'ActivityServiceAssigneeTest::testCreateDefaultsAssigneeToActor',
		],
		[
			'file' => $appRoot . '/lib/Service/ActivityService.php',
			'from' => "\$this->notifications->notifyUser(\n\t\t\t\$uid,\n\t\t\tConstants::NOTIFY_ACTIVITY_ASSIGNED,",
			'to' => "\$this->notifications->notifyUser(\n\t\t\t\$uid,\n\t\t\tConstants::NOTIFY_ACTIVITY_DUE,",
			'label' => 'assign_uses_due_notify_subject',
			'filter' => 'ActivityServiceAssigneeTest::testCreateNotifiesColleagueAssignee',
		],
		[
			'file' => $appRoot . '/lib/Service/ActivityService.php',
			'from' => "\$filters['createdByOrAssignee'] = \$userId;\n\t\t\tunset(\$filters['createdBy'], \$filters['assigneeUserId']);",
			'to' => "\$filters['createdBy'] = \$userId;",
			'label' => 'mobile_search_drops_assignee_visibility',
			'filter' => 'ActivityServiceMobileAccessTest::testSearchForMobileScopesNonAdminsToOwnActivities',
		],
		[
			'file' => $appRoot . '/lib/Service/ActivityService.php',
			'from' => "if (\$createdBy === \$userId || (\$assignee !== '' && \$assignee === \$userId)) {\n\t\t\treturn \$activity;\n\t\t}",
			'to' => "if (\$createdBy === \$userId) {\n\t\t\treturn \$activity;\n\t\t}",
			'label' => 'mobile_access_drops_assignee',
			'filter' => 'ActivityServiceMobileAccessTest::testRequireMobileAccessAllowsAssignee',
		],
		[
			'file' => $appRoot . '/lib/Service/ActivityDueReminderService.php',
			'from' => "if (\$assignee !== '') {\n\t\t\t\t// Assigned to-do → only the colleague gets the due ping.\n\t\t\t\t\$delivered = \$this->notifications->notifyUser(\n\t\t\t\t\t\$assignee,\n\t\t\t\t\tConstants::NOTIFY_ACTIVITY_DUE,\n\t\t\t\t\t'activity',\n\t\t\t\t\t(string)\$activityId,\n\t\t\t\t\t\$params,\n\t\t\t\t) ? 1 : 0;\n\t\t\t} else {\n\t\t\t\t// Legacy / unassigned rows: whole workspace (shared queue).\n\t\t\t\t\$delivered = \$this->notifications->notifyWorkspaceUsers(\n\t\t\t\t\t\$workspaceId,\n\t\t\t\t\tConstants::NOTIFY_ACTIVITY_DUE,\n\t\t\t\t\t'activity',\n\t\t\t\t\t(string)\$activityId,\n\t\t\t\t\t\$params,\n\t\t\t\t);\n\t\t\t}",
			'to' => "\$delivered = \$this->notifications->notifyWorkspaceUsers(\n\t\t\t\t\$workspaceId,\n\t\t\t\tConstants::NOTIFY_ACTIVITY_DUE,\n\t\t\t\t'activity',\n\t\t\t\t(string)\$activityId,\n\t\t\t\t\$params,\n\t\t\t);",
			'label' => 'due_always_fans_out_workspace',
			'filter' => 'ActivityDueReminderServiceTest::testRunNotifiesOnlyAssigneeWhenSet',
		],
		[
			'file' => $appRoot . '/lib/Service/ActivityDueReminderService.php',
			'from' => "if (\$assignee !== '') {\n\t\t\t\t// Assigned to-do → only the colleague gets the due ping.\n\t\t\t\t\$delivered = \$this->notifications->notifyUser(\n\t\t\t\t\t\$assignee,\n\t\t\t\t\tConstants::NOTIFY_ACTIVITY_DUE,\n\t\t\t\t\t'activity',\n\t\t\t\t\t(string)\$activityId,\n\t\t\t\t\t\$params,\n\t\t\t\t) ? 1 : 0;\n\t\t\t} else {\n\t\t\t\t// Legacy / unassigned rows: whole workspace (shared queue).\n\t\t\t\t\$delivered = \$this->notifications->notifyWorkspaceUsers(\n\t\t\t\t\t\$workspaceId,\n\t\t\t\t\tConstants::NOTIFY_ACTIVITY_DUE,\n\t\t\t\t\t'activity',\n\t\t\t\t\t(string)\$activityId,\n\t\t\t\t\t\$params,\n\t\t\t\t);\n\t\t\t}",
			'to' => "\$delivered = \$this->notifications->notifyUser(\n\t\t\t\t\$assignee,\n\t\t\t\tConstants::NOTIFY_ACTIVITY_DUE,\n\t\t\t\t'activity',\n\t\t\t\t(string)\$activityId,\n\t\t\t\t\$params,\n\t\t\t) ? 1 : 0;",
			'label' => 'due_never_fans_out_unassigned',
			'filter' => 'ActivityDueReminderServiceTest::testRunInsertsLedgerThenNotifiesWorkspaceWhenUnassigned',
		],
		[
			'file' => $appRoot . '/lib/Constants.php',
			'from' => "self::NOTIFY_ACTIVITY_DUE,\n\t\tself::NOTIFY_ACTIVITY_ASSIGNED,\n\t\tself::NOTIFY_DEAL_CHECKLIST,\n\t\tself::NOTIFY_DEAL_ROTTING,",
			'to' => "self::NOTIFY_ACTIVITY_DUE,\n\t\tself::NOTIFY_DEAL_CHECKLIST,\n\t\tself::NOTIFY_DEAL_ROTTING,",
			'label' => 'push_subjects_drop_activity_assigned',
			'filter' => 'MobilePushServiceTest',
		],
		[
			'file' => $appRoot . '/lib/Controller/ActivityApiController.php',
			'from' => "'assigneeUserId' => \$a->getAssigneeUserId(),\n\t\t\t'createdBy' => \$a->getCreatedBy(),",
			'to' => "'createdBy' => \$a->getCreatedBy(),",
			'label' => 'api_ser_drops_assignee',
			'filter' => 'ActivityApiAssigneeContractTest',
		],
	];
}

$mutations = activity_assignee_mutations($appRoot);
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
fwrite(STDOUT, "\nMSI (activity assignee): {$msi}% ({$killed}/{$total} killed, {$skipped} skipped)\n");
exit($survived > 0 ? 1 : 0);
