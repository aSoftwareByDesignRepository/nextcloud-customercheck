<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: account plan design-system layout.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-account-plan-layout-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'AccountPlan(Layout|SaveGuard)ContractTest';

function account_plan_layout_phpunit_command(string $phpunit, string $config, string $filter): string
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
				. ' --filter ' . escapeshellarg($filter);
		}
	}
	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,replace_all?:bool}>
 */
function account_plan_layout_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'function accountPlanStepsHtml(plan, mapSteps) {',
			'to' => 'function accountPlanStepsHtmlBroken(plan, mapSteps) {',
			'label' => 'drops_steps_helper',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '#app-content.crm-app .crm-account-plan {
	width: 100%;
	max-width: none;',
			'to' => '#app-content.crm-app .crm-account-plan {
	width: 100%;
	max-width: 42rem;',
			'label' => 'narrows_account_plan_to_42rem',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => '<section class="crm-card crm-card--account-plan" id="crm-account-plan"',
			'to' => '<details class="crm-card crm-card--collapsible" id="crm-account-plan"',
			'label' => 'reverts_to_collapsible_details',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "html += '<div class=\"crm-account-plan__col crm-account-plan__col--moves\">';\n      html += renderMovesSection(mapSteps, true);",
			'to' => "html += '<div class=\"crm-account-plan__col crm-account-plan__col--plan\">';\n      html += renderMovesSection(mapSteps, true);",
			'label' => 'moves_render_in_plan_column',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => '<div class="crm-account-plan__stack">',
			'to' => '<div class="crm-page-grid crm-account-plan__grid">',
			'label' => 'reverts_stack_to_two_column_grid',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '#app-content.crm-app .crm-account-plan__stack {
	display: flex;
	flex-direction: column;',
			'to' => '#app-content.crm-app .crm-account-plan__stack {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));',
			'label' => 'stack_becomes_two_column_grid',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '@media (min-width: 720px) {
	#app-content.crm-app .crm-account-plan__guide.crm-welcome-coach__steps {
		grid-template-columns: repeat(3, minmax(0, 1fr));
	}
}',
			'to' => '@media (min-width: 720px) {
	#app-content.crm-app .crm-account-plan__guide.crm-welcome-coach__steps {
		grid-template-columns: minmax(0, 1fr);
	}
}',
			'label' => 'guide_stays_one_column_on_wide',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '#app-content.crm-app .crm-account-plan__panel-title {
	display: flex;
	flex-direction: row;
	align-items: center;',
			'to' => '#app-content.crm-app .crm-account-plan__panel-title {
	display: flex;
	flex-direction: row;
	align-items: flex-start;',
			'label' => 'panel_title_top_aligns_number',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'crm-welcome-coach__steps crm-account-plan__guide',
			'to' => 'crm-account-plan__steps',
			'label' => 'reverts_to_custom_steps_strip',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "form.setAttribute('aria-busy', 'true')",
			'to' => "form.setAttribute('aria-busy', 'false')",
			'label' => 'drops_save_busy_guard',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'function accountPlanOwnerSearchLabel(plan) {',
			'to' => 'function accountPlanOwnerSearchLabelBroken(plan) {',
			'label' => 'drops_owner_display_helper',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'crm-advanced crm-account-plan__more',
			'to' => 'crm-plan-more',
			'label' => 'reverts_more_options_to_plan_more',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'loadAccountPlan(savedPlan)',
			'to' => 'loadAccountPlan()',
			'label' => 'ignores_put_payload_and_refetches',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'id="crm-plan-save-status"',
			'to' => 'id="crm-plan-save-status-missing"',
			'label' => 'drops_saved_status_id',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "C.showToast({ type: 'success'",
			'to' => "C.showToast({ type: 'info'",
			'label' => 'success_toast_becomes_info',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '#app-content.crm-app .crm-account-plan__saved {
	margin: 0;
	padding: 0.85rem 1rem;
	font-size: 1.1rem;
	font-weight: 700;
	line-height: 1.35;
	min-height: var(--crm-touch, 44px);
}',
			'to' => '#app-content.crm-app .crm-account-plan__saved {
	margin: 0;
	min-height: 8px;
}',
			'label' => 'saved_banner_loses_touch_target',
		],
	];
}

function apply_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "MUTATION SETUP FAIL {$m['label']}: from-string not found\n");
		exit(2);
	}
	$pos = strpos($src, $m['from']);
	file_put_contents($m['file'], substr_replace($src, $m['to'], $pos, strlen($m['from'])));
}

function revert_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	$pos = strpos($src, $m['to']);
	if ($pos === false) {
		fwrite(STDERR, "MUTATION REVERT FAIL {$m['label']}\n");
		exit(2);
	}
	file_put_contents($m['file'], substr_replace($src, $m['from'], $pos, strlen($m['to'])));
}

$baselineCmd = account_plan_layout_phpunit_command($phpunit, $config, $filter);
exec($baselineCmd . ' 2>&1', $baselineOut, $baselineCode);
if ($baselineCode !== 0) {
	fwrite(STDERR, "BASELINE FAIL — AccountPlanLayoutContractTest must be green before mutations\n");
	fwrite(STDERR, implode("\n", array_slice($baselineOut, -30)) . "\n");
	exit(2);
}
fwrite(STDOUT, "BASELINE OK {$filter}\n");

$failed = 0;
$killed = 0;
foreach (account_plan_layout_mutations($appRoot) as $m) {
	apply_mutation($m);
	exec($baselineCmd . ' 2>&1', $out, $code);
	revert_mutation($m);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED mutation {$m['label']}\n");
		$failed++;
	} else {
		fwrite(STDOUT, "KILLED {$m['label']}\n");
		$killed++;
	}
	$out = [];
}

fwrite(STDOUT, "Account plan layout mutations: killed={$killed} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
