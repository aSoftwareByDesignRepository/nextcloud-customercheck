<?php

declare(strict_types=1);

/**
 * Mutation tests for Talk/Review Answer → in-app send hardening.
 *
 * Run: php tests/Mutation/run-talk-answer-send-mutations.php
 * Prefer Docker: docker compose exec -u www-data nextcloud …
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

$cases = [
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "\$pack['companyId'] = \$companyId;",
		'replace' => '// mutated: drop companyId',
		'test' => 'TalkAnswerSendContractTest::testReplyPackExposesMailboxCompanyAndPrivacyMeta',
		'label' => 'reply pack drops companyId',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "\$pack['visibility'] = (string)\$msg['visibility'];",
		'replace' => '// mutated: drop visibility',
		'test' => 'TalkAnswerSendContractTest::testReplyPackExposesMailboxCompanyAndPrivacyMeta',
		'label' => 'reply pack drops visibility',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "\$disclosure = \$this->mailboxes->disclosure((string)\$box['label'], \$workspaceName, \$boxKind);",
		'replace' => "\$disclosure = \$this->mailboxes->disclosure((string)\$box['label'], \$workspaceName);",
		'test' => 'TalkAnswerSendContractTest::testPersonalSendUsesPersonalDisclosure',
		'label' => 'send loses kind-aware disclosure',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "if (\$kind === MailAclLogic::KIND_PERSONAL) {\n\t\t\treturn \$this->l10n->t(\n\t\t\t\t'Sent from your personal email %1\$s (connected in workspace %2\$s).',\n\t\t\t\t[\$mailboxLabel, \$workspaceName]\n\t\t\t);\n\t\t}",
		'replace' => '',
		'test' => 'TalkAnswerSendContractTest::testDisclosureCopyDiffersByKind|TalkAnswerSendContractTest::testPersonalSendUsesPersonalDisclosure',
		'label' => 'personal disclosure branch removed',
	],
	[
		'file' => $root . '/js/common/shared-mail-send.js',
		'find' => 'function queryInDialog(dialogId, selector) {',
		'replace' => 'function queryDialogScoped(dialogId, selector) {',
		'test' => 'TalkAnswerSendContractTest::testSharedMailOfferPreparedMailHardened',
		'label' => 'queryInDialog renamed away',
	],
	[
		'file' => $root . '/js/common/shared-mail-send.js',
		'find' => 'C.closeModal(dialogRoot(dialogId));',
		'replace' => '/* mutated: leave dialog open */',
		'test' => 'TalkAnswerSendContractTest::testSharedMailOfferPreparedMailHardened',
		'label' => 'dialog stays open after send',
	],
	[
		'file' => $root . '/js/common/shared-mail-send.js',
		'find' => 'This reply quotes private personal mail. Team send makes that quote visible to colleagues who can read mail.',
		'replace' => 'mutated: no private elevation warning',
		'test' => 'TalkAnswerSendContractTest::testSharedMailOfferPreparedMailHardened',
		'label' => 'private elevation warning removed',
	],
	[
		'file' => $root . '/js/common/shared-mail-send.js',
		'find' => "const sendBtn = queryInDialog(dialogId, '[data-crm-shared-send]');",
		'replace' => "const sendBtn = document.querySelector('[data-crm-shared-send]');",
		'test' => 'TalkAnswerSendContractTest::testSharedMailOfferPreparedMailHardened',
		'label' => 'send button uses global selector',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "wrap.getAttribute('data-company-id')",
		'replace' => "wrap.getAttribute('data-company-id-missing')",
		'test' => 'TalkAnswerSendContractTest::testTalkAndReviewAnswerWireOfferPreparedMail',
		'label' => 'Review loses data-company-id read',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => 'replyMode: true',
		'replace' => 'replyMode: false',
		'test' => 'TalkAnswerSendContractTest::testTalkAndReviewAnswerWireOfferPreparedMail',
		'label' => 'Talk Answer drops replyMode',
	],
	[
		'file' => $root . '/lib/Controller/PersonaApiController.php',
		'find' => 'Answer helper: reply compose pack for mailto / Nextcloud Mail / in-app send.',
		'replace' => 'Answer helper: mailto / Nextcloud Mail compose presets (no send from CRM).',
		'test' => 'TalkAnswerSendContractTest::testReplyMailControllerDocAllowsInAppSend',
		'label' => 'controller doc reverts to mailto-only',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$config = $root . '/phpunit.xml';
$failed = 0;
$killed = 0;
$pending = [];
$restoreAll = static function () use (&$pending): void {
	foreach ($pending as $path => $contents) {
		// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
		// a kill-test that is red unmutated turns nonzero exits into fake kills.
		require_once __DIR__ . '/MutationBaseline.php';
		mutationBaselineSelfCheck(__FILE__);
		file_put_contents($path, $contents);
	}
	$pending = [];
};
register_shutdown_function($restoreAll);

foreach ($cases as $case) {
	$original = (string)file_get_contents($case['file']);
	if (!str_contains($original, $case['find'])) {
		fwrite(STDERR, "SKIP missing needle: {$case['label']}\n");
		$failed++;
		continue;
	}
	$pending[$case['file']] = $original;
	$code = 1;
	$out = [];
	try {
		file_put_contents($case['file'], str_replace($case['find'], $case['replace'], $original));
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' --configuration ' . escapeshellarg($config)
			. ' --filter ' . escapeshellarg($case['test']);
		exec($cmd . ' 2>&1', $out, $code);
	} finally {
		file_put_contents($case['file'], $pending[$case['file']]);
		unset($pending[$case['file']]);
	}
	if ($code !== 0) {
		echo "KILLED  {$case['label']}\n";
		$killed++;
	} else {
		echo "SURVIVED {$case['label']}\n";
		$failed++;
	}
}

echo "\nTalk Answer send mutations: killed={$killed} survived/skipped={$failed} total=" . count($cases) . "\n";
exit($failed > 0 ? 1 : 0);
