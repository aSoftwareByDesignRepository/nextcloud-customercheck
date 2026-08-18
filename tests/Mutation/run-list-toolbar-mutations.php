<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: list toolbar filter toggle (shared core + contracts).
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
$config = $appRoot . '/phpunit.xml';

function list_toolbar_phpunit_command(string $phpunit, string $config, string $filter): string
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
				. ' --cache-result-file=/tmp/cc-phpunit-list-toolbar.cache';
		}
	}
	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
}

function list_toolbar_vitest_command(): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	return 'npm --prefix ' . escapeshellarg($appRoot)
		. ' run test:js -- tests/js/list-toolbar-core.test.js';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,tool:string,filter?:string}>
 */
function list_toolbar_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/common/list-toolbar-core.js',
			'from' => '    if (toggle) {
      toggle.addEventListener(\'click\', () => {
        if (panel.hasAttribute(\'hidden\')) {
          panel.removeAttribute(\'hidden\');
        } else {
          panel.setAttribute(\'hidden\', \'\');
        }
        sync();
      });
    }

    sync();
    return sync;',
			'to' => '    sync();
    return sync;',
			'label' => 'filter_toggle_click_unwired',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/filter-live.js',
			'from' => '        if (window.CustomerCheckListToolbar && typeof window.CustomerCheckListToolbar.syncFilterPanelsFromUrl === \'function\') {
          window.CustomerCheckListToolbar.syncFilterPanelsFromUrl(url.href);
        }',
			'to' => '',
			'label' => 'live_filter_skips_toolbar_sync',
			'tool' => 'phpunit',
			'filter' => 'ListToolbarContractTest',
		],
		[
			'file' => $appRoot . '/js/common/list-toolbar-core.js',
			'from' => "return (params.get('status') || '') !== '' || params.get('archived') === '1';",
			'to' => "return (params.get('status') || '') !== '';",
			'label' => 'archived_chip_does_not_open_panel',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/templates/companies.php',
			'from' => 'crm-list-controls<?php if ($filterPanelOpen): ?> crm-list-controls--filter-open<?php endif; ?>" id="crm-companies-controls"',
			'to' => 'broken-toolbar" id="crm-companies-controls"',
			'label' => 'drops_list_controls_class',
			'tool' => 'phpunit',
			'filter' => 'ListToolbarContractTest',
		],
	];
}

function list_toolbar_run_contract_check(string $label): int
{
	$root = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	$companies = (string)file_get_contents($root . '/templates/companies.php');
	$filterLive = (string)file_get_contents($root . '/js/common/filter-live.js');
	switch ($label) {
		case 'live_filter_skips_toolbar_sync':
			return str_contains($filterLive, 'syncFilterPanelsFromUrl(url.href)') ? 0 : 1;
		case 'drops_list_controls_class':
			return str_contains($companies, 'crm-list-controls') ? 0 : 1;
		default:
			return 0;
	}
}

function list_toolbar_apply_mutation(array $mutation): void
{
	$file = $mutation['file'];
	$from = $mutation['from'];
	$to = $mutation['to'];
	$contents = (string)file_get_contents($file);
	if (!str_contains($contents, $from)) {
		fwrite(STDERR, "Mutation source not found in {$file}: {$mutation['label']}\n");
		exit(2);
	}
	if (!isset($GLOBALS['list_toolbar_originals'][$file])) {
		$GLOBALS['list_toolbar_originals'][$file] = $contents;
	}
	$patched = str_replace($from, $to, $contents, $count);
	if ($count !== 1) {
		fwrite(STDERR, "Expected exactly one replacement in {$file}: {$mutation['label']} (got {$count})\n");
		exit(2);
	}
	file_put_contents($file, $patched);
}

function list_toolbar_restore_all(): void
{
	foreach ($GLOBALS['list_toolbar_originals'] ?? [] as $file => $original) {
		file_put_contents($file, $original);
	}
}

$GLOBALS['list_toolbar_originals'] = [];
$mutations = list_toolbar_mutations($appRoot);
$killed = 0;
$survived = 0;

foreach ($mutations as $mutation) {
	list_toolbar_apply_mutation($mutation);
	if ($mutation['tool'] === 'vitest') {
		$cmd = list_toolbar_vitest_command();
		exec($cmd . ' 2>&1', $output, $code);
	} else {
		$code = list_toolbar_run_contract_check((string)$mutation['label']);
		$output = [];
	}
	list_toolbar_restore_all();
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$mutation['label']}\n" . implode("\n", $output) . "\n");
		$survived++;
	} else {
		$killed++;
	}
}

$contractCode = list_toolbar_run_contract_check('live_filter_skips_toolbar_sync')
	| list_toolbar_run_contract_check('drops_list_controls_class');
if ($contractCode !== 0) {
	fwrite(STDERR, "Inline contract checks failed after restore\n");
	exit(1);
}

$total = count($mutations);
echo "List toolbar mutations: {$killed}/{$total} killed";
if ($survived > 0) {
	echo " ({$survived} survived)";
}
echo "\n";
exit($survived > 0 ? 1 : 0);
