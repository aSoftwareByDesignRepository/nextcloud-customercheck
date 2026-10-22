<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Sales Inbox cursor paging (ACL tab fall-closed + cursor integrity).
 *
 * Host (from customercheck/):
 *   php tests/Mutation/run-inbox-paging-mutations.php
 *
 * Docker (from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-inbox-paging-mutations.php
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
 * @return list<array{file:string,from:string,to:string,label:string,kind:string,filter?:string,vitest?:string}>
 */
function inbox_paging_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailInboxPageLogic.php',
			'from' => "default => 'mine',",
			'to' => "default => 'team',",
			'label' => 'unknown_tab_widens_to_team',
			'kind' => 'phpunit',
			'filter' => 'MailInboxPageLogicTest::testNormalizeTabFallsClosedToMine',
		],
		[
			'file' => $appRoot . '/lib/Service/MailInboxPageLogic.php',
			'from' => "if (!self::isValidCursorSentAt(\$at)) {\n\t\t\treturn null;\n\t\t}",
			'to' => "if (false && !self::isValidCursorSentAt(\$at)) {\n\t\t\treturn null;\n\t\t}",
			'label' => 'decode_accepts_malformed_sentAt',
			'kind' => 'phpunit',
			'filter' => 'MailInboxPageLogicTest::testDecodeRejectsJunkAndMalformedTimestamps',
		],
		[
			'file' => $appRoot . '/lib/Service/MailInboxPageLogic.php',
			'from' => "\$hasMore = count(\$accepted) > \$limit;",
			'to' => "\$hasMore = count(\$accepted) < \$limit;",
			'label' => 'pageResult_hasMore_inverted',
			'kind' => 'phpunit',
			'filter' => 'MailInboxPageLogicTest::testPageResultHasMoreAndCursor',
		],
		[
			'file' => $appRoot . '/lib/Service/MailInboxPageLogic.php',
			'from' => "public static function encodeCursor(?string \$sentAt, int \$id): ?string\n\t{\n\t\tif (\$id <= 0) {\n\t\t\treturn null;\n\t\t}",
			'to' => "public static function encodeCursor(?string \$sentAt, int \$id): ?string\n\t{\n\t\tif (\$id < 0) {\n\t\t\treturn null;\n\t\t}",
			'label' => 'encode_allows_zero_id',
			'kind' => 'phpunit',
			'filter' => 'MailInboxPageLogicTest::testPageResultHasMoreFalseWhenLastIdInvalid',
		],
		[
			'file' => $appRoot . '/lib/Service/PersonalMailService.php',
			'from' => "\$tab = MailInboxPageLogic::normalizeTab(\$tab);",
			'to' => "\$tab = strtolower(trim(\$tab));",
			'label' => 'listInbox_skips_normalizeTab',
			'kind' => 'phpunit',
			'filter' => 'MailInboxPagingContractTest::testListInboxNormalizesTabAndUsesMultiPass',
		],
		[
			'file' => $appRoot . '/js/common/inbox-page-core.js',
			'from' => "if (!(id > 0) || seen[id]) return;",
			'to' => "if (!(id > 0)) return;",
			'label' => 'js_merge_allows_duplicates',
			'kind' => 'vitest',
			'vitest' => 'tests/js/inbox-page-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/inbox-page-core.js',
			'from' => "hasMore: !!hasMore && !!cursor,",
			'to' => "hasMore: !!hasMore,",
			'label' => 'js_hasMore_without_cursor',
			'kind' => 'vitest',
			'vitest' => 'tests/js/inbox-page-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/inbox-page-core.js',
			'from' => "if (tab === 'unmatched') return false;",
			'to' => "if (false && tab === 'unmatched') return false;",
			'label' => 'js_load_more_on_unmatched',
			'kind' => 'vitest',
			'vitest' => 'tests/js/inbox-page-core.test.js',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,kind:string,filter?:string,vitest?:string} $m
 */
function apply_mutation(array $m): string
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('Needle not found for ' . $m['label'] . ' in ' . $m['file']);
	}
	$mutated = str_replace($m['from'], $m['to'], $src, $count);
	if ($count !== 1) {
		throw new RuntimeException('Expected 1 replace for ' . $m['label'] . ', got ' . $count);
	}
	// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
	// a kill-test that is red unmutated turns nonzero exits into fake kills.
	require_once __DIR__ . '/MutationBaseline.php';
	mutationBaselineSelfCheck(__FILE__);
	file_put_contents($m['file'], $mutated);

	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

/**
 * @param array{file:string,from:string,to:string,label:string,kind:string,filter?:string,vitest?:string} $m
 */
function run_kill_check(string $appRoot, string $phpunit, array $m): bool
{
	if ($m['kind'] === 'vitest') {
		$cmd = 'npm run test:js -- --run ' . escapeshellarg((string)$m['vitest']) . ' 2>&1';
		$cwd = $appRoot;
	} else {
		$filter = (string)($m['filter'] ?? 'MailInboxPageLogicTest');
		$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
			. ' --filter ' . escapeshellarg($filter) . ' 2>&1';
		$cwd = $appRoot;
	}
	$des = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
	$proc = proc_open($cmd, $des, $pipes, $cwd);
	if (!is_resource($proc)) {
		return false;
	}
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($proc);
	// Mutation is killed when the focused suite FAILS.
	return $code !== 0;
}

$mutations = inbox_paging_mutations($appRoot);
$failed = [];
$killed = 0;

echo "Inbox paging mutation gauntlet (" . count($mutations) . " mutants)\n";

foreach ($mutations as $m) {
	$label = $m['label'];
	echo "— {$label} … ";
	$original = null;
	try {
		$original = apply_mutation($m);
		$dead = run_kill_check($appRoot, $phpunit, $m);
		restore_file($m['file'], $original);
		if ($dead) {
			echo "KILLED\n";
			$killed++;
		} else {
			echo "SURVIVED\n";
			$failed[] = $label;
		}
	} catch (Throwable $e) {
		if ($original !== null) {
			restore_file($m['file'], $original);
		}
		echo "ERROR: " . $e->getMessage() . "\n";
		$failed[] = $label . ' (error)';
	}
}

echo "\nKilled {$killed}/" . count($mutations) . "\n";
if ($failed !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $failed) . "\n");
	exit(1);
}

echo "Inbox paging mutation gauntlet OK.\n";
exit(0);
