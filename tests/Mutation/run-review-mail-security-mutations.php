<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Review discard lock / compensate / reopen / person-remove order / sent list.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-review-mail-security-mutations.php
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
function review_mail_security_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "// ZEUS NG-01: one live IMAP session per mailbox — same key as sync/preview.\n\t\t\$lockKey = 'crm_mbox_' . \$mailboxId;",
			'to' => "// ZEUS NG-01 mutated: separate delete lock\n\t\t\$lockKey = 'crm_mail_del_' . \$workspaceId . '_' . \$messageId;",
			'label' => 'delete_uses_separate_lock_key',
			'filter' => 'MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey|ReviewMailSecurityHardeningContractTest',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "// IMAP already succeeded — never leave CRM triage open for a gone message.\n\t\t\tif (\$deletedRemote && \$mailId > 0) {\n\t\t\t\ttry {\n\t\t\t\t\t\$this->mailbox->markRemoteDeleted(\$workspaceId, \$mailId);\n\t\t\t\t} catch (\\Throwable) {\n\t\t\t\t\t// Best-effort compensate; original failure is the user-facing error.\n\t\t\t\t}\n\t\t\t}",
			'to' => "// mutated: skip compensate after IMAP success\n\t\t\t",
			'label' => 'discard_skips_remote_delete_compensate',
			'filter' => 'ReviewMailSecurityHardeningContractTest::testDiscardCompensatesRemoteDeleteAfterCrmConflict',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "if (\$mailId > 0 && \$this->mailbox->isRemoteDeleted(\$workspaceId, \$mailId)) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\t'status',\n\t\t\t\t\t\$this->l10n->t('This message was deleted from the shared mailbox and cannot be reopened.'),\n\t\t\t\t);\n\t\t\t}",
			'to' => "// mutated: allow reopen of remote_deleted\n\t\t\t",
			'label' => 'reopen_allows_remote_deleted',
			'filter' => 'ReviewMailSecurityHardeningContractTest::testReopenBlocksRemoteDeletedBeforeMutatingReview',
		],
		[
			'file' => $appRoot . '/lib/Controller/PersonApiController.php',
			'from' => "// Delete first (optimistic lock) so a conflict cannot leave mail unmatched while the person remains.\n\t\t\t\$this->persons->delete(\$id, \$ctx['uid'], \$this->updatedAtPrecondition(\$body));\n\n\t\t\tif (\$returnMail) {\n\t\t\t\t\$this->emailTouches->clearPersonId(\$id);\n\t\t\t\tif (\$email !== '' && \$workspaceId > 0) {\n\t\t\t\t\t\$mailIds = \$this->mailbox->unmatchByCompanyAndFrom(\$workspaceId, \$companyId, \$email);\n\t\t\t\t\tif (\$mailIds !== []) {\n\t\t\t\t\t\t\$this->review->returnSharedMailMessagesToReview(\$workspaceId, \$mailIds);\n\t\t\t\t\t\t\$mailReturned = count(\$mailIds);\n\t\t\t\t\t}\n\t\t\t\t}\n\t\t\t}",
			'to' => "if (\$returnMail) {\n\t\t\t\t\$this->emailTouches->clearPersonId(\$id);\n\t\t\t\tif (\$email !== '' && \$workspaceId > 0) {\n\t\t\t\t\t\$mailIds = \$this->mailbox->unmatchByCompanyAndFrom(\$workspaceId, \$companyId, \$email);\n\t\t\t\t\tif (\$mailIds !== []) {\n\t\t\t\t\t\t\$this->review->returnSharedMailMessagesToReview(\$workspaceId, \$mailIds);\n\t\t\t\t\t\t\$mailReturned = count(\$mailIds);\n\t\t\t\t\t}\n\t\t\t\t}\n\t\t\t}\n\t\t\t\$this->persons->delete(\$id, \$ctx['uid'], \$this->updatedAtPrecondition(\$body));",
			'label' => 'person_unmatch_before_delete',
			'filter' => 'ReviewMailSecurityHardeningContractTest::testPersonDestroyDeletesBeforeUnmatchingMail|PersonRemoveReturnMailContractTest',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "\$fetchLimit = \$dateOnlyQuery ? max(\$limit, min(500, \$limit * 5)) : \$limit;",
			'to' => "\$fetchLimit = \$limit;",
			'label' => 'date_query_loses_wide_fetch',
			'filter' => 'ReviewMailSecurityHardeningContractTest::testListItemsWidensDateFetchAndSortsBySent',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "// Stable Sent ordering for API consumers / client default sort (sentAt || createdAt).\n\t\tusort(\$out, static function (array \$a, array \$b): int {\n\t\t\t\$ak = (string)(\$a['sentAt'] ?? \$a['createdAt'] ?? '');\n\t\t\t\$bk = (string)(\$b['sentAt'] ?? \$b['createdAt'] ?? '');\n\t\t\t\$cmp = strcmp(\$bk, \$ak);\n\t\t\tif (\$cmp !== 0) {\n\t\t\t\treturn \$cmp;\n\t\t\t}\n\n\t\t\treturn ((int)(\$b['id'] ?? 0)) <=> ((int)(\$a['id'] ?? 0));\n\t\t});",
			'to' => "// mutated: keep updated_at SQL order only\n\t\t",
			'label' => 'list_skips_sent_usort',
			'filter' => 'ReviewMailSecurityHardeningContractTest::testListItemsWidensDateFetchAndSortsBySent',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxService.php',
			'from' => "->andWhere(\$qb->expr()->neq('triage_status', \$qb->createNamedParameter('remote_deleted')))",
			'to' => "->andWhere(\$qb->expr()->in('triage_status', \$qb->createNamedParameter(['open', 'discarded'], IQueryBuilder::PARAM_STR_ARRAY)))",
			'label' => 'mark_remote_deleted_skips_matched',
			'filter' => 'ReviewMailSecurityHardeningContractTest::testMarkRemoteDeletedClearsMatchAndSkipsAlreadyPurged',
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
	file_put_contents($mut['file'], $once);

	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$config = $appRoot . '/phpunit.xml';
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);

	return (int)$code;
}

