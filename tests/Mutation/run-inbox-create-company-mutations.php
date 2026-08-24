<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for Sales Inbox inline company create helpers.
 */

$appRoot = dirname(__DIR__, 2);
$coreFile = $appRoot . '/js/common/inbox-create-company-core.js';
$pageFile = $appRoot . '/js/pages/sales-inbox.js';
$pickerFile = $appRoot . '/js/common/company-picker.js';

$vitestCore = 'cd ' . escapeshellarg($appRoot) . ' && npm run test:js -- tests/js/inbox-create-company-core.test.js tests/js/company-picker-empty-results.test.js 2>&1';

function runCmd(string $cmd): bool
{
	exec($cmd, $out, $code);
	return $code === 0;
}

function applyMutant(string $file, string $find, string $replace): ?string
{
	$original = file_get_contents($file);
	if ($original === false) {
		return null;
	}
	if (!str_contains($original, $find)) {
		fwrite(STDERR, "Needle missing in $file\n");
		return null;
	}
	file_put_contents($file, str_replace($find, $replace, $original));
	return $original;
}

$mutants = [
	[
		'file' => $coreFile,
		'label' => 'validateInlineCompanyName accepts empty',
		'find' => "if (!name) {\n      return { ok: false, name: '', error: 'empty' };",
		'replace' => "if (!name) {\n      return { ok: true, name: '', error: '' };",
	],
	[
		'file' => $coreFile,
		'label' => 'extractCompanyIdFromCreateResponse ignores nested data',
		'find' => "if (payload.data !== undefined && payload.data !== null && typeof payload.data === 'object') {\n      payload = payload.data;\n    }",
		'replace' => '// mutant: skip unwrap',
	],
	[
		'file' => $coreFile,
		'label' => 'buildMinimalCompanyCreateBody keeps raw whitespace',
		'find' => "return { name: String(name || '').trim() };",
		'replace' => "return { name: String(name || '') };",
	],
	[
		'file' => $pageFile,
		'label' => 'createCompanyAndAssign skips assign POST',
		'find' => "await Api.post('/workspaces/' + ws + '/review/items/' + rid + '/assign', { companyId: cid });",
		'replace' => '// mutant: skip assign',
	],
	[
		'file' => $pickerFile,
		'label' => 'onEmptyResults never fires on empty search',
		'find' => "if (!shown.length && q && typeof opts.onEmptyResults === 'function') {\n          opts.onEmptyResults(q);\n        }",
		'replace' => '// mutant: skip onEmptyResults',
	],
];

echo "Baseline vitest…\n";
if (!runCmd($vitestCore)) {
	fwrite(STDERR, "Baseline vitest failed.\n");
	exit(1);
}

$killed = 0;
$total = count($mutants);

foreach ($mutants as $mutant) {
	$file = $mutant['file'];
	$original = applyMutant($file, $mutant['find'], $mutant['replace']);
	if ($original === null) {
		fwrite(STDERR, "Abort: {$mutant['label']}\n");
		exit(1);
	}

	$vitestOk = runCmd($vitestCore);
	$contractOk = runCmd(
		'cd ' . escapeshellarg(dirname($appRoot, 2))
		. ' && docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/vendor/bin/phpunit'
		. ' -c /var/www/html/custom_apps/customercheck/phpunit.xml'
		. ' --filter SalesInboxInlineCreateContractTest 2>&1',
	);
	$survived = $vitestOk && $contractOk;

	file_put_contents($file, $original);

	if ($survived) {
		echo "SURVIVED: {$mutant['label']}\n";
	} else {
		echo "KILLED: {$mutant['label']}\n";
		$killed += 1;
	}
}

echo "Mutation score: {$killed}/{$total} killed\n";
if ($killed < $total) {
	exit(1);
}

echo "All mutants killed.\n";
