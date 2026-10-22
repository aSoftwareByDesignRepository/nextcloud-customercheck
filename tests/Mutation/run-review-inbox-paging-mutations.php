<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Review inbox dead-mail purge + stable list window.
 *
 * Run from host:
 *   php tests/Mutation/run-review-inbox-paging-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';

if (!is_file($phpunit)) {
	fwrite(STDERR, "Missing phpunit at $phpunit\n");
	exit(1);
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Support/ReviewSharedMailTriage.php',
			'from' => "if (\$mailRow === false || \$mailRow === null) {\n\t\t\treturn true;\n\t\t}",
			'to' => "if (false && (\$mailRow === false || \$mailRow === null)) {\n\t\t\treturn true;\n\t\t}",
			'label' => 'missing_mail_not_gone',
			'filter' => 'ReviewSharedMailTriageTest::testMissingMailIsGone',
		],
		[
			'file' => $appRoot . '/lib/Support/ReviewSharedMailTriage.php',
			'from' => "return in_array(\$status, self::DEAD_STATUSES, true);",
			'to' => "return in_array(\$status, ['nope'], true);",
			'label' => 'remote_deleted_not_dead',
			'filter' => 'ReviewSharedMailTriageTest::testRemoteDeletedAndDiscardedAreGone',
		],
		[
			'file' => $appRoot . '/lib/Support/ReviewSharedMailTriage.php',
			'from' => "if (self::isMailboxGone(\$mailRow)) {\n\t\t\treturn 'dead';\n\t\t}",
			'to' => "if (false && self::isMailboxGone(\$mailRow)) {\n\t\t\treturn 'dead';\n\t\t}",
			'label' => 'open_review_never_dead',
			'filter' => 'ReviewSharedMailTriageTest::testRemoteDeletedAndDiscardedAreGone',
		],
		[
			'file' => $appRoot . '/lib/Support/ReviewSharedMailTriage.php',
			'from' => "if (self::isMatchedForAutoClose(\$mailRow)) {\n\t\t\treturn 'matched';\n\t\t}",
			'to' => "if (false && self::isMatchedForAutoClose(\$mailRow)) {\n\t\t\treturn 'matched';\n\t\t}",
			'label' => 'matched_never_auto_closes',
			'filter' => 'ReviewSharedMailTriageTest::testMatchedWithCompanyAutoCloses',
		],
		[
			'file' => $appRoot . '/lib/Support/ReviewSharedMailTriage.php',
			'from' => "return max(\$limit, min(500, max(\$limit * 3, 150)));",
			'to' => "return \$limit;",
			'label' => 'fetch_window_no_overfetch',
			'filter' => 'ReviewSharedMailTriageTest::testFetchWindowOverFetchesForDeadDropsAndSentSort',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "\$qb->orderBy('id', 'DESC')->setMaxResults(\$fetchLimit);",
			'to' => "\$qb->orderBy('updated_at', 'DESC')->setMaxResults(\$fetchLimit);",
			'label' => 'list_reverts_to_updated_at_window',
			'filter' => 'ReviewInboxUxContractTest::testReviewListPurgesDeadMailAndUsesStableQueueWindow',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "\$out = \$this->dropDeadSharedMailItems(\$workspaceId, \$out);",
			'to' => "// mutated: keep dead shared-mail ghosts in the list\n\t\t",
			'label' => 'list_keeps_dead_mail_ghosts',
			'filter' => 'ReviewInboxUxContractTest::testReviewListPurgesDeadMailAndUsesStableQueueWindow',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "t('Inbox refreshed — next messages in the queue are shown.')",
			'to' => "t('Inbox refreshed — ignore the queue.')",
			'label' => 'ux_queue_copy_removed',
			'filter' => 'ReviewInboxUxContractTest::testReviewListPurgesDeadMailAndUsesStableQueueWindow',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $mut
 */
function apply_mutation(array $mut): string
{
	$src = (string)file_get_contents($mut['file']);
	if (!str_contains($src, $mut['from'])) {
		throw new RuntimeException('Mutation needle missing for ' . $mut['label']);
	}
	$backup = $src;
	// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
	// a kill-test that is red unmutated turns nonzero exits into fake kills.
	require_once __DIR__ . '/MutationBaseline.php';
	mutationBaselineSelfCheck(__FILE__);
	file_put_contents($mut['file'], str_replace($mut['from'], $mut['to'], $src));

	return $backup;
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $mut
 */
function restore(array $mut, string $backup): void
{
	file_put_contents($mut['file'], $backup);
}

$killed = 0;
$survived = 0;
foreach (mutations($appRoot) as $mut) {
	$backup = apply_mutation($mut);
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($mut['filter']);
	passthru($cmd, $code);
	restore($mut, $backup);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$mut['label']}\n");
		$survived++;
	} else {
		fwrite(STDOUT, "Killed: {$mut['label']}\n");
		$killed++;
	}
}

fwrite(STDOUT, "Review inbox paging mutations: {$killed} killed, {$survived} survived\n");
exit($survived === 0 ? 0 : 1);
