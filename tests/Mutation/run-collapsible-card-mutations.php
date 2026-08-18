<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: collapsible card Open/Close disclosure affordance.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-collapsible-card-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'CollapsibleCardUxContractTest|AccountPlanLayoutContractTest';

function collapsible_card_phpunit_command(string $phpunit, string $config, string $filter): string
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
				. ' --filter ' . escapeshellarg($filter);
		}
	}
	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
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
 * @return list<array{file:string,from:string,to:string,label:string,replace_all?:bool}>
 */
function collapsible_card_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/templates/common/crm-emails-section.php',
			'from' => "include __DIR__ . '/crm-collapsible-disclosure.php';",
			'to' => '/* mutated-no-disclosure */',
			'label' => 'emails_drop_disclosure_include',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => "include __DIR__ . '/common/crm-collapsible-disclosure.php';",
			'to' => '/* mutated-no-disclosure */',
			'label' => 'company_detail_drops_all_disclosure_includes',
			'replace_all' => true,
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => 'details:not([open]) > summary .crm-card__disclosure-label--open',
			'to' => 'details:not([open]) > summary .crm-card__disclosure-label--closed',
			'label' => 'css_shows_close_label_when_collapsed',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "#app-content.crm-app .crm-card__disclosure {\n\tflex: 0 0 auto;\n\tdisplay: inline-flex;\n\talign-items: center;\n\tjustify-content: center;\n\tgap: var(--crm-space-2, 8px);\n\tmin-height: 44px;\n\tmin-width: 44px;",
			'to' => "#app-content.crm-app .crm-card__disclosure {\n\tflex: 0 0 auto;\n\tdisplay: inline-flex;\n\talign-items: center;\n\tjustify-content: center;\n\tgap: var(--crm-space-2, 8px);\n\tmin-height: 44px;\n\tmin-width: 24px;",
			'label' => 'disclosure_shrinks_below_touch_target',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'crm-advanced crm-account-plan__more',
			'to' => 'crm-plan-more',
			'label' => 'reverts_more_options_to_plan_more',
		],
		[
			'file' => $appRoot . '/templates/common/crm-collapsible-disclosure.php',
			'from' => 'crm-card__disclosure-label--open',
			'to' => 'crm-card__disclosure-label--gone',
			'label' => 'partial_drops_close_label_class',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,replace_all?:bool} $m
 */
function apply_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "MUTATION SETUP FAIL {$m['label']}: from-string not found in {$m['file']}\n");
		exit(2);
	}
	if (!empty($m['replace_all'])) {
		$next = str_replace($m['from'], $m['to'], $src, $count);
		if ($count < 1) {
			fwrite(STDERR, "MUTATION SETUP FAIL {$m['label']}: replace count 0\n");
			exit(2);
		}
	} else {
		$pos = strpos($src, $m['from']);
		$next = substr_replace($src, $m['to'], $pos, strlen($m['from']));
	}
	file_put_contents($m['file'], $next);
}

/**
 * @param array{file:string,from:string,to:string,label:string,replace_all?:bool} $m
 */
function revert_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!empty($m['replace_all'])) {
		if (!str_contains($src, $m['to'])) {
			fwrite(STDERR, "MUTATION REVERT FAIL {$m['label']}: to-string not found\n");
			exit(2);
		}
		$next = str_replace($m['to'], $m['from'], $src);
	} else {
		$pos = strpos($src, $m['to']);
		if ($pos === false) {
			fwrite(STDERR, "MUTATION REVERT FAIL {$m['label']}: to-string not found\n");
			exit(2);
		}
		$next = substr_replace($src, $m['from'], $pos, strlen($m['to']));
	}
	file_put_contents($m['file'], $next);
}

$baselineCmd = collapsible_card_phpunit_command($phpunit, $config, $filter);
exec($baselineCmd . ' 2>&1', $baselineOut, $baselineCode);
if ($baselineCode !== 0) {
	fwrite(STDERR, "BASELINE FAIL — CollapsibleCardUxContractTest must be green before mutations\n");
	fwrite(STDERR, implode("\n", array_slice($baselineOut, -30)) . "\n");
	exit(2);
}
fwrite(STDOUT, "BASELINE OK {$filter}\n");

$failed = 0;
$killed = 0;
foreach (collapsible_card_mutations($appRoot) as $m) {
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

fwrite(STDOUT, "Collapsible card mutations: killed={$killed} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
