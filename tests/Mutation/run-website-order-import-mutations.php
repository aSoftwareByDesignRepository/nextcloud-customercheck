<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: website-order / multi-agreement import expansion + apply coalesce.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-website-order-import-mutations.php
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
function website_order_import_mutations(string $appRoot): array
{
	$svc = $appRoot . '/lib/Service/ImportService.php';
	return [
		[
			'file' => $svc,
			'from' => "private function isExpandableAgreementPayload(array \$data): bool\n\t{\n\t\tif (isset(\$data['company']) && is_array(\$data['company'])) {\n\t\t\treturn true;\n\t\t}\n\t\t\$agreements = \$data['agreements'] ?? null;",
			'to' => "private function isExpandableAgreementPayload(array \$data): bool\n\t{\n\t\treturn false;\n\t\t\$agreements = \$data['agreements'] ?? null;",
			'label' => 'disable_expandable_agreement_detection',
			'filter' => 'ImportServiceParseTest::testWebsiteOrderJsonExpandsAgreementsWithSeatsAndContact',
		],
		[
			'file' => $svc,
			'from' => "'mobileSeats' => (int)(\$limits['mobileSeats'] ?? \$agreement['mobileSeats'] ?? \$agreement['seats'] ?? 0),\n\t\t\t\t'terminalDevices' => (int)(\$limits['terminalDevices'] ?? \$agreement['terminalDevices'] ?? \$agreement['devices'] ?? 0),",
			'to' => "'mobileSeats' => (int)(\$limits['mobileSeats'] ?? \$agreement['mobileSeats'] ?? 0),\n\t\t\t\t'terminalDevices' => (int)(\$limits['terminalDevices'] ?? \$agreement['terminalDevices'] ?? 0),",
			'label' => 'drop_seats_devices_aliases',
			'filter' => 'ImportServiceParseTest::testWebsiteOrderJsonExpandsAgreementsWithSeatsAndContact',
		],
		[
			'file' => $svc,
			'from' => "if (\$action === 'create' && \$identityKey !== '' && isset(\$batchCompanyIds[\$identityKey])) {\n\t\t\t\t\t\$companyId = \$batchCompanyIds[\$identityKey];\n\t\t\t\t} elseif (\$action === 'create') {",
			'to' => "if (\$action === 'create') {",
			'label' => 'drop_batch_company_coalesce',
			'filter' => 'ImportWebsiteOrderIntegrationTest::testGdprMultiAgreementApplyDoesNotSlugTaken',
		],
		[
			'file' => $svc,
			'from' => "if (\$contactName === '') {\n\t\t\t\$contactName = trim((string)(\$payload['contactName'] ?? \$company['contactName'] ?? ''));\n\t\t}\n\t\tif (\$email === '') {\n\t\t\t\$email = trim((string)(\$payload['email'] ?? \$company['email'] ?? ''));\n\t\t}",
			'to' => '',
			'label' => 'drop_top_level_contact_fallback',
			'filter' => 'ImportServiceParseTest::testWebsiteOrderJsonExpandsAgreementsWithSeatsAndContact',
		],
		[
			'file' => $svc,
			'from' => "'startsAt' => trim(\$row['startsAt'] ?? ''),\n\t\t\t'validUntil' => trim(\$row['validUntil'] ?? ''),",
			'to' => "'validUntil' => trim(\$row['validUntil'] ?? ''),",
			'label' => 'drop_normalize_starts_at',
			'filter' => 'ImportServiceParseTest::testWebsiteOrderJsonExpandsAgreementsWithSeatsAndContact',
		],
		[
			'file' => $svc,
			'from' => "'label' => trim(\$row['label'] ?? ''),\n\t\t\t'mobileSeats' => (int)(\$row['mobileSeats'] ?? 0),",
			'to' => "'mobileSeats' => (int)(\$row['mobileSeats'] ?? 0),",
			'label' => 'drop_normalize_label',
			'filter' => 'ImportServiceParseTest::testWebsiteOrderJsonExpandsAgreementsWithSeatsAndContact',
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
	file_get_contents($m['file']); // touch read
	// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
	// a kill-test that is red unmutated turns nonzero exits into fake kills.
	require_once __DIR__ . '/MutationBaseline.php';
	mutationBaselineSelfCheck(__FILE__);
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

$mutations = website_order_import_mutations($appRoot);
$caught = 0;
$failed = [];

echo "Website-order import mutations: " . count($mutations) . "\n";

foreach ($mutations as $m) {
	echo "\n=== MUTATION: {$m['label']} ===\n";
	$original = apply_mutation($m);
	try {
		$code = run_phpunit($phpunit, $appRoot, $m['filter']);
		if ($code === 0) {
			$failed[] = $m['label'] . ' (survived — tests still green)';
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
