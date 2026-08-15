<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: GDPR erase IN()-clause must use PARAM_*_ARRAY (not named-token lists).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-gdpr-erase-in-clause-mutations.php
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
function gdpr_erase_in_clause_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/CompanyService.php',
			'from' => "->andWhere(\$qb->expr()->in(\n\t\t\t\t'entity_id',\n\t\t\t\t\$qb->createNamedParameter(\$entityIds, IQueryBuilder::PARAM_INT_ARRAY),\n\t\t\t))",
			'to' => "->andWhere(\$qb->expr()->in('entity_id', array_map(static fn (int \$eid, int \$i) => \$qb->createNamedParameter(\$eid, IQueryBuilder::PARAM_INT, 'ceid' . \$i), \$entityIds, array_keys(\$entityIds))))",
			'label' => 'cancel_open_conflicts_reverts_to_named_token_list',
			'filter' => 'GdprEraseInClauseContractTest::testCancelOpenConflictsUsesIntArrayParameter',
		],
		[
			'file' => $appRoot . '/lib/Service/CompanyService.php',
			'from' => "->where(\$qb->expr()->in(\n\t\t\t\t\t'plan_id',\n\t\t\t\t\t\$qb->createNamedParameter(\$planIds, IQueryBuilder::PARAM_INT_ARRAY),\n\t\t\t\t))",
			'to' => "->where(\$qb->expr()->in('plan_id', array_map(static fn (\$pid, \$i) => \$qb->createNamedParameter(\$pid, null, 'pid' . \$i), \$planIds, array_keys(\$planIds))))",
			'label' => 'map_step_delete_reverts_to_named_token_list',
			'filter' => 'GdprEraseInClauseContractTest::testErasePersonaPlanDeletesUseIntArrayParameter',
		],
		[
			'file' => $appRoot . '/lib/Service/CoachingService.php',
			'from' => 'PARAM_INT_ARRAY',
			'to' => 'PARAM_STR',
			'label' => 'coaching_filters_lose_int_array',
			'filter' => 'GdprEraseInClauseContractTest::testCoachingBulkFiltersUseArrayParameters',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $m
 */
function apply_mutation(array $m): string
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('Mutation anchor missing for ' . $m['label']);
	}
	$next = str_replace($m['from'], $m['to'], $src);
	if ($next === $src) {
		throw new RuntimeException('Mutation did not change file for ' . $m['label']);
	}
	file_put_contents($m['file'], $next);
	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = ['php', $phpunit, '-c', $appRoot . '/phpunit.xml', '--filter', $filter];
	$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $appRoot);
	if (!is_resource($proc)) {
		throw new RuntimeException('Failed to start phpunit');
	}
	fclose($pipes[0]);
	stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return proc_close($proc);
}

$failed = 0;
$caught = 0;
foreach (gdpr_erase_in_clause_mutations($appRoot) as $m) {
	$original = null;
	try {
		$original = apply_mutation($m);
		$code = run_phpunit($phpunit, $appRoot, $m['filter']);
		if ($code === 0) {
			fwrite(STDERR, "MUTATION SURVIVED: {$m['label']}\n");
			$failed++;
		} else {
			fwrite(STDOUT, "caught: {$m['label']}\n");
			$caught++;
		}
	} catch (Throwable $e) {
		fwrite(STDERR, 'ERROR ' . $m['label'] . ': ' . $e->getMessage() . "\n");
		$failed++;
	} finally {
		if ($original !== null) {
			restore_file($m['file'], $original);
		}
	}
}

fwrite(STDOUT, "GDPR erase IN-clause mutations: caught={$caught} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
