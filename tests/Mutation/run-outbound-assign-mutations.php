<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: CRM outbound must not break Assign discard / spam / unmatched promote.
 *
 * Run: php tests/Mutation/run-outbound-assign-mutations.php
 * Prefer: docker compose exec -u www-data -w /var/www/html/custom_apps/customercheck nextcloud php tests/Mutation/run-outbound-assign-mutations.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

$cases = [
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "if (\$mailboxId <= 0 || !ImapConnection::isLiveUid(\$uidRaw)) {\n\t\t\treturn false;\n\t\t}",
		'replace' => "if (\$mailboxId <= 0 || !ImapConnection::isLiveUid('BROKEN' . \$uidRaw)) {\n\t\t\treturn false;\n\t\t}",
		'test' => 'Service\\MailboxSyncServiceTest::testOutboundUidDeleteAndSpamAreCrmOnly',
		'label' => 'isLiveUid CRM-only gate broken on delete/spam',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "if (\$mailboxId <= 0 || !ImapConnection::isLiveUid(\$uidRaw)) {\n\t\t\treturn false;\n\t\t}",
		'replace' => "if (\$mailboxId <= 0 || !ImapConnection::isLiveUid(\$uidRaw)) {\n\t\t\tthrow new ValidationException(Constants::ERR_VALIDATION, 'missing folder or id');\n\t\t}",
		'test' => 'Service\\MailboxSyncServiceTest::testOutboundUidDeleteAndSpamAreCrmOnly',
		'label' => 'outbound throws missing folder or id again',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "if (\$owner === null || !hash_equals(\$owner, \$actor)) {\n\t\t\tthrow new ValidationException(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\$this->l10n->t('Only the mailbox owner can delete or mark spam on personal mail.'),\n\t\t\t);\n\t\t}",
		'replace' => "if (false && (\$owner === null || !hash_equals(\$owner, \$actor))) {\n\t\t\tthrow new ValidationException(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\$this->l10n->t('Only the mailbox owner can delete or mark spam on personal mail.'),\n\t\t\t);\n\t\t}",
		'test' => 'Service\\PersonalMailHardeningContractTest::testDeleteIndexedMessageRefusesPersonalMailbox',
		'label' => 'personal owner hash_equals gate disabled',
	],
	[
		'file' => $root . '/lib/Support/ReviewSharedMailTriage.php',
		'find' => "if (self::isCrmOutboundNotForAssign(\$mailRow)) {\n\t\t\treturn 'dead';\n\t\t}",
		'replace' => "if (false && self::isCrmOutboundNotForAssign(\$mailRow)) {\n\t\t\treturn 'dead';\n\t\t}",
		'test' => 'Support\\ReviewSharedMailTriageTest::testCrmOutboundNeverStaysInAssign',
		'label' => 'outbound Assign auto-close disabled',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "->andWhere(\$qb->expr()->neq('m.direction', \$qb->createNamedParameter('outbound')))",
		'replace' => "->andWhere(\$qb->expr()->neq('m.direction', \$qb->createNamedParameter('inbound')))",
		'test' => 'ReviewInboxUxContractTest::testReviewDiscardAndBulkMultiselectWired',
		'label' => 'unmatchedPage excludes inbound instead of outbound',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "// Never open Assign/Unassigned for CRM-sent copies (synthetic outbound: uid; not IMAP-deletable).\n\t\t\t\t'triage_status' => \$ins->createNamedParameter('matched'),",
		'replace' => "// mutated: reopen Assign for outbound\n\t\t\t\t'triage_status' => \$ins->createNamedParameter(\$companyId ? 'matched' : 'open'),",
		'test' => 'ReviewInboxUxContractTest::testReviewDiscardAndBulkMultiselectWired',
		'label' => 'outbound insert reopens Assign without company',
	],
];

$failed = 0;
foreach ($cases as $case) {
	$original = (string)file_get_contents($case['file']);
	if (!str_contains($original, $case['find'])) {
		fwrite(STDERR, "SKIP (needle missing): {$case['label']}\n");
		$failed++;
		continue;
	}
	$count = 0;
	$mutated = str_replace($case['find'], $case['replace'], $original, $count);
	if ($count < 1) {
		fwrite(STDERR, "SKIP (no replace): {$case['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($case['file'], $mutated);
	$cmd = 'cd ' . escapeshellarg($root)
		. ' && ./vendor/bin/phpunit --configuration phpunit.xml '
		. escapeshellarg($case['test'])
		. ' 2>&1';
	$out = [];
	exec($cmd, $out, $code);
	file_put_contents($case['file'], $original);
	$killed = $code !== 0;
	fwrite(STDOUT, ($killed ? 'KILLED' : 'SURVIVED') . ": {$case['label']}\n");
	if (!$killed) {
		$failed++;
		fwrite(STDERR, implode("\n", array_slice($out, -25)) . "\n");
	}
}

if ($failed > 0) {
	fwrite(STDERR, "Outbound assign mutations: {$failed} problem(s)\n");
	exit(1);
}
fwrite(STDOUT, "Outbound assign mutations: all killed\n");
exit(0);
