<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for Inbox Unassigned mapping helpers.
 * Survivors = test gaps. Exit non-zero if any mutant lives.
 */

$appRoot = dirname(__DIR__, 2);
$file = $appRoot . '/js/common/inbox-assign-map.js';
$testCmd = 'cd ' . escapeshellarg($appRoot) . ' && npm run test:js -- tests/js/inbox-assign-map.test.js 2>&1';

$original = file_get_contents($file);
if ($original === false) {
	fwrite(STDERR, "Cannot read $file\n");
	exit(1);
}

$mutants = [
	[
		'label' => 'normalizeAssignKind never collapses shared_mail',
		'find' => "if (k === 'shared_mail' || k === 'personal_mail_shared_unmatched') {\n      return 'mail';\n    }",
		'replace' => "if (k === 'personal_mail_shared_unmatched') {\n      return 'mail';\n    }",
	],
	[
		'label' => 'isMailSourceKind drops personal_mail_shared_unmatched',
		'find' => "return code === 'shared_mail' || code === 'personal_mail_shared_unmatched';",
		'replace' => "return code === 'shared_mail';",
	],
	[
		'label' => 'mapReviewItemToRow uses review id as list id',
		'find' => 'id: mailMsgId > 0 ? mailMsgId : 0,',
		'replace' => 'id: reviewItemId,',
	],
	[
		'label' => 'hasMail ignores source kind',
		'find' => 'const hasMail = mailMsgId > 0 && isMailSourceKind(kind);',
		'replace' => 'const hasMail = mailMsgId > 0;',
	],
	[
		'label' => 'isUnifiedMailAssignRow ignores hasMail',
		'find' => "return !!(row && row.hasMail && parseInt(String(row.id || 0), 10) > 0\n      && parseInt(String(row.reviewItemId || 0), 10) > 0);",
		'replace' => "return !!(row && parseInt(String(row.id || 0), 10) > 0\n      && parseInt(String(row.reviewItemId || 0), 10) > 0);",
	],
	[
		'label' => 'reviewApiKind collapses shared_mail to mail',
		'find' => "if (k === 'shared_mail' || k === 'personal_mail_shared_unmatched' || k === 'mail') {\n      return k;\n    }",
		'replace' => "if (k === 'personal_mail_shared_unmatched' || k === 'mail') {\n      return k;\n    }\n    if (k === 'shared_mail') {\n      return 'mail';\n    }",
	],
	[
		'label' => 'sortMailRows always ascending',
		'find' => 'return asc ? cmp : -cmp;',
		'replace' => 'return cmp;',
	],
	[
		'label' => 'matchMailQuery always true',
		'find' => 'return hay.indexOf(needle) !== -1;',
		'replace' => 'return true;',
	],
	[
		'label' => 'nextSortState never toggles',
		'find' => "if (String(curKey || '') === key) {\n      return { key: key, dir: curDir === 'asc' ? 'desc' : 'asc' };\n    }",
		'replace' => "if (String(curKey || '') === key) {\n      return { key: key, dir: curDir === 'asc' ? 'asc' : 'desc' };\n    }",
	],
];

function runTests(string $cmd): bool
{
	exec($cmd, $out, $code);
	return $code === 0;
}

echo "Baseline (must pass)…\n";
if (!runTests($testCmd)) {
	fwrite(STDERR, "Baseline tests failed — fix before mutation.\n");
	exit(1);
}

$killed = 0;
$survived = [];
foreach ($mutants as $m) {
	if (!str_contains($original, $m['find'])) {
		fwrite(STDERR, "Mutant find string missing: {$m['label']}\n");
		file_put_contents($file, $original);
		exit(1);
	}
	$mutated = str_replace($m['find'], $m['replace'], $original);
	file_put_contents($file, $mutated);
	$passed = runTests($testCmd);
	file_put_contents($file, $original);
	if ($passed) {
		$survived[] = $m['label'];
		echo "SURVIVED: {$m['label']}\n";
	} else {
		$killed++;
		echo "killed: {$m['label']}\n";
	}
}

echo "\nKilled {$killed}/" . count($mutants) . "\n";
if ($survived !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $survived) . "\n");
	exit(1);
}
echo "All mutants killed.\n";
exit(0);
