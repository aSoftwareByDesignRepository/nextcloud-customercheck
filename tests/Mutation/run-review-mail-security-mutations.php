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
			'from' => <<<'FROM'
			// Delete first (optimistic lock) so a conflict cannot leave mail unmatched while the person remains.
			$this->persons->delete($id, $ctx['uid'], $this->updatedAtPrecondition($body));

			if ($returnMail) {
				$this->emailTouches->clearPersonId($id);
				if ($email !== '' && $workspaceId > 0) {
					$mailIds = $this->mailbox->unmatchByCompanyAndFrom($workspaceId, $companyId, $email);
					if ($mailIds !== []) {
						$this->review->returnSharedMailMessagesToAssign($workspaceId, $mailIds);
						$mailReturned = count($mailIds);
					}
				}
			}
FROM
			,
			'to' => <<<'TO'
			if ($returnMail) {
				$this->emailTouches->clearPersonId($id);
				if ($email !== '' && $workspaceId > 0) {
					$mailIds = $this->mailbox->unmatchByCompanyAndFrom($workspaceId, $companyId, $email);
					if ($mailIds !== []) {
						$this->review->returnSharedMailMessagesToAssign($workspaceId, $mailIds);
						$mailReturned = count($mailIds);
					}
				}
			}
			$this->persons->delete($id, $ctx['uid'], $this->updatedAtPrecondition($body));
TO
			,
			'label' => 'person_unmatch_before_delete',
			'filter' => 'ReviewMailSecurityHardeningContractTest::testPersonDestroyDeletesBeforeUnmatchingMail|PersonRemoveReturnMailContractTest',
		],
		[
			'file' => $appRoot . '/lib/Support/ReviewSharedMailTriage.php',
			'from' => "\t\tif (\$dateOnlyQuery) {\n\t\t\treturn max(\$limit, min(500, max(\$limit * 5, 200)));\n\t\t}",
			'to' => "\t\tif (\$dateOnlyQuery) {\n\t\t\treturn \$limit;\n\t\t}",
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
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "if (\$discarded < 1 && \$failed > 0) {\n\t\t\t\$first = \$errors[0]['message'] ?? \$this->l10n->t('Could not delete the selected messages.');\n\t\t\tthrow new ValidationException(Constants::ERR_VALIDATION, \$first);\n\t\t}",
			'to' => "// mutated: silent all-fail bulk discard\n\t\t",
			'label' => 'discard_bulk_silent_all_fail',
			'filter' => 'ReviewDiscardBulkTest::testBulkAllImapFailuresSurfaceAsValidationError',
		],
		[
			'file' => $appRoot . '/lib/Support/ImapConnection.php',
			'from' => "return strcasecmp(\$name, 'INBOX') !== 0;",
			'to' => 'return true;',
			'label' => 'pick_junk_allows_inbox',
			'filter' => 'ImapJunkFolderPickTest::testNeverReturnsInboxOrUnsafe',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "if (\$discarded < 1 && \$failed > 0) {\n\t\t\t\$first = \$errors[0]['message'] ?? \$this->l10n->t('Could not mark the selected messages as spam.');\n\t\t\tthrow new ValidationException(Constants::ERR_VALIDATION, \$first);\n\t\t}",
			'to' => "// mutated: silent all-fail bulk mark-spam\n\t\t",
			'label' => 'mark_spam_bulk_silent_all_fail',
			'filter' => 'ReviewMarkSpamBulkTest::testBulkAllImapFailuresSurfaceAsValidationError',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => '$repaired = $this->mailbox->backfillForwardHintsFromPreview(
			$workspaceId,
			$this->matchExcludeEmails($workspaceId),
			Constants::MAIL_SYNC_HINT_BACKFILL_CAP,
		);',
			'to' => '$repaired = 0; // mutated: skip preview hint backfill',
			'label' => 'sync_skips_preview_hint_backfill',
			'filter' => 'MailRepairRematchTest::testContractPostMailboxSyncBackfillsHintsBeforeRematch|MailRepairRematchTest::testThunderbirdPreviewBackfillYieldsForwardFromMatch',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "\$filed = \$this->reconcileKnownSharedMail(\$workspaceId);\n\t\t\$promoted = \$this->promoteOpenSharedMail(\$workspaceId);",
			'to' => "// mutated: skip rematch of known customers\n\t\t\$filed = 0;\n\t\t\$promoted = \$this->promoteOpenSharedMail(\$workspaceId);",
			'label' => 'sync_skips_known_customer_rematch',
			'filter' => 'ReviewKnownCustomerRematchContractTest::testSyncSharedMailRematchesBeforeEnqueue',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "\$pageSize = 5000;\n\t\tdo {\n\t\t\t\$batch = \$this->companyMapper->search(\$filters, \$pageSize, \$offset);",
			'to' => "\$pageSize = 5000;\n\t\t// mutated: hard-cap catalog at first page only\n\t\t\$companies = \$this->companyMapper->search(\$filters, 500, 0);\n\t\tif (false) do {\n\t\t\t\$batch = \$this->companyMapper->search(\$filters, \$pageSize, \$offset);",
			'label' => 'catalog_reverts_to_500_cap',
			'filter' => 'ReviewKnownCustomerRematchContractTest::testSuggestionCatalogNoLongerHardCapsAt500Companies',
		],
		[
			'file' => $appRoot . '/lib/Service/MailMatchLogic.php',
			'from' => "public static function normalizeFromAddress(string \$fromAddress): string\n\t{\n\t\t\$fromAddress = trim(str_replace(\"\\xc2\\xa0\", ' ', \$fromAddress));\n\t\tif (\$fromAddress === '') {\n\t\t\treturn '';\n\t\t}\n\t\tif (preg_match('/<([^<>@\\s]+@[^<>@\\s]+)>/', \$fromAddress, \$m) === 1) {\n\t\t\treturn strtolower(trim(\$m[1]));\n\t\t}\n\t\tif (str_contains(\$fromAddress, '@') && !str_contains(\$fromAddress, ' ')) {\n\t\t\treturn strtolower(\$fromAddress);\n\t\t}\n\t\tif (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i', \$fromAddress, \$m) === 1) {\n\t\t\treturn strtolower(trim(\$m[0]));\n\t\t}\n\n\t\treturn strtolower(\$fromAddress);\n\t}",
			'to' => "public static function normalizeFromAddress(string \$fromAddress): string\n\t{\n\t\t// mutated: no display-name / angle-bracket extraction\n\t\treturn strtolower(trim(\$fromAddress));\n\t}",
			'label' => 'from_header_angle_brackets_ignored',
			'filter' => 'MailMatchLogicTest::testExactPersonMatchFromDisplayNameHeader|ReviewKnownCustomerRematchContractTest::testDisplayNameFromHeadersNormalizeForExactMatch',
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

$baseline = 'ReviewMailSecurityHardeningContractTest|MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey|PersonRemoveReturnMailContractTest|ReviewSentAtLogicTest|ReviewDiscardBulkTest|ReviewMarkSpamBulkTest|ImapJunkFolderPickTest';
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
