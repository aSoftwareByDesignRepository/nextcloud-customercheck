<?php

declare(strict_types=1);

/**
 * Mutation tests for Files attach picker hardening (pickNodes + DAV fileid).
 *
 * Run: php tests/Mutation/run-mail-attach-picker-mutations.php
 * Prefer Docker: docker compose exec -u www-data nextcloud …
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

$cases = [
	[
		'file' => $root . '/js/common/folder-picker.js',
		'find' => 'picker.pickNodes()',
		'replace' => 'picker.pick()',
		'test' => 'DirectoryPickerContractTest::testQuotePdfAndCompanyFilesFolderGuards',
		'label' => 'pickNodes() downgraded to pick()',
	],
	[
		'file' => $root . '/js/common/folder-picker.js',
		'find' => 'function resolveFileIdViaDav(normalizedPath) {',
		'replace' => 'function resolveFileIdViaDavBroken(normalizedPath) {',
		'test' => 'DirectoryPickerContractTest::testQuotePdfAndCompanyFilesFolderGuards',
		'label' => 'DAV fileid resolver renamed away',
	],
	[
		'file' => $root . '/js/common/folder-picker.js',
		'find' => 'function selectionFromPickerNode(node) {',
		'replace' => 'function selectionFromPickerNodeBroken(node) {',
		'test' => 'DirectoryPickerContractTest::testQuotePdfAndCompanyFilesFolderGuards',
		'label' => 'selectionFromPickerNode renamed away',
	],
];

$failed = 0;
foreach ($cases as $case) {
	$original = (string)file_get_contents($case['file']);
	if (!str_contains($original, $case['find'])) {
		fwrite(STDERR, "SKIP (needle missing): {$case['label']}\n");
		$failed++;
		continue;
	}
	$count = 0;
	$mutated = str_replace($case['find'], $case['replace'], $original, $count);
	if ($count < 1) {
		fwrite(STDERR, "SKIP (no replace): {$case['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($case['file'], $mutated);
	$cmd = 'cd ' . escapeshellarg($root)
		. ' && ./vendor/bin/phpunit --configuration tests/phpunit.xml '
		. escapeshellarg($case['test'])
		. ' 2>&1';
	exec($cmd, $out, $code);
	file_put_contents($case['file'], $original);
	$killed = $code !== 0;
	fwrite(STDOUT, ($killed ? 'KILLED' : 'SURVIVED') . ": {$case['label']}\n");
	if (!$killed) {
		$failed++;
		fwrite(STDERR, implode("\n", array_slice($out, -30)) . "\n");
	}
	$out = [];
}

if ($failed > 0) {
	fwrite(STDERR, "Mail attach picker mutations: {$failed} problem(s)\n");
	exit(1);
}
fwrite(STDOUT, "Mail attach picker mutations: all killed\n");
exit(0);
