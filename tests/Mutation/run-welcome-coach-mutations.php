<?php

declare(strict_types=1);

/**
 * Welcome-coach step-card mutants — WelcomeCoachUxContractTest.
 */

$root = dirname(__DIR__, 2);

$cases = [
	[
		'file' => $root . '/templates/company-detail.php',
		'find' => 'crm-welcome-coach__steps',
		'replace' => 'crm-welcome-coach__list',
		'test' => 'WelcomeCoachUxContractTest',
		'label' => 'welcome coach drops step grid class',
	],
	[
		'file' => $root . '/templates/company-detail.php',
		'find' => 'Three short steps to make this customer useful.',
		'replace' => 'Onboarding checklist for power users.',
		'test' => 'WelcomeCoachUxContractTest',
		'label' => 'welcome coach lead reverts to jargon',
	],
	[
		'file' => $root . '/css/app.css',
		'find' => "@media (min-width: 960px) {\n\t#app-content.crm-app .crm-welcome-coach__steps {\n\t\tgrid-template-columns: repeat(3, minmax(0, 1fr));\n\t}\n}",
		'replace' => "@media (min-width: 960px) {\n\t#app-content.crm-app .crm-welcome-coach__steps {\n\t\tgrid-template-columns: 1fr;\n\t}\n}",
		'test' => 'WelcomeCoachUxContractTest',
		'label' => 'welcome coach drops desktop three-column grid',
	],
	[
		'file' => $root . '/css/app.css',
		'find' => "#app-content.crm-app .crm-welcome-coach__step-actions .crm-btn {\n\tmin-height: var(--crm-touch, 44px);\n\tjustify-content: center;\n}",
		'replace' => "#app-content.crm-app .crm-welcome-coach__step-actions .crm-btn {\n\tmin-height: 32px;\n\tjustify-content: center;\n}",
		'test' => 'WelcomeCoachUxContractTest',
		'label' => 'welcome coach CTAs drop 44px touch target',
	],
	[
		'file' => $root . '/templates/company-detail.php',
		'find' => "IconCatalog::renderCalloutWell('info', 'info')",
		'replace' => "'' /* mutated: empty welcome icon */",
		'test' => 'WelcomeCoachUxContractTest',
		'label' => 'welcome coach drops callout icon well',
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
			echo implode("\n", array_slice($out, -25)) . "\n";
		}
		$failed++;
	}
	$out = [];
}

$total = $killed + $failed;
$msi = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
echo "\nMSI (welcome-coach): {$msi}% ({$killed}/{$total} killed)\n";
exit($failed === 0 && $killed > 0 ? 0 : 1);
