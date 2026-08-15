<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: My customers Add customer header CTA.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-my-accounts-add-customer-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
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
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function my_accounts_add_customer_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/templates/my-accounts.php',
			'from' => 'id="crm-my-accounts-add"',
			'to' => 'id="crm-my-accounts-add-missing"',
			'label' => 'rename_add_customer_id',
			'filter' => 'MyAccountsAddCustomerUxContractTest::testMyAccountsTemplateExposesAddCustomerHeaderAction',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "href: OC.generateUrl('/apps/customercheck/companies/new') + '?workspaceId=' + ws,\n      label: t('Add customer'),",
			'to' => "href: OC.generateUrl('/apps/customercheck/companies') + '?workspaceId=' + ws,\n      label: t('Browse companies'),",
			'label' => 'revert_empty_cta_to_browse',
			'filter' => 'MyAccountsAddCustomerUxContractTest::testMyAccountsEmptyStateLinksToCompanyNew',
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
		throw new RuntimeException('Mutation anchor missing for ' . $m['label']);
	}
	$next = str_replace($m['from'], $m['to'], $src);
	if ($next === $src) {
		throw new RuntimeException('Mutation did not change file for ' . $m['label']);
	}
	file_put_contents($m['file'], $next);
	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = my_accounts_add_customer_mutations($appRoot);
$caught = 0;
$failed = [];
echo 'My-accounts add-customer mutations: ' . count($mutations) . "\n";
foreach ($mutations as $m) {
	echo "\n=== MUTATION: {$m['label']} ===\n";
	$original = apply_mutation($m);
	try {
		$code = run_phpunit($phpunit, $appRoot, $m['filter']);
		if ($code === 0) {
			$failed[] = $m['label'] . ' (survived)';
			echo "SURVIVED (bad)\n";
		} else {
			$caught++;
			echo "CAUGHT (good)\n";
		}
	} finally {
		restore_file($m['file'], $original);
	}
}
echo "\nResult: {$caught}/" . count($mutations) . " caught\n";
if ($failed !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $failed) . "\n");
	exit(1);
}
exit(0);
