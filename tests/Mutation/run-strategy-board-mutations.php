<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Strategy board UX + account-plan status fail-closed.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-strategy-board-mutations.php
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
function strategy_board_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/templates/strategy.php',
			'from' => 'id="crm-strategy-board"',
			'to' => 'id="crm-strategy-board-broken"',
			'label' => 'strategy_loses_board_card_id',
			'filter' => 'StrategyBoardUxContractTest::testStrategyTemplateUsesCardShellAndFilterRoot',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'strategyLoadGeneration',
			'to' => 'strategyPaintEpoch',
			'label' => 'strategy_loses_load_generation_guard',
			'filter' => 'StrategyBoardUxContractTest::testPersonaStrategyRendersTableFiltersAndAccessibleEdit',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'crm-table--strategy',
			'to' => 'crm-table--plans-board',
			'label' => 'strategy_loses_responsive_table',
			'filter' => 'StrategyBoardUxContractTest::testPersonaStrategyRendersTableFiltersAndAccessibleEdit',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'crmPlanSnapshot',
			'to' => 'crmPlanBackupHtml',
			'label' => 'strategy_edit_cancel_loses_restore',
			'filter' => 'StrategyBoardUxContractTest::testPersonaStrategyRendersTableFiltersAndAccessibleEdit',
		],
		[
			'file' => $appRoot . '/lib/Service/AccountPlanService.php',
			'from' => "if (!in_array(\$status, ['active', 'paused', 'done'], true)) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'status',\n\t\t\t\t\$this->l10n->t('Unknown plan status.'),\n\t\t\t);\n\t\t}",
			'to' => "// mutated: accept any plan status",
			'label' => 'account_plan_accepts_arbitrary_status',
			'filter' => 'AccountPlanStatusValidationTest|StrategyBoardUxContractTest::testAccountPlanHydrateExposesBoardLabelsAndStatusGate',
		],
		[
			'file' => $appRoot . '/lib/Service/AccountPlanService.php',
			'from' => "'priorityLabel' => \$this->labels->planPriority(\$priority),",
			'to' => "// mutated: drop priorityLabel\n\t\t\t'priorityLabelX' => \$this->labels->planPriority(\$priority),",
			'label' => 'hydrate_drops_priority_label',
			'filter' => 'StrategyBoardUxContractTest::testAccountPlanHydrateExposesBoardLabelsAndStatusGate',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '.crm-strategy-edit-row',
			'to' => '.crm-strategy-editor-row',
			'label' => 'strategy_css_loses_edit_row',
			'filter' => 'StrategyBoardUxContractTest::testStrategyCssSupportsEditRowAndTouchTargets',
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
		throw new RuntimeException('Mutation anchor missing for ' . $m['label'] . ' in ' . $m['file']);
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
	$cmd = [
		'php',
		$phpunit,
		'-c',
		$appRoot . '/phpunit.xml',
		'--filter',
		$filter,
	];
	$proc = proc_open(
		$cmd,
		[
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		],
		$pipes,
		$appRoot,
	);
	if (!is_resource($proc)) {
		throw new RuntimeException('Failed to start phpunit');
	}
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]) ?: '';
	$stderr = stream_get_contents($pipes[2]) ?: '';
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($proc);
	if ($code === 0) {
		fwrite(STDERR, $stdout . $stderr);
	}
	return $code;
}

$failed = 0;
$caught = 0;
foreach (strategy_board_mutations($appRoot) as $m) {
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

fwrite(STDOUT, "Strategy board mutations: caught={$caught} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
