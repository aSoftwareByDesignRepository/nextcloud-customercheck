<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: duplicate-UID To/CC backfill + Review promotion drain.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-mail-ingest-refresh-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';

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

/** @return list<array{file:string,from:string,to:string,label:string,filter:string}> */
function mail_ingest_refresh_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailboxService.php',
			'from' => "\t\t} catch (\\Throwable) {\n\t\t\t// Unique (mailbox, folder, uid) — already indexed. Older rows often lack\n\t\t\t// To/CC (pre-recipient matching). Refresh open rows so Sync now can\n\t\t\t// file CC’d / team-sent copies onto customers and into Review.\n\t\t\t\$refreshed = \$this->refreshOpenIndexedMessage(",
			'to' => "\t\t} catch (\\Throwable) {\n\t\t\t// mutated: swallow duplicate uid\n\t\t\treturn array_merge(\$match, ['inserted' => false, 'touched' => false]);\n\t\t\t// \$refreshed = \$this->refreshOpenIndexedMessage(",
			'label' => 'ingest_swallows_duplicate_uid',
			'filter' => 'MailboxServiceIngestRefreshTest|ReviewKnownCustomerRematchContractTest',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxService.php',
			'from' => "\t\tif ((string)(\$row['triage_status'] ?? '') !== 'open') {\n\t\t\treturn array_merge(\$match, ['touched' => false]);\n\t\t}",
			'to' => "\t\t// mutated: refresh matched rows too\n\t\t",
			'label' => 'refresh_overwrites_matched_rows',
			'filter' => 'MailboxServiceIngestRefreshTest::testDuplicateUidSkipsRefreshWhenAlreadyMatched',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxService.php',
			'from' => "\t\t\t\$upd->where(\$upd->expr()->eq('id', \$upd->createNamedParameter(\$mailId, IQueryBuilder::PARAM_INT)))\n\t\t\t\t->andWhere(\$upd->expr()->eq('workspace_id', \$upd->createNamedParameter(\$workspaceId, IQueryBuilder::PARAM_INT)))\n\t\t\t\t->andWhere(\$upd->expr()->eq('triage_status', \$upd->createNamedParameter('open')))\n\t\t\t\t->executeStatement();\n\t\t}\n\n\t\t\$companyId = isset(\$match['companyId']) ? (int)\$match['companyId'] : 0;",
			'to' => "\t\t\t\$upd->where(\$upd->expr()->eq('id', \$upd->createNamedParameter(\$mailId, IQueryBuilder::PARAM_INT)))\n\t\t\t\t->andWhere(\$upd->expr()->eq('workspace_id', \$upd->createNamedParameter(\$workspaceId, IQueryBuilder::PARAM_INT)))\n\t\t\t\t// mutated: no triage guard on backfill\n\t\t\t\t->executeStatement();\n\t\t}\n\n\t\t\$companyId = isset(\$match['companyId']) ? (int)\$match['companyId'] : 0;",
			'label' => 'backfill_without_open_guard',
			'filter' => 'MailboxServiceIngestRefreshTest|ReviewKnownCustomerRematchContractTest::testTriageAssignAcceptsMatchedHow',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "\$batch = \$this->mailbox->unmatchedPage(\$workspaceId, \$afterId, \$pageSize, false);",
			'to' => "\$batch = \$this->mailbox->unmatched(\$workspaceId, \$pageSize);",
			'label' => 'review_promotion_reverts_to_sent_desc_cap',
			'filter' => 'ReviewKnownCustomerRematchContractTest::testSyncSharedMailRematchesBeforeEnqueue',
		],
		[
			'file' => $appRoot . '/lib/Service/ReviewService.php',
			'from' => "\t\t\t\tif (\$added >= \$maxPromote) {\n\t\t\t\t\treturn \$added;\n\t\t\t\t}",
			'to' => "\t\t\t\t// mutated: no promotion ceiling\n\t\t\t\t",
			'label' => 'review_promotion_drops_max_ceiling',
			'filter' => 'ReviewKnownCustomerRematchContractTest::testSyncSharedMailRematchesBeforeEnqueue',
		],
		[
			'file' => $appRoot . '/lib/Constants.php',
			'from' => "\tpublic const REVIEW_SHARED_MAIL_SYNC_PAGE = 200;",
			'to' => "\tpublic const REVIEW_SHARED_MAIL_SYNC_PAGE = 50;",
			'label' => 'review_page_size_shrunk',
			'filter' => 'ReviewKnownCustomerRematchContractTest::testSyncSharedMailRematchesBeforeEnqueue',
		],
	];
}

$mutations = mail_ingest_refresh_mutations($appRoot);
$failed = 0;
foreach ($mutations as $m) {
	$file = $m['file'];
	$original = (string)file_get_contents($file);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP {$m['label']}: anchor not found in {$file}\n");
		$failed++;
		continue;
	}
	$mutated = str_replace($m['from'], $m['to'], $original, $count);
	if ($count !== 1) {
		fwrite(STDERR, "SKIP {$m['label']}: expected exactly one replacement, got {$count}\n");
		$failed++;
		continue;
	}
	file_put_contents($file, $mutated);
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($m['filter']);
	$out = [];
	$code = 0;
	exec($cmd . ' 2>&1', $out, $code);
	file_put_contents($file, $original);
	if ($code === 0) {
		fwrite(STDERR, "FAIL {$m['label']}: tests still passed after mutation (gauntlet breach)\n");
		$failed++;
		continue;
	}
	echo "OK {$m['label']}\n";
}

if ($failed > 0) {
	fwrite(STDERR, "Mail ingest refresh mutation gauntlet: {$failed} failure(s)\n");
	exit(1);
}

echo "Mail ingest refresh mutation gauntlet: all mutations killed by tests.\n";
exit(0);
