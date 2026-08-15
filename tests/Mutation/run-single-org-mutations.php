<?php

declare(strict_types=1);

/**
 * Mutation suite — single-organisation fail-closed locks.
 *
 * Mutates critical guards and asserts the dedicated unit contracts catch them.
 * Run: php tests/Mutation/run-single-org-mutations.php
 * Prefer Docker: docker compose exec -u www-data nextcloud php …
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';

if (!is_executable($phpunit) && !is_file($phpunit)) {
	fwrite(STDERR, "phpunit not found at {$phpunit}\n");
	exit(1);
}

/**
 * @return list<array{file: string, find: string, replace: string, label: string}>
 */
function singleOrgMutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Constants.php',
			'find' => 'public const MULTI_WORKSPACE_ENABLED = false;',
			'replace' => 'public const MULTI_WORKSPACE_ENABLED = true;',
			'label' => 'MULTI_WORKSPACE_ENABLED flipped on',
		],
		[
			'file' => $appRoot . '/lib/Constants.php',
			'find' => 'public const WS_MAX_PER_INSTANCE = 1;',
			'replace' => 'public const WS_MAX_PER_INSTANCE = 100;',
			'label' => 'WS_MAX raised while MULTI still false (create path must still refuse)',
		],
		[
			'file' => $appRoot . '/lib/Service/WorkspaceService.php',
			'find' => "if (!Constants::MULTI_WORKSPACE_ENABLED && \$existing >= 1) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_WS_LIMIT,\n\t\t\t\t\t'workspace',\n\t\t\t\t\t\$this->l10n->t('CustomerCheck uses a single organisation. Additional workspaces cannot be created.'),\n\t\t\t\t);\n\t\t\t}",
			'replace' => "if (false && !Constants::MULTI_WORKSPACE_ENABLED && \$existing >= 1) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_WS_LIMIT,\n\t\t\t\t\t'workspace',\n\t\t\t\t\t\$this->l10n->t('CustomerCheck uses a single organisation. Additional workspaces cannot be created.'),\n\t\t\t\t);\n\t\t\t}",
			'label' => 'WorkspaceService create single-org guard neutralized',
		],
		[
			'file' => $appRoot . '/lib/Service/CompanyService.php',
			'find' => "if (!Constants::MULTI_WORKSPACE_ENABLED) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_WS_LIMIT,\n\t\t\t\t'workspaceId',\n\t\t\t\t\$this->l10n->t('CustomerCheck uses a single organisation. Companies cannot be moved between workspaces.'),\n\t\t\t);\n\t\t}",
			'replace' => "if (false && !Constants::MULTI_WORKSPACE_ENABLED) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_WS_LIMIT,\n\t\t\t\t'workspaceId',\n\t\t\t\t\$this->l10n->t('CustomerCheck uses a single organisation. Companies cannot be moved between workspaces.'),\n\t\t\t);\n\t\t}",
			'label' => 'CompanyService move single-org guard neutralized',
		],
		[
			'file' => $appRoot . '/lib/Controller/PersonaApiController.php',
			'find' => "if (!Constants::MULTI_WORKSPACE_ENABLED && \$this->workspaceService->listAll() !== []) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_WS_LIMIT,\n\t\t\t\t\t'workspace',\n\t\t\t\t\t\$this->l10n->t('CustomerCheck uses a single organisation. Additional workspaces cannot be created.'),\n\t\t\t\t);\n\t\t\t}",
			'replace' => "if (false && !Constants::MULTI_WORKSPACE_ENABLED && \$this->workspaceService->listAll() !== []) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_WS_LIMIT,\n\t\t\t\t\t'workspace',\n\t\t\t\t\t\$this->l10n->t('CustomerCheck uses a single organisation. Additional workspaces cannot be created.'),\n\t\t\t\t);\n\t\t\t}",
			'label' => 'PersonaApi createWorkspace controller guard neutralized',
		],
		[
			'file' => $appRoot . '/lib/Repair/BootstrapDefaultWorkspace.php',
			'find' => "if (\$anyRow !== false) {\n\t\t\t\$id = (int)\$anyRow['id'];",
			'replace' => "if (false && \$anyRow !== false) {\n\t\t\t\$id = (int)\$anyRow['id'];",
			'label' => 'Bootstrap promote-existing path disabled',
		],
	];
}

$filter = 'SingleOrgModeContractTest|WorkspaceServiceSingleOrgCreateTest|CompanyServiceMoveWorkspaceTest|WorkspaceSwitcherContractTest';
$mutations = singleOrgMutations($appRoot);
$killed = 0;
$survived = 0;
$failedApply = 0;

foreach ($mutations as $i => $m) {
	$file = $m['file'];
	$original = (string)file_get_contents($file);
	if (!str_contains($original, $m['find'])) {
		fwrite(STDERR, "[skip] could not apply: {$m['label']}\n");
		$failedApply++;
		continue;
	}
	$mutated = str_replace($m['find'], $m['replace'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "[skip] no-op: {$m['label']}\n");
		$failedApply++;
		continue;
	}
	file_put_contents($file, $mutated);
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($config)
		. ' --testsuite unit --filter ' . escapeshellarg($filter)
		. ' 2>&1';
	exec($cmd, $out, $code);
	file_put_contents($file, $original);
	if ($code === 0) {
		fwrite(STDERR, "[SURVIVED] {$m['label']}\n");
		$survived++;
	} else {
		fwrite(STDOUT, "[killed] {$m['label']}\n");
		$killed++;
	}
	unset($out);
}

fwrite(STDOUT, sprintf(
	"\nSingle-org mutations: killed=%d survived=%d apply-fail=%d total=%d\n",
	$killed,
	$survived,
	$failedApply,
	count($mutations),
));

if ($survived > 0 || $failedApply > 0 || $killed === 0) {
	exit(1);
}
exit(0);
