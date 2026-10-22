<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: workspace sync-all + mailSync summary edges.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-mail-sync-now-mutations.php
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
function mail_sync_now_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "if ((string)(\$box['status'] ?? '') === 'error') {\n\t\t\t\tcontinue;\n\t\t\t}",
			'to' => "// mutated: sync error mailboxes too\n\t\t\t",
			'label' => 'syncWorkspace_attempts_error_mailboxes',
			'filter' => 'MailboxSyncServiceTest::testSyncWorkspaceSkipsErrorMailboxes',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "if (\$workspaceId <= 0) {\n\t\t\tthrow ValidationException::field(",
			'to' => "if (false && \$workspaceId <= 0) {\n\t\t\tthrow ValidationException::field(",
			'label' => 'syncWorkspace_allows_invalid_id',
			'filter' => 'MailboxSyncServiceTest::testSyncWorkspaceRejectsInvalidWorkspaceId',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "} elseif (\$locked > 0 && \$ok < 1 && \$errors < 1) {",
			'to' => "} elseif (false && \$locked > 0 && \$ok < 1 && \$errors < 1) {",
			'label' => 'syncWorkspace_locked_reports_up_to_date',
			'filter' => 'MailboxSyncServiceTest::testSyncWorkspaceLockedOnlySurfacesBusyMessage',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "\$attempted++;\n\t\t\ttry {\n\t\t\t\t\$result = \$this->syncOne(\$id, \$workspaceId);",
			'to' => "\$attempted++;\n\t\t\tif (\$attempted > 1) {\n\t\t\t\tcontinue;\n\t\t\t}\n\t\t\ttry {\n\t\t\t\t\$result = \$this->syncOne(\$id, \$workspaceId);",
			'label' => 'syncWorkspace_stops_after_first_healthy_box',
			'filter' => 'MailboxSyncServiceTest::testSyncWorkspaceSyncsEveryHealthyMailbox',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "} elseif (\$errors > 0 && \$ok > 0) {",
			'to' => "} elseif (false && \$errors > 0 && \$ok > 0) {",
			'label' => 'syncWorkspace_partial_success_hidden',
			'filter' => 'MailboxSyncServiceTest::testSyncWorkspacePartialSuccessReportsAttention',
		],
		[
			'file' => $appRoot . '/js/common/mail-sync-now.js',
			'from' => "parts.push(t('%s shared mailboxes connected.', [String(summary.mailboxCount)]));",
			'to' => "// mutated: hide multi mailbox count\n",
			'label' => 'statusLine_hides_multi_mailbox_count',
			'filter' => 'MailSyncNowUxContractTest::testFrontendMountsSharedMailSyncBar',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "if (\$boxes === []) {\n\t\t\treturn null;\n\t\t}",
			'to' => "if (false && \$boxes === []) {\n\t\t\treturn null;\n\t\t}",
			'label' => 'mailSyncSummary_empty_not_null',
			'filter' => 'MailboxSyncServiceTest::testMailSyncSummaryFromBoxes|MailboxSyncServiceTest::testSyncWorkspaceWithNoMailboxes',
		],
		[
			'file' => $appRoot . '/lib/Controller/PersonaApiController.php',
			'from' => "\t\t\t\$this->workspaces->assertCapability(\$uid, \$workspaceId, 'write');\n\t\t\t\$this->workspaces->assertCapability(\$uid, \$workspaceId, 'mail.read');\n\n\t\t\t\$result = \$this->mailboxSync->syncWorkspace(\$workspaceId);",
			'to' => "\t\t\t\$this->workspaces->assertCapability(\$uid, \$workspaceId, 'write');\n\t\t\t// mutated: skip mail.read gate\n\n\t\t\t\$result = \$this->mailboxSync->syncWorkspace(\$workspaceId);",
			'label' => 'syncAll_skips_mail_read_capability',
			'filter' => 'MailSyncNowUxContractTest::testSyncAllRequiresWriteAndMailRead',
		],
		[
			'file' => $appRoot . '/js/common/mail-sync-now.js',
			'from' => "if (btn.disabled || syncing) {\n          return;\n        }",
			'to' => "if (btn.disabled) {\n          return;\n        }",
			'label' => 'mountBar_allows_concurrent_sync_clicks',
			'filter' => 'MailSyncNowUxContractTest::testFrontendMountsSharedMailSyncBar',
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
		throw new RuntimeException('Mutation source not found for ' . $mut['label']);
	}
	$once = preg_replace('/' . preg_quote($mut['from'], '/') . '/', $mut['to'], $src, 1, $count);
	if ($once === null || $count !== 1) {
		throw new RuntimeException('Mutation apply failed for ' . $mut['label'] . ' (count=' . $count . ')');
	}
	// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
	// a kill-test that is red unmutated turns nonzero exits into fake kills.
	require_once __DIR__ . '/MutationBaseline.php';
	mutationBaselineSelfCheck(__FILE__);
	file_put_contents($mut['file'], $once);

	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

function run_filtered_tests(string $phpunit, string $appRoot, string $filter): bool
{
	$cmd = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter)
		. ' 2>&1';
	exec($cmd, $out, $code);

	return $code === 0;
}

$mutations = mail_sync_now_mutations($appRoot);
$killed = 0;
$survived = 0;

foreach ($mutations as $mut) {
	$original = apply_mutation($mut);
	$testsGreen = run_filtered_tests($phpunit, $appRoot, $mut['filter']);
	if ($testsGreen) {
		fwrite(STDERR, "SURVIVED: {$mut['label']}\n");
		$survived++;
	} else {
		fwrite(STDOUT, "KILLED: {$mut['label']}\n");
		$killed++;
	}
	restore_file($mut['file'], $original);
}

$total = count($mutations);
fwrite(STDOUT, "\nMail sync now mutations: {$killed}/{$total} killed, {$survived} survived.\n");

if ($survived > 0) {
	exit(1);
}

exit(0);
