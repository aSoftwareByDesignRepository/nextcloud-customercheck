<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: account-plan save date/CAS hardening.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -T -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-account-plan-save-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'AccountPlanStatusValidationTest';

function account_plan_save_phpunit_command(string $phpunit, string $config, string $filter): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	$composeDir = dirname($appRoot, 2);
	$dockerPhpunit = '/var/www/html/custom_apps/customercheck/vendor/bin/phpunit';
	$dockerConfig = '/var/www/html/custom_apps/customercheck/phpunit.xml';
	if (is_file($composeDir . '/docker-compose.yml')) {
		$probe = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";');
		exec($probe . ' 2>/dev/null', $probeOut, $probeCode);
		if ($probeCode === 0) {
			return 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
				. ' exec -T -u www-data nextcloud php ' . escapeshellarg($dockerPhpunit)
				. ' --configuration ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter)
				. ' --cache-result-file=/tmp/cc-phpunit-account-plan-save.cache';
		}
	}
	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function account_plan_save_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/AccountPlanService.php',
			'from' => "if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})/', \$raw, \$m) === 1) {\n\t\t\treturn DateMath::parseStrictDate(\$m[1]) !== null ? \$m[1] : null;\n\t\t}",
			'to' => "if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})/', \$raw, \$m) === 1) {\n\t\t\treturn \$m[1];\n\t\t}",
			'label' => 'accepts_impossible_iso_prefix',
		],
		[
			'file' => $appRoot . '/lib/Service/AccountPlanService.php',
			'from' => "return DateMath::parseStrictDate(\$raw)?->format('Y-m-d');",
			'to' => 'return $raw; // allow-european',
			'label' => 'accepts_european_review_date',
		],
		[
			'file' => $appRoot . '/lib/Service/AccountPlanService.php',
			'from' => "\$this->l10n->t('Use YYYY-MM-DD.'),",
			'to' => "\$this->l10n->t('Use any date.'),",
			'label' => 'drops_iso_date_validation_message',
		],
		[
			'file' => $appRoot . '/lib/Service/AccountPlanService.php',
			'from' => 'if ($expectedSql !== null && $expectedSql === $storedSql) {',
			'to' => 'if ($expectedSql !== null && $expectedSql !== $storedSql) {',
			'label' => 'treats_matching_stamp_as_conflict',
		],
		[
			'file' => $appRoot . '/lib/Service/AccountPlanService.php',
			'from' => 'calendarDayFromStorage',
			'to' => 'calendarDayFromStorageBroken',
			'label' => 'renames_calendar_day_helper',
		],
	];
}

function apply_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "MUTATION SETUP FAIL {$m['label']}: from-string not found\n");
		exit(2);
	}
	$pos = strpos($src, $m['from']);
	file_put_contents($m['file'], substr_replace($src, $m['to'], $pos, strlen($m['from'])));
}

function revert_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	$pos = strpos($src, $m['to']);
	if ($pos === false) {
		fwrite(STDERR, "MUTATION REVERT FAIL {$m['label']}\n");
		exit(2);
	}
	file_put_contents($m['file'], substr_replace($src, $m['from'], $pos, strlen($m['to'])));
}

$baselineCmd = account_plan_save_phpunit_command($phpunit, $config, $filter);
exec($baselineCmd . ' 2>&1', $baselineOut, $baselineCode);
if ($baselineCode !== 0) {
	fwrite(STDERR, "BASELINE FAIL — AccountPlanStatusValidationTest must be green before mutations\n");
	fwrite(STDERR, implode("\n", array_slice($baselineOut, -40)) . "\n");
	exit(2);
}
fwrite(STDOUT, "BASELINE OK {$filter}\n");

$failed = 0;
$killed = 0;
foreach (account_plan_save_mutations($appRoot) as $m) {
	apply_mutation($m);
	exec($baselineCmd . ' 2>&1', $out, $code);
	revert_mutation($m);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED mutation {$m['label']}\n");
		$failed++;
	} else {
		fwrite(STDOUT, "KILLED {$m['label']}\n");
		$killed++;
	}
	$out = [];
}

fwrite(STDOUT, "Account plan save mutations: killed={$killed} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
