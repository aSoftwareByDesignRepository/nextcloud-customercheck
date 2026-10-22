<?php

declare(strict_types=1);

/**
 * Bachus first-paint simplicity mutants — BachusFirstPaintSimplicityContractTest.
 */

$root = dirname(__DIR__, 2);

$cases = [
	[
		'file' => $root . '/templates/company-detail.php',
		'find' => '$showRoleCue = !$showWelcomeCoach;',
		'replace' => '$showRoleCue = true;',
		'test' => 'BachusFirstPaintSimplicityContractTest',
		'label' => 'role cue always shows beside welcome coach',
	],
	[
		'file' => $root . '/templates/company-detail.php',
		'find' => "data-crm-trigger=\"crm-log-email\"><?php p(\$l->t('Log talk')); ?></button>",
		'replace' => "href=\"#crm-communication\"><?php p(\$l->t('Log talk')); ?></a>",
		'test' => 'BachusFirstPaintSimplicityContractTest',
		'label' => 'Log talk reverts to hash-only scroll',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => "const withEmail = persons.filter((p) => String((p && p.email) || '').trim() !== '');",
		'replace' => 'const withEmail = persons;',
		'test' => 'BachusFirstPaintSimplicityContractTest',
		'label' => 'log email lists people without email',
	],
	[
		'file' => $root . '/templates/company-detail.php',
		'find' => 'One tap schedules a call for tomorrow — no email needed yet.',
		'replace' => 'Use Log email even when nobody has an email yet.',
		'test' => 'BachusFirstPaintSimplicityContractTest',
		'label' => 'coach without email loses Call tomorrow copy',
	],
	[
		'file' => $root . '/templates/company-detail.php',
		'find' => 'data-crm-trigger="crm-schedule-callback"',
		'replace' => 'data-crm-trigger="crm-log-email"',
		'test' => 'BachusFirstPaintSimplicityContractTest',
		'label' => 'coach without email offers only log-email dead end',
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
			echo implode("\n", array_slice($out, -20)) . "\n";
		}
		$failed++;
	}
	$out = [];
}

$total = $killed + $failed;
$msi = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
echo "\nMSI (bachus first-paint): {$msi}% ({$killed}/{$total} killed)\n";
exit($failed === 0 && $killed > 0 ? 0 : 1);
