<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: CC-shared-mailbox recipient matching safety.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-mail-match-cc-safety-mutations.php
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
 * @return list<array{file:string,from:string,to:string,label:string,filter:string,replace_all?:bool}>
 */
function mail_match_cc_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailMatchLogic.php',
			'from' => "\$recipientHit = self::matchRecipients(\n\t\t\t\$recipientAddresses,\n\t\t\t\$personsInWorkspace,\n\t\t\t\$excludeLookup,\n\t\t\t\$teamDomains,\n\t\t);\n\t\tif (\$recipientHit !== null) {\n\t\t\treturn \$recipientHit;\n\t\t}",
			'to' => "// mutated: skip recipient fallback\n\t\t",
			'label' => 'match_skips_recipient_fallback',
			'filter' => 'MailMatchLogicTest::testCcSharedMailboxFilesUniqueCustomerOnTo|SharedMailFixtureIntegrationTest::testCcYourselfDoesNotFanOutOrAutoAttachViaTeamFrom',
		],
		[
			'file' => $appRoot . '/lib/Service/MailMatchLogic.php',
			'from' => "if (count(\$exactCompanies) === 1) {\n\t\t\treturn ['companyId' => (int)array_key_first(\$exactCompanies), 'how' => 'recipient_email'];\n\t\t}\n\t\tif (count(\$exactCompanies) > 1) {\n\t\t\treturn ['companyId' => null, 'how' => 'ambiguous'];\n\t\t}",
			'to' => "if (count(\$exactCompanies) === 1) {\n\t\t\treturn ['companyId' => (int)array_key_first(\$exactCompanies), 'how' => 'recipient_email'];\n\t\t}\n\t\tif (count(\$exactCompanies) > 1) {\n\t\t\treturn ['companyId' => (int)array_key_first(\$exactCompanies), 'how' => 'recipient_email'];\n\t\t}",
			'label' => 'recipient_ambiguous_guesses_first',
			'filter' => 'MailMatchLogicTest::testTwoCustomerCompaniesOnToCcStayAmbiguous|SharedMailFixtureIntegrationTest::testTwoCustomersOnThreadStayInReview',
		],
		[
			'file' => $appRoot . '/lib/Service/MailMatchLogic.php',
			'from' => "if (count(\$exact) > 1) {\n\t\t\t\treturn ['companyId' => null, 'how' => 'ambiguous'];\n\t\t\t}",
			'to' => "if (count(\$exact) > 1) {\n\t\t\t\treturn ['companyId' => (int)array_key_first(\$exact), 'how' => 'person_email'];\n\t\t\t}",
			'label' => 'from_ambiguous_guesses_first',
			'filter' => 'MailMatchLogicTest::testAmbiguousPersonEmailAcrossCompaniesIsUnmatched',
		],
		[
			'file' => $appRoot . '/lib/Support/ImapLiveProbe.php',
			'from' => 'BODY.PEEK[HEADER.FIELDS (FROM TO CC REPLY-TO RESENT-FROM X-ORIGINAL-FROM SUBJECT DATE MESSAGE-ID)]',
			'to' => 'BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE MESSAGE-ID)]',
			'label' => 'fetch_drops_to_cc_headers',
			'filter' => 'MailMatchCcSafetyContractTest::testSyncParsesToAndCcIntoIngest',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'Write to the customer and CC the shared mailbox — or forward their mail to it. We file on From, a unique To/CC contact, or the original sender inside a forward — never onto every recipient.',
			'to' => 'Shared mailbox items are visible to everyone in this workspace with Review access.',
			'label' => 'review_drops_cc_filing_disclosure',
			'filter' => 'MailMatchCcSafetyContractTest::testGrannyCopyExplainsCcSharedMailboxFlow|ReviewInboxUxContractTest',
			'replace_all' => true,
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string,replace_all?:bool} $mut
 */
function apply_mutation(array $mut): string
{
	$src = (string)file_get_contents($mut['file']);
	if (!str_contains($src, $mut['from'])) {
		throw new RuntimeException('Mutation source not found for ' . $mut['label']);
	}
	$limit = !empty($mut['replace_all']) ? -1 : 1;
	$once = preg_replace('/' . preg_quote($mut['from'], '/') . '/', $mut['to'], $src, $limit, $count);
	if ($once === null || $count < 1 || (empty($mut['replace_all']) && $count !== 1)) {
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

$mutations = mail_match_cc_mutations($appRoot);
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
fwrite(STDOUT, "\nMail match CC safety mutations: {$killed}/{$total} killed, {$survived} survived.\n");

exit($survived > 0 ? 1 : 0);
