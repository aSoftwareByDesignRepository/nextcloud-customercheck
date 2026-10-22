<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Coaching bulk reassign of activity assignees (company-join ACL).
 *
 * Usage (host from apps/customercheck/):
 *   php tests/Mutation/run-coaching-activity-bulk-mutations.php
 *
 * Docker:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-coaching-activity-bulk-mutations.php
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
function coaching_activity_bulk_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "if (in_array('activities', \$types, true) || in_array('activity', \$types, true) || in_array('todos', \$types, true)) {\n\t\t\t\tif (\$this->db->tableExists('crm_activity')) {\n\t\t\t\t\t\$activityIds = array_values(array_unique(array_merge(\$activityIds, \$this->idsOwnedBy(\$workspaceId, 'crm_activity', 'assignee_user_id', \$from, true))));\n\t\t\t\t}\n\t\t\t}",
			'to' => "if (false && (in_array('activities', \$types, true) || in_array('activity', \$types, true) || in_array('todos', \$types, true))) {\n\t\t\t\tif (\$this->db->tableExists('crm_activity')) {\n\t\t\t\t\t\$activityIds = array_values(array_unique(array_merge(\$activityIds, \$this->idsOwnedBy(\$workspaceId, 'crm_activity', 'assignee_user_id', \$from, true))));\n\t\t\t\t}\n\t\t\t}",
			'label' => 'bulk_entityTypes_activities_skipped',
			'filter' => 'CoachingBulkReassignTest::testEntityTypesResolvesOwnedOpenActivities',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "\$scopedActivityIds = \$this->db->tableExists('crm_activity')\n\t\t\t? \$this->filterActivityIdsInWorkspace(\$workspaceId, \$activityIds, \$from)\n\t\t\t: [];",
			'to' => "\$scopedActivityIds = array_values(array_unique(array_filter(array_map('intval', \$activityIds), static fn (int \$id): bool => \$id > 0)));",
			'label' => 'bulk_activity_skips_company_join_acl',
			'filter' => 'CoachingBulkReassignTest::testCrossWorkspaceActivityIdsYieldEmptyAndReject',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "if (\$from === \$to) {\n\t\t\tthrow ValidationException::field(Constants::ERR_VALIDATION, 'toUserId', \$this->l10n->t('Pick a different colleague to receive the work.'));\n\t\t}",
			'to' => "if (false && \$from === \$to) {\n\t\t\tthrow ValidationException::field(Constants::ERR_VALIDATION, 'toUserId', \$this->l10n->t('Pick a different colleague to receive the work.'));\n\t\t}",
			'label' => 'bulk_allows_same_from_to',
			'filter' => 'CoachingBulkReassignTest::testSameFromAndToRejected',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "\$this->assertWorkspaceMember(\$workspaceId, \$to, 'toUserId');",
			'to' => "// mutated: no membership check on toUserId",
			'label' => 'bulk_skips_to_member_assert',
			'filter' => 'CoachingBulkReassignTest::testNonMemberToUserRejected',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "if (\$table === 'crm_activity') {\n\t\t\t\t\$qb->andWhere(\$qb->expr()->isNull('d.done_at'));\n\t\t\t}",
			'to' => "if (false && \$table === 'crm_activity') {\n\t\t\t\t\$qb->andWhere(\$qb->expr()->isNull('d.done_at'));\n\t\t\t}",
			'label' => 'idsOwnedBy_includes_done_activities',
			'filter' => 'CoachingActivityBulkContractTest',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "->andWhere(\$qb->expr()->isNull('a.done_at'))\n\t\t\t->andWhere(\$qb->expr()->in(\n\t\t\t\t'a.id',\n\t\t\t\t\$qb->createNamedParameter(\$activityIds, IQueryBuilder::PARAM_INT_ARRAY),\n\t\t\t));",
			'to' => "->andWhere(\$qb->expr()->in(\n\t\t\t\t'a.id',\n\t\t\t\t\$qb->createNamedParameter(\$activityIds, IQueryBuilder::PARAM_INT_ARRAY),\n\t\t\t));",
			'label' => 'filter_activity_allows_done_rows',
			'filter' => 'CoachingActivityBulkContractTest',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "\$activityAffected += \$qb->update('crm_activity')\n\t\t\t\t\t->set('assignee_user_id', \$qb->createNamedParameter(\$to))\n\t\t\t\t\t->set('updated_at', \$qb->createNamedParameter(\$now))\n\t\t\t\t\t->where(\$qb->expr()->eq('id', \$qb->createNamedParameter(\$id)))\n\t\t\t\t\t->andWhere(\$qb->expr()->eq('assignee_user_id', \$qb->createNamedParameter(\$from)))\n\t\t\t\t\t->andWhere(\$qb->expr()->isNull('done_at'))\n\t\t\t\t\t->executeStatement();",
			'to' => "\$activityAffected += \$qb->update('crm_activity')\n\t\t\t\t\t->set('assignee_user_id', \$qb->createNamedParameter(\$to))\n\t\t\t\t\t->set('updated_at', \$qb->createNamedParameter(\$now))\n\t\t\t\t\t->where(\$qb->expr()->eq('id', \$qb->createNamedParameter(\$id)))\n\t\t\t\t\t->executeStatement();",
			'label' => 'activity_update_drops_assignee_cas',
			'filter' => 'CoachingActivityBulkContractTest',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "if (\$affected !== \$total) {\n\t\t\t\t\$this->db->rollBack();\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_CONFLICT_STALE,\n\t\t\t\t\t'bulk',\n\t\t\t\t\t\$this->l10n->t('Ownership changed while reassigning. Refresh and try again.'),\n\t\t\t\t);\n\t\t\t}",
			'to' => "if (false && \$affected !== \$total) {\n\t\t\t\t\$this->db->rollBack();\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_CONFLICT_STALE,\n\t\t\t\t\t'bulk',\n\t\t\t\t\t\$this->l10n->t('Ownership changed while reassigning. Refresh and try again.'),\n\t\t\t\t);\n\t\t\t}",
			'label' => 'bulk_activity_ignores_cas_miss',
			'filter' => 'CoachingBulkReassignTest::testActivityCasMissRollsBack',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "if (\$this->notifications === null || \$activityIds === [] || \$toUserId === '' || \$toUserId === \$actor) {\n\t\t\treturn;\n\t\t}",
			'to' => "if (\$this->notifications === null || \$activityIds === [] || \$toUserId === '') {\n\t\t\treturn;\n\t\t}",
			'label' => 'activity_bulk_notify_pings_actor',
			'filter' => 'CoachingBulkReassignTest::testActivityBulkSkipsNotifyWhenAssigneeIsActor',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => "\$this->notifications->notifyUser(\n\t\t\t\t\$toUserId,\n\t\t\t\tConstants::NOTIFY_ACTIVITY_ASSIGNED,",
			'to' => "\$this->notifications->notifyUser(\n\t\t\t\t\$toUserId,\n\t\t\t\tConstants::NOTIFY_ACTIVITY_DUE,",
			'label' => 'activity_bulk_uses_due_notify_subject',
			'filter' => 'CoachingBulkReassignTest::testActivityBulkNotifiesNewAssigneeOncePerItem',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "'<label class=\"crm-check\"><input type=\"checkbox\" name=\"entityTypes\" value=\"activities\" checked> ' + escapeHtml(t('Open to-dos')) + '</label>' +",
			'to' => '',
			'label' => 'ui_drops_activities_checkbox',
			'filter' => 'TeamUxContractTest::testPersonaTeamRendersHumanColumnsAndCapacityCards',
		],
	];
}

$mutations = coaching_activity_bulk_mutations($appRoot);
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
fwrite(STDOUT, "\nMSI (coaching activity bulk): {$msi}% ({$killed}/{$total} killed, {$skipped} skipped)\n");
exit($survived > 0 || $skipped > 0 ? 1 : 0);
