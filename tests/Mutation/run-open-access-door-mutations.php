<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for CustomerCheck Open access door + boot nav isolation.
 *
 * Usage (from app root via Docker):
 *   php tests/Mutation/run-open-access-door-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function open_access_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/AccessControlService.php',
			'from' => "\t\tif (!\$this->isAccessRestrictionEnabled()) {\n\t\t\treturn true;\n\t\t}",
			'to' => "\t\tif (!\$this->isAccessRestrictionEnabled()) {\n\t\t\treturn false;\n\t\t}",
			'label' => 'open_mode_denies_everyone',
		],
		[
			'file' => $appRoot . '/lib/Service/AccessControlService.php',
			'from' => "\t\tif (!\$this->isAccessRestrictionEnabled()) {\n\t\t\treturn true;\n\t\t}\n\n\t\treturn \$this->userMatchesAccessAllowList(\$userId);",
			'to' => "\t\tif (!\$this->isAccessRestrictionEnabled()) {\n\t\t\treturn true;\n\t\t}\n\n\t\treturn true;",
			'label' => 'restricted_mode_always_allows',
		],
		[
			'file' => $appRoot . '/lib/AppInfo/Application.php',
			'from' => "\t\ttry {\n\t\t\t\$this->getContainer()->get(SettingsService::class)->ensureTokensExist();\n\t\t} catch (\\Throwable) {\n\t\t}\n\n\t\ttry {",
			'to' => "\t\ttry {\n\t\t\t\$this->getContainer()->get(SettingsService::class)->ensureTokensExist();\n",
			'label' => 'merge_token_seed_into_nav_try',
		],
		[
			'file' => $appRoot . '/templates/settings/access.php',
			'from' => 'Open — every logged-in user can open the app',
			'to' => 'Open — every Nextcloud user with a CustomerCheck role can open the app',
			'label' => 'misleading_open_copy',
		],
	];
}

function run_open_access_tests(string $appRoot, string $phpunit): int
{
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter "AccessControlServiceTest|OpenAccessModeContractTest|AppAccessMiddlewareTest|SettingsOpenAccessConfirmTest"';
	passthru($cmd, $code);
	return (int)$code;
}

echo "== baseline open-access tests ==\n";
$baseline = run_open_access_tests($appRoot, $phpunit);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$failed = [];
foreach (open_access_mutations($appRoot) as $mutation) {
	$source = $mutation['file'];
	$backup = $source . '.mutation-bak';
	$label = $mutation['label'];
	echo "\n== mutation: {$label} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		$failed[] = $label . ' (anchor missing)';
		fwrite(STDERR, "Mutation anchor not found for {$label}\n");
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	file_put_contents($source, $mutated);
	$code = run_open_access_tests($appRoot, $phpunit);
	rename($backup, $source);
	if ($code === 0) {
		$failed[] = $label;
		echo "MUTATION SURVIVED: {$label}\n";
	} else {
		echo "killed {$label}\n";
	}
}

if ($failed !== []) {
	fwrite(STDERR, "\nSurviving mutations: " . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll CustomerCheck open-access door mutations killed.\n";
exit(0);
