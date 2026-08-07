<?php

declare(strict_types=1);

/**
 * Mutation gauntlet — CustomerCheck directory seat search.
 *
 * Usage: php tests/Mutation/run-license-seat-search-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$filter = 'DirectoryOptionsSearchUsersTest|SettingsApiSearchUsersAclTest';

$runPhp = static function () use ($appRoot, $phpunit, $filter): int {
	$nextcloudRoot = dirname($appRoot, 2);
	$dockerRunner = $nextcloudRoot . '/docker/run-app-phpunit.sh';
	if (!is_file('/.dockerenv') && is_file($dockerRunner)) {
		passthru(escapeshellarg($dockerRunner) . ' customercheck --filter ' . escapeshellarg($filter), $code);
		return (int)$code;
	}
	passthru(
		'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter),
		$code,
	);
	return (int)$code;
};

$runNode = static function () use ($appRoot): int {
	passthru('node --test '
		. escapeshellarg($appRoot . '/tests/js/license-seat-search.test.mjs') . ' '
		. escapeshellarg($appRoot . '/tests/js/license-seat-scroll.test.mjs'), $code);
	return (int)$code;
};

echo "== baseline ==\n";
if ($runPhp() !== 0 || $runNode() !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}

$mutations = [
	'drop-display-name-search' => [
		'file' => 'lib/Service/DirectoryOptionsService.php',
		'suite' => 'php',
		'from' => '$byName = $this->userManager->searchDisplayName($query, $limit, 0);',
		'to' => '$byName = [];',
	],
	'skip-disabled-filter' => [
		'file' => 'lib/Service/DirectoryOptionsService.php',
		'suite' => 'php',
		'from' => "if (method_exists(\$user, 'isEnabled') && !\$user->isEnabled()) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'to' => "if (false && method_exists(\$user, 'isEnabled') && !\$user->isEnabled()) {\n\t\t\t\tcontinue;\n\t\t\t}",
	],
	'drop-empty-state-message' => [
		'file' => 'js/pages/settings.js',
		'suite' => 'js',
		'from' => "showSuggestNote(tr('No matching users found.'), false);",
		'to' => 'suggest.hidden = true;',
	],
	'drop-exclude-seated' => [
		'file' => 'js/pages/settings.js',
		'suite' => 'js',
		'from' => 'excludeUidsFn:',
		'to' => 'excludeUidsRemoved:',
	],
];

$failed = [];
foreach ($mutations as $name => $m) {
	$path = $appRoot . '/' . $m['file'];
	$orig = file_get_contents($path);
	if ($orig === false || !str_contains($orig, $m['from'])) {
		fwrite(STDERR, "Needle missing for mutant $name\n");
		exit(1);
	}
	file_put_contents($path, str_replace($m['from'], $m['to'], $orig));
	echo "== mutant: $name (expect FAIL) ==\n";
	$ok = ($m['suite'] === 'js' ? $runNode() : $runPhp()) === 0;
	file_put_contents($path, $orig);
	if ($ok) {
		$failed[] = $name;
		fwrite(STDERR, "SURVIVOR: $name\n");
	} else {
		echo "killed: $name\n";
	}
}

echo "== post baseline ==\n";
if ($runPhp() !== 0 || $runNode() !== 0) {
	fwrite(STDERR, "Post baseline failed\n");
	exit(1);
}
if ($failed !== []) {
	fwrite(STDERR, 'Survivors: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "All customercheck license-seat-search mutants killed.\n";
