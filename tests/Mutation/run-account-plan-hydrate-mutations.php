<?php

declare(strict_types=1);

/**
 * Account-plan / promises hydrate TDZ mutants — Company360PageContractTest.
 */

$root = dirname(__DIR__, 2);

$cases = [
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => 'generation = ++promisesGeneration;',
		'replace' => 'generation = promisesGeneration;',
		'test' => 'Company360PageContractTest',
		'label' => 'promises loader drops generation bump',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => 'generation = ++accountPlanGeneration;',
		'replace' => 'generation = accountPlanGeneration;',
		'test' => 'Company360PageContractTest',
		'label' => 'account plan loader drops generation bump',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => "// Boot only after generation counters are initialized (avoids TDZ hang).\n  loadCommandCenter();\n  loadAccountPlan();\n  loadPromises();",
		'replace' => '/* mutated: skip company 360 hydrator boot */',
		'test' => 'Company360PageContractTest',
		'label' => 'company 360 skips account plan / promises boot',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => "let commandCenterGeneration = 0;\n  let accountPlanGeneration = 0;\n  let promisesGeneration = 0;",
		'replace' => "let commandCenterGeneration = 0;\n  let promisesGeneration = 0;",
		'test' => 'Company360PageContractTest',
		'label' => 'accountPlanGeneration init removed (TDZ hang)',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => 'id="crm-account-plan-form" class="crm-form crm-form--stack"',
		'replace' => 'id="crm-account-plan-form" class="crm-form"',
		'test' => 'Company360PageContractTest',
		'label' => 'account plan form drops stack layout class',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$config = $root . '/phpunit.xml';
$failed = 0;
$killed = 0;
$pending = [];
$restoreAll = static function () use (&$pending): void {
	foreach ($pending as $path => $contents) {
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
		if ($out !== []) {
			echo implode("\n", array_slice($out, -20)) . "\n";
		}
		$failed++;
	}
	$out = [];
}

$total = $killed + $failed;
$msi = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
echo "\nMSI (account-plan hydrate): {$msi}% ({$killed}/{$total} killed)\n";
exit($failed === 0 && $killed > 0 ? 0 : 1);
