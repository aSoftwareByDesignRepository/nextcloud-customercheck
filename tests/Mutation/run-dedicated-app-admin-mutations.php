<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: dedicated App Admin OR-semantics + chip UI.
 *
 * Usage:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-dedicated-app-admin-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function dedicated_app_admin_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/AccessControlService.php',
			'from' => "\t\tif (\$this->isSystemAdmin(\$userId)) {\n\t\t\treturn true;\n\t\t}",
			'to' => "\t\tif (false && \$this->isSystemAdmin(\$userId)) {\n\t\t\treturn true;\n\t\t}",
			'label' => 'drops_system_admin_or_branch',
		],
		[
			'file' => $appRoot . '/lib/Service/AccessControlService.php',
			'from' => 'return in_array($userId, $this->getAppAdminIds(), true);',
			'to' => 'return false;',
			'label' => 'ignores_dedicated_admin_list',
		],
		[
			'file' => $appRoot . '/templates/settings/access.php',
			'from' => 'id="crm-app-admins-chips"',
			'to' => 'id="crm-app-admins-chip-list"',
			'label' => 'breaks_app_admin_chip_list_id',
		],
		[
			'file' => $appRoot . '/lib/Service/SettingsService.php',
			'from' => "\t\t\tif (\$removingSelf && \$adminIds === []) {\n\t\t\t\tthrow new ValidationException(\n\t\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\t'You cannot remove your own app administrator access without assigning another administrator first.',\n\t\t\t\t\t[Constants::CONFIG_APP_ADMIN_USER_IDS => 'Cannot remove yourself'],\n\t\t\t\t);\n\t\t\t}",
			'to' => "\t\t\tif (false && \$removingSelf && \$adminIds === []) {\n\t\t\t\tthrow new ValidationException(\n\t\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\t'You cannot remove your own app administrator access without assigning another administrator first.',\n\t\t\t\t\t[Constants::CONFIG_APP_ADMIN_USER_IDS => 'Cannot remove yourself'],\n\t\t\t\t);\n\t\t\t}",
			'label' => 'skips_self_lockout_guard',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter "DedicatedAppAdminContractTest|AccessControlServiceTest|SettingsServiceAppAdminValidationTest|UserDeletedListenerAccessListsTest"';
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = dedicated_app_admin_mutations($appRoot);
$failed = 0;
$killed = 0;

echo "Baseline…\n";
if (run_phpunit($phpunit, $appRoot) !== 0) {
	fwrite(STDERR, "Baseline failed — aborting mutations.\n");
	exit(1);
}

foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP (needle missing): {$m['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	echo "Mutant {$m['label']}…\n";
	$code = run_phpunit($phpunit, $appRoot);
	file_put_contents($m['file'], $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$m['label']}\n");
		$failed++;
	} else {
		echo "Killed: {$m['label']}\n";
		$killed++;
	}
}

echo "Done: killed={$killed} failed={$failed} total=" . count($mutations) . "\n";
exit($failed === 0 ? 0 : 1);
