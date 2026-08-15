<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: forwarded-mail original-From recovery.
 *
 * Usage:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-mail-forward-match-mutations.php
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
function mail_forward_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailForwardLogic.php',
			'from' => '$outlook = self::hintsFromOutlookBlocks($text, $excludeEmails);
		if ($outlook !== []) {
			return $outlook;
		}',
			'to' => '// mutated: skip outlook blocks
		$outlook = [];',
			'label' => 'body_hints_skip_outlook_blocks',
			'filter' => 'MailForwardLogicTest::testOutlookForwardBlockFindsCustomerFrom|MailForwardLogicTest::testOutlookBlockUsesAnWhenVonIsTeam|MailForwardLogicTest::testOutlookVonAnWithoutGesendetLine',
		],
		[
			'file' => $appRoot . '/lib/Service/MailForwardLogic.php',
			'from' => 'if ($fromEmail !== \'\' && str_contains($fromEmail, \'@\') && !isset($exclude[$fromEmail])) {
			return $fromEmail;
		}',
			'to' => 'if ($fromEmail !== \'\' && !isset($exclude[$fromEmail])) {
			return $fromEmail;
		}',
			'label' => 'outlook_accepts_display_name_from',
			'filter' => 'MailForwardLogicTest::testThunderbirdForwardBlockFindsEmailInSignatureWhenFromHasNoAddress|MailForwardLogicTest::testCustomerEmailForReviewSuggestUsesForwardHintNotTeamEnvelope',
		],
		[
			'file' => $appRoot . '/lib/Service/MailForwardLogic.php',
			'from' => 'if ($fromEmail !== \'\' && isset($exclude[$fromEmail]) && $toEmail !== \'\' && str_contains($toEmail, \'@\') && !isset($exclude[$toEmail])) {
			return $toEmail;
		}',
			'to' => '// mutated: skip An fallback when Von is team',
			'label' => 'outlook_skips_an_when_von_team',
			'filter' => 'MailForwardLogicTest::testOutlookBlockUsesAnWhenVonIsTeam|MailForwardLogicTest::testOutlookVonAnTeamOnVonUsesCustomerOnAnWithoutGesendet',
		],
		[
			'file' => $appRoot . '/lib/Service/MailForwardLogic.php',
			'from' => 'public static function hintsForRematch(?string $storedHintsJson, ?string $preview, array $excludeEmails = []): array
	{
		$raw = trim((string)$storedHintsJson);',
			'to' => 'public static function hintsForRematch(?string $storedHintsJson, ?string $preview, array $excludeEmails = []): array
	{
		return [];
		$raw = trim((string)$storedHintsJson);',
			'label' => 'rematch_hints_always_empty',
			'filter' => 'MailForwardLogicTest::testHintsForRematchUsesPreviewWhenStoredHintsEmpty|MailForwardLogicTest::testHintsForRematchPrefersStoredHintsOverPreview',
		],
		[
			'file' => $appRoot . '/lib/Service/MailForwardLogic.php',
			'from' => 'foreach ($matches[1] as $line) {
			$email = ImapLiveProbe::extractEmailAddress((string)$line);
			if ($email === \'\' || !str_contains($email, \'@\') || isset($exclude[$email])) {
				continue;
			}

			return [$email];
		}',
			'to' => 'foreach ($matches[1] as $line) {
			$email = ImapLiveProbe::extractEmailAddress((string)$line);
			if ($email === \'\' || !str_contains($email, \'@\')) {
				continue;
			}
			return [$email];
		}',
			'label' => 'marker_path_accepts_team_from',
			'filter' => 'MailForwardLogicTest::testForwardMarkerSkipsTeamFromWhenOutlookBlocksDoNotApply',
		],
		[
			'file' => $appRoot . '/lib/Service/MailMatchLogic.php',
			'from' => "if (\$originalFromHints !== []) {\n\t\t\t\$forwardHit = self::matchOriginalFromHints(\n\t\t\t\t\$originalFromHints,\n\t\t\t\t\$personsInWorkspace,\n\t\t\t\t\$excludeLookup,\n\t\t\t\t\$teamDomains,\n\t\t\t);\n\t\t\tif (\$forwardHit !== null) {\n\t\t\t\treturn \$forwardHit;\n\t\t\t}\n\t\t}",
			'to' => "// mutated: skip forward hints\n\t\t",
			'label' => 'match_skips_forward_hints',
			'filter' => 'MailMatchLogicTest::testForwardedMailMatchesOriginalFromHint|MailMatchLogicTest::testTeamForwarderPersonContactDoesNotBeatForwardHints|SharedMailFixtureIntegrationTest::testForwardToSharedInboxFilesOriginalSender',
		],
		[
			'file' => $appRoot . '/lib/Service/MailMatchLogic.php',
			'from' => "if (count(\$exactCompanies) === 1) {\n\t\t\treturn ['companyId' => (int)array_key_first(\$exactCompanies), 'how' => 'forward_from'];\n\t\t}\n\t\tif (count(\$exactCompanies) > 1) {\n\t\t\treturn ['companyId' => null, 'how' => 'ambiguous'];\n\t\t}",
			'to' => "if (count(\$exactCompanies) === 1) {\n\t\t\treturn ['companyId' => (int)array_key_first(\$exactCompanies), 'how' => 'forward_from'];\n\t\t}\n\t\tif (count(\$exactCompanies) > 1) {\n\t\t\treturn ['companyId' => (int)array_key_first(\$exactCompanies), 'how' => 'forward_from'];\n\t\t}",
			'label' => 'forward_ambiguous_guesses_first',
			'filter' => 'MailMatchLogicTest::testTwoOriginalFromHintsStayAmbiguous',
		],
		[
			'file' => $appRoot . '/lib/Service/MailForwardLogic.php',
			'from' => 'if ($fromEmail !== \'\' && str_contains($fromEmail, \'@\') && !isset($exclude[$fromEmail])) {
			return $fromEmail;
		}',
			'to' => 'if ($fromEmail !== \'\') {
			return $fromEmail;
		}',
			'label' => 'body_hint_allows_team_from',
			'filter' => 'MailForwardLogicTest::testBodyHintDropsTeamOnlyOutlookBlock',
		],
		[
			'file' => $appRoot . '/lib/Service/MailForwardLogic.php',
			'from' => 'public static function hintsFromRedirectHeaders(?string $xOriginalFrom, ?string $replyTo): array
	{
		$out = [];',
			'to' => 'public static function hintsFromRedirectHeaders(?string $xOriginalFrom, ?string $replyTo): array
	{
		return [];
		$out = [];',
			'label' => 'redirect_hints_always_empty',
			'filter' => 'MailForwardLogicTest::testThunderbirdRedirectReplyToFilesCustomer|SharedMailFixtureIntegrationTest::testRedirectViaReplyToFilesOriginalSender',
		],
		[
			'file' => $appRoot . '/lib/Support/ImapLiveProbe.php',
			'from' => 'BODY.PEEK[HEADER.FIELDS (FROM TO CC REPLY-TO RESENT-FROM X-ORIGINAL-FROM SUBJECT DATE MESSAGE-ID)]',
			'to' => 'BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID)]',
			'label' => 'fetch_drops_resent_headers',
			'filter' => 'MailForwardMatchContractTest::testForwardLogicAndMatchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'Write to the customer and CC the shared mailbox — or forward their mail to it. We file on From, a unique To/CC contact, or the original sender inside a forward — never onto every recipient.',
			'to' => 'Shared mailbox items are visible to everyone in this workspace with Review access.',
			'label' => 'review_drops_forward_disclosure',
			'filter' => 'MailForwardMatchContractTest::testGrannyCopyMentionsForward|ReviewInboxUxContractTest',
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

$mutations = mail_forward_mutations($appRoot);
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
fwrite(STDOUT, "\nMail forward match mutations: {$killed}/{$total} killed, {$survived} survived.\n");

exit($survived > 0 ? 1 : 0);