$mutations = review_mail_security_mutations($appRoot);
$failed = 0;
$killed = 0;
$survived = [];

$baseline = 'ReviewMailSecurityHardeningContractTest|MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey|PersonRemoveReturnMailContractTest|ReviewSentAtLogicTest';
echo "BASELINE {$baseline}\n";
if (run_phpunit($phpunit, $appRoot, $baseline) !== 0) {
	fwrite(STDERR, "BASELINE FAIL — refusing mutations while suite is red\n");
	exit(1);
}

echo "Review mail security mutations: " . count($mutations) . "\n";

foreach ($mutations as $mut) {
	echo "\n=== MUTATION: {$mut['label']} ===\n";
	$original = null;
	try {
		$original = apply_mutation($mut);
		$code = run_phpunit($phpunit, $appRoot, $mut['filter']);
		if ($code === 0) {
			echo "SURVIVED (tests still green) — BAD\n";
			$survived[] = $mut['label'];
			$failed++;
		} else {
			echo "KILLED (tests failed as expected)\n";
			$killed++;
		}
	} catch (Throwable $e) {
		echo 'ERROR: ' . $e->getMessage() . "\n";
		$failed++;
		$survived[] = $mut['label'] . ' (apply/error)';
	} finally {
		if ($original !== null) {
			restore_file($mut['file'], $original);
		}
	}
}

echo "\n---\nKilled: {$killed} / " . count($mutations) . "\n";
if ($survived !== []) {
	echo "Survivors:\n- " . implode("\n- ", $survived) . "\n";
	exit(1);
}

echo "All mutations killed.\n";
exit(0);
