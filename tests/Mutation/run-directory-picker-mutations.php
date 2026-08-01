<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: directory pickers + searchUsers ACL + unknown member reject.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-directory-picker-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function directory_picker_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Controller/SettingsApiController.php',
			'from' => "\t\t\tif (!\$this->accessControl->isAppAdmin(\$uid)) {\n\t\t\t\tif (\$workspaceId < 1) {\n\t\t\t\t\tthrow new AccessDeniedException();\n\t\t\t\t}\n\t\t\t\t\$this->workspaces->assertCapability(\$uid, \$workspaceId, 'write');\n\t\t\t}",
			'to' => "\t\t\tif (!\$this->accessControl->isAppAdmin(\$uid)) {\n\t\t\t\t// mutated: directory search open to any app user\n\t\t\t}",
			'label' => 'search_users_open_to_viewers',
		],
		[
			'file' => $appRoot . '/lib/Service/WorkspaceService.php',
			'from' => "\t\tif (\$userId === '' || !\$this->userManager->userExists(\$userId)) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'userId',\n\t\t\t\t\$this->l10n->t('Unknown Nextcloud user. Search the directory and pick a colleague.'),\n\t\t\t);\n\t\t}",
			'to' => "\t\tif (\$userId === '') {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'userId',\n\t\t\t\t\$this->l10n->t('Unknown Nextcloud user. Search the directory and pick a colleague.'),\n\t\t\t);\n\t\t}",
			'label' => 'add_member_skips_user_exists',
		],
		[
			'file' => $appRoot . '/templates/settings/workspace-mailbox.php',
			'from' => 'id="crm-ws-member-search"',
			'to' => 'id="crm-ws-member-search-mutated"',
			'label' => 'member_picker_search_id_broken',
		],
		[
			'file' => $appRoot . '/templates/settings/license.php',
			'from' => 'Never type a raw user id',
			'to' => 'Type the Nextcloud user id',
			'label' => 'license_help_allows_raw_id',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'function bindPersonaUserPicker(',
			'to' => 'function bindPersonaUserPickerBroken(',
			'label' => 'team_capacity_picker_binder_broken',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'bindPersonaUserPicker(Object.assign',
			'to' => 'bindPersonaUserPickerBroken(Object.assign',
			'label' => 'team_capacity_picker_calls_broken',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'I confirm reassigning work from the selected',
			'to' => 'Type the from-user name to confirm',
			'label' => 'bulk_confirm_reverts_to_typed_id',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter "DirectoryPickerContractTest|WorkspaceServiceAddMemberValidationTest|SettingsApiSearchUsersAclTest"';
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = directory_picker_mutations($appRoot);
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
