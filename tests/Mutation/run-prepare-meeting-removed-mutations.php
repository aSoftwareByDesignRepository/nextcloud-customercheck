<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: prepare-meeting removal (no duplicate 360 pack, ACL-gated 301).
 *
 * Host (from customercheck/):
 *   php tests/Mutation/run-prepare-meeting-removed-mutations.php
 *
 * Docker (from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-prepare-meeting-removed-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$lockPath = $appRoot . '/.mutation.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another mutation run holds {$lockPath} — aborting.\n");
	exit(1);
}
register_shutdown_function(static function () use ($lockFh, $lockPath): void {
	flock($lockFh, LOCK_UN);
	fclose($lockFh);
	@unlink($lockPath);
});

/**
 * Prefer Docker PHPUnit when the host cannot reach the NC DB (bootstrap.php loads OC).
 */
function prepare_meeting_phpunit_command(string $phpunit, string $filter): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	$composeDir = dirname($appRoot, 2);
	$dockerPhpunit = '/var/www/html/custom_apps/customercheck/vendor/bin/phpunit';
	$dockerConfig = '/var/www/html/custom_apps/customercheck/phpunit.xml';
	if (is_file($composeDir . '/docker-compose.yml')) {
		$probe = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";');
		exec($probe . ' 2>/dev/null', $probeOut, $probeCode);
		if ($probeCode === 0) {
			return 'cd ' . escapeshellarg($composeDir)
				. ' && docker compose exec -T -u www-data nextcloud php ' . escapeshellarg($dockerPhpunit)
				. ' --configuration ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter);
		}
	}

	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function prepare_meeting_removed_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => "\t\t\$this->requireCompanyPage(\$id);\n\t\t\$ws = (int)\$this->request->getParam('workspaceId', 0);",
			'to' => "\t\t\$ws = (int)\$this->request->getParam('workspaceId', 0);",
			'label' => 'redirect_skips_company_acl',
			'filter' => 'PrepareMeetingRemovedContractTest::testRedirectGatesOnCompanyAcl',
		],
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => "linkToRoute('customercheck.page.companyDetail', \$params)",
			'to' => "linkToRoute('customercheck.page.today', \$params)",
			'label' => 'redirect_lands_on_today',
			'filter' => 'PrepareMeetingRemovedContractTest::testRedirectIsPermanentToCompanyDetail',
		],
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => "\t\treturn new RedirectResponse(\n\t\t\t\$this->urlGenerator->getAbsoluteURL(\$rel),\n\t\t\tHttp::STATUS_MOVED_PERMANENTLY,\n\t\t);",
			'to' => "\t\treturn new RedirectResponse(\n\t\t\t\$this->urlGenerator->getAbsoluteURL(\$rel),\n\t\t\tHttp::STATUS_FOUND,\n\t\t);",
			'label' => 'redirect_is_temporary',
			'filter' => 'PrepareMeetingRemovedContractTest::testRedirectIsPermanentToCompanyDetail',
		],
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => "\t\t\$ws = (int)\$this->request->getParam('workspaceId', 0);\n\t\t\$params = ['id' => \$id];",
			'to' => "\t\t\$ws = \$this->request->getParam('workspaceId', 0);\n\t\t\$params = ['id' => \$id];",
			'label' => 'workspace_id_not_int_cast',
			'filter' => 'PrepareMeetingRemovedContractTest::testRedirectWorkspaceIdIsStrictInt',
		],
		[
			'file' => $appRoot . '/appinfo/routes.php',
			'from' => "['name' => 'persona_api#cancelPromise', 'url' => '/api/v1/companies/{companyId}/promises/{id}/cancel', 'verb' => 'POST', 'requirements' => ['companyId' => '\\d+', 'id' => '\\d+']],",
			'to' => "['name' => 'persona_api#cancelPromise', 'url' => '/api/v1/companies/{companyId}/promises/{id}/cancel', 'verb' => 'POST', 'requirements' => ['companyId' => '\\d+', 'id' => '\\d+']],\n\t\t['name' => 'persona_api#prepareMeeting', 'url' => '/api/v1/companies/{companyId}/prepare-meeting', 'verb' => 'GET', 'requirements' => ['companyId' => '\\d+']],",
			'label' => 'restores_prepare_meeting_api',
			'filter' => 'PrepareMeetingRemovedContractTest::testApiRouteGone',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => '<details class="crm-card crm-card--collapsible" id="crm-timeline"',
			'to' => '<details class="crm-card crm-card--collapsible" id="crm-prepare-meeting"></details>' . "\n" . '<details class="crm-card crm-card--collapsible" id="crm-timeline"',
			'label' => 'restores_360_prepare_meeting_card',
			'filter' => 'PrepareMeetingRemovedContractTest::testCompany360HasNoPrepareMeetingSection',
		],
		[
			'file' => $appRoot . '/js/common/company360-focus-core.js',
			'from' => "'crm-timeline': 'more',",
			'to' => "'crm-prepare-meeting': 'more',\n  'crm-timeline': 'more',",
			'label' => 'restores_focus_map_key',
			'filter' => 'PrepareMeetingRemovedContractTest::testJsDropsPrepareMeetingMount',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $m
 */
function apply_mutation(array $m): string
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('Needle not found for ' . $m['label'] . ' in ' . $m['file']);
	}
	$mutated = str_replace($m['from'], $m['to'], $src, $count);
	if ($count !== 1) {
		throw new RuntimeException('Expected 1 replace for ' . $m['label'] . ', got ' . $count);
	}
	file_put_contents($m['file'], $mutated);

	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $m
 */
function run_kill_check(string $phpunit, array $m): bool
{
	$cmd = prepare_meeting_phpunit_command($phpunit, (string)$m['filter']) . ' 2>&1';
	exec($cmd, $out, $code);
	$body = implode("\n", $out);
	if (str_contains($body, 'No tests executed')) {
		throw new RuntimeException('PHPUnit ran zero tests for ' . $m['label'] . "\n" . $body);
	}
	$GLOBALS['lastMutationPhpunitOut'] = $body;

	return $code !== 0;
}

$mutations = prepare_meeting_removed_mutations($appRoot);
$failed = [];
$killed = 0;

echo 'Prepare-meeting removal mutation gauntlet (' . count($mutations) . " mutants)\n";

foreach ($mutations as $m) {
	$label = $m['label'];
	echo "— {$label} … ";
	$original = null;
	try {
		$original = apply_mutation($m);
		$dead = run_kill_check($phpunit, $m);
		restore_file($m['file'], $original);
		if ($dead) {
			echo "KILLED\n";
			$killed++;
		} else {
			echo "SURVIVED\n";
			$failed[] = $label;
			$tail = isset($GLOBALS['lastMutationPhpunitOut']) ? (string)$GLOBALS['lastMutationPhpunitOut'] : '';
			fwrite(STDERR, substr($tail, -800) . "\n");
		}
	} catch (Throwable $e) {
		if ($original !== null) {
			restore_file($m['file'], $original);
		}
		echo 'ERROR: ' . $e->getMessage() . "\n";
		$failed[] = $label . ' (error)';
	}
}

echo "\nKilled {$killed}/" . count($mutations) . "\n";
if ($failed !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $failed) . "\n");
	exit(1);
}
