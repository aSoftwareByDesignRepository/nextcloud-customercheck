<?php

declare(strict_types=1);

/**
 * Mutations: list workspace fail-closed + pipeline in-place view switch.
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;

function finding_phpunit_command(string $filter): string
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
			return 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
				. ' exec -T -u www-data nextcloud php ' . escapeshellarg($dockerPhpunit)
				. ' --configuration ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter)
				. ' --cache-result-file=/tmp/cc-phpunit-findings.cache';
		}
	}
	$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,tool:string,filter?:string}>
 */
function finding_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Support/ListWorkspaceGuard.php',
			'from' => 'return $workspaceId > 0;',
			'to' => 'return $workspaceId >= 0;',
			'label' => 'zero_workspace_becomes_listable',
			'tool' => 'phpunit',
			'filter' => 'ListWorkspaceGuardTest',
		],
		[
			'file' => $appRoot . '/lib/Support/ListWorkspaceGuard.php',
			'from' => 'if ((int)($filters[\'companyId\'] ?? 0) > 0) {',
			'to' => 'if ((int)($filters[\'companyId\'] ?? 0) >= 0) {',
			'label' => 'zero_company_id_counts_as_scoped',
			'tool' => 'phpunit',
			'filter' => 'ListWorkspaceGuardTest::testChildListAllowsCompanyOrDealWithoutWorkspace',
		],
		[
			'file' => $appRoot . '/lib/Db/CompanyMapper.php',
			'from' => "\t\t} else {\n\t\t\t// Fail closed: unscoped company search is every book.\n\t\t\t\$qb->andWhere(\$qb->expr()->eq(\$qb->createNamedParameter(1), \$qb->createNamedParameter(0)));\n\t\t}",
			'to' => '',
			'label' => 'company_search_unscoped_else_dropped',
			'tool' => 'phpunit',
			'filter' => 'UnscopedMapperSearchContractTest::testCompanySearchFailClosesWithoutWorkspace',
		],
		[
			'file' => $appRoot . '/lib/Db/DealMapper.php',
			'from' => '} elseif ($companyId <= 0) {',
			'to' => '} elseif ($companyId < 0) {',
			'label' => 'deal_search_zero_company_not_fail_closed',
			'tool' => 'phpunit',
			'filter' => 'UnscopedMapperSearchContractTest::testDealSearchFailClosesWithoutWorkspaceOrCompany',
		],
		[
			'file' => $appRoot . '/lib/Db/ActivityMapper.php',
			'from' => '} elseif ($companyId <= 0 && $dealId <= 0) {',
			'to' => '} elseif (false && $companyId <= 0 && $dealId <= 0) {',
			'label' => 'activity_search_unscoped_else_disabled',
			'tool' => 'phpunit',
			'filter' => 'UnscopedMapperSearchContractTest::testActivitySearchFailClosesWithoutWorkspaceCompanyOrDeal',
		],
		[
			'file' => $appRoot . '/lib/Db/PersonMapper.php',
			'from' => '} elseif ($companyId <= 0) {',
			'to' => '} elseif (false && $companyId <= 0) {',
			'label' => 'person_search_unscoped_else_disabled',
			'tool' => 'phpunit',
			'filter' => 'UnscopedMapperSearchContractTest::testPersonSearchFailClosesWithoutWorkspaceOrCompany',
		],
		[
			'file' => $appRoot . '/lib/Db/AgreementMapper.php',
			'from' => '} elseif ($companyId <= 0 && $companyIds === []) {',
			'to' => '} elseif (false && $companyId <= 0 && $companyIds === []) {',
			'label' => 'agreement_search_unscoped_else_disabled',
			'tool' => 'phpunit',
			'filter' => 'UnscopedMapperSearchContractTest::testAgreementSearchFailClosesWithoutWorkspaceOrCompany',
		],
		[
			'file' => $appRoot . '/lib/Db/AgreementMapper.php',
			'from' => 'if (is_array($workspaceIds)) {
			$this->restrictToWorkspaces($qb, $workspaceIds);
		} elseif ($workspaceId > 0) {',
			'to' => 'if (false && is_array($workspaceIds)) {
			$this->restrictToWorkspaces($qb, $workspaceIds);
		} elseif ($workspaceId > 0) {',
			'label' => 'agreement_workspace_ids_ignored',
			'tool' => 'phpunit',
			'filter' => 'UnscopedMapperSearchContractTest::testAgreementSearchFailClosesWithoutWorkspaceOrCompany',
		],
		[
			'file' => $appRoot . '/lib/Db/CompanyMapper.php',
			'from' => "->andWhere(\$qb->expr()->eq('workspace_id', \$qb->createNamedParameter(\$workspaceId, IQueryBuilder::PARAM_INT)))\n\t\t\t->andWhere(\$qb->expr()->isNull('archived_at'))",
			'to' => "->andWhere(\$qb->expr()->isNull('archived_at'))",
			'label' => 'name_lookup_drops_workspace_predicate',
			'tool' => 'phpunit',
			'filter' => 'UnscopedMapperSearchContractTest::testCompanyNameLookupRequiresWorkspace',
		],
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => 'if (ListWorkspaceGuard::isListable($wsId)) {
			$filters = [\'archived\' => $archived, \'workspaceId\' => $wsId];',
			'to' => 'if (true) {
			$filters = [\'archived\' => $archived, \'workspaceId\' => $wsId];',
			'label' => 'companies_search_unguarded',
			'tool' => 'phpunit',
			'filter' => 'ListWorkspaceGuardContractTest',
		],
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => '$companyCount = ListWorkspaceGuard::isListable($wsId)
			? $this->companies->count([\'archived\' => false, \'workspaceId\' => $wsId])
			: 0;',
			'to' => '$companyCount = true
			? $this->companies->count([\'archived\' => false, \'workspaceId\' => $wsId])
			: 0;',
			'label' => 'today_company_count_unguarded',
			'tool' => 'phpunit',
			'filter' => 'ListWorkspaceGuardContractTest',
		],
		[
			'file' => $appRoot . '/js/common/pipeline-view-core.js',
			'from' => "if (v === 'board' || v === 'table') {\n      return v;\n    }\n    return '';",
			'to' => "if (v === 'board' || v === 'table' || v) {\n      return v;\n    }\n    return '';",
			'label' => 'accepts_arbitrary_pipeline_view',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/pages/pipeline.js',
			'from' => "const PipelineView = window.CustomerCheckPipelineView;\n  if (PipelineView && typeof PipelineView.bind === 'function' && Api && typeof Api.put === 'function') {\n    PipelineView.bind({",
			'to' => "const PipelineView = window.CustomerCheckPipelineView;\n  if (false && PipelineView && typeof PipelineView.bind === 'function' && Api && typeof Api.put === 'function') {\n    PipelineView.bind({",
			'label' => 'drops_in_place_pipeline_view_bind',
			'tool' => 'phpunit',
			'filter' => 'PipelineToolbarContractTest::testPipelineViewSwitchesInPlaceWithoutReload',
		],
	];
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

$killed = 0;
$failed = 0;
foreach (finding_mutations($appRoot) as $m) {
	fwrite(STDOUT, "=== MUTATION: {$m['label']} ===\n");
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "anchor missing for {$m['label']}\n");
		exit(1);
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $src));
	$code = 0;
	if (($m['tool'] ?? '') === 'vitest') {
		passthru('npm --prefix ' . escapeshellarg($appRoot) . ' run test:js -- tests/js/pipeline-view-core.test.js', $code);
	} else {
		passthru(finding_phpunit_command((string)($m['filter'] ?? '')), $code);
	}
	file_put_contents($m['file'], $src);
	if ((int)$code === 0) {
		fwrite(STDOUT, "SURVIVED (bad)\n");
		$failed++;
	} else {
		fwrite(STDOUT, "CAUGHT (good)\n");
		$killed++;
	}
}

fwrite(STDOUT, "Finding mutations: {$killed}/" . ($killed + $failed) . " killed\n");
exit($failed > 0 ? 1 : 0);
