<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: signature logo URL policy (AS-18 / Argus SF-01).
 *
 * Host (from customercheck/):
 *   php tests/Mutation/run-signature-logo-mutations.php
 *
 * Docker (from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-signature-logo-mutations.php
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
 * @return list<array{file:string,from:string,to:string,label:string,kind:string,filter?:string,vitest?:string}>
 */
function signature_logo_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/SignatureLogoUrlPolicy.php',
			'from' => "if (preg_match('#^https://#i', \$src) === 1) {\n\t\t\treturn true;\n\t\t}",
			'to' => "if (preg_match('#^https://#i', \$src) === 1) {\n\t\t\treturn false;\n\t\t}",
			'label' => 'policy_https_gate_inverted',
			'kind' => 'phpunit',
			'filter' => 'SignatureLogoUrlPolicyTest',
		],
		[
			'file' => $appRoot . '/lib/Service/SignatureLogoUrlPolicy.php',
			'from' => "if (\$allowed !== '' && \$allowed === \$host) {\n\t\t\t\treturn true;\n\t\t\t}",
			'to' => "if (\$allowed !== '' && \$allowed === \$host) {\n\t\t\t\treturn false;\n\t\t\t}",
			'label' => 'policy_same_origin_http_denied',
			'kind' => 'phpunit',
			'filter' => 'SignatureLogoUrlPolicyTest::testHttpSameOriginPublicShareAllowed',
		],
		[
			'file' => $appRoot . '/lib/Service/SignatureLogoUrlPolicy.php',
			'from' => "if (!self::isNextcloudPublicSharePath((string)\$parts['path'])) {\n\t\t\treturn false;\n\t\t}",
			'to' => "if (false && !self::isNextcloudPublicSharePath((string)\$parts['path'])) {\n\t\t\treturn false;\n\t\t}",
			'label' => 'policy_share_path_gate_removed',
			'kind' => 'phpunit',
			'filter' => 'SignatureLogoUrlPolicyTest::testHttpSameOriginNonSharePathBlocked',
		],
		[
			'file' => $appRoot . '/lib/Service/HtmlSanitizeService.php',
			'from' => "if (!SignatureLogoUrlPolicy::isAllowedSrc(\$src, \$this->allowedHttpLogoHosts())) {\n\t\t\treturn null;\n\t\t}",
			'to' => "if (false && !SignatureLogoUrlPolicy::isAllowedSrc(\$src, \$this->allowedHttpLogoHosts())) {\n\t\t\treturn null;\n\t\t}",
			'label' => 'sanitize_drops_policy_gate',
			'kind' => 'phpunit',
			'filter' => 'HtmlSanitizeServiceTest::testSignatureAllowsHttpsImgNotHttpOrData',
		],
		[
			'file' => $appRoot . '/js/common/signature-logo-core.js',
			'from' => "if (/^https:\\/\\//i.test(src)) {\n      return true;\n    }",
			'to' => "if (/^https:\\/\\//i.test(src)) {\n      return false;\n    }",
			'label' => 'js_https_gate_inverted',
			'kind' => 'vitest',
			'vitest' => 'tests/js/signature-logo-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/signature-logo-core.js',
			'from' => 'function isNextcloudPublicSharePath(pathname) {',
			'to' => 'function isNextcloudPublicSharePathDISABLED(pathname) {',
			'label' => 'js_share_path_helper_renamed',
			'kind' => 'vitest',
			'vitest' => 'tests/js/signature-logo-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/signature-logo-core.js',
			'from' => 'function isReusablePublicShareRow(row) {',
			'to' => 'function isReusablePublicShareRowDISABLED(row) {',
			'label' => 'js_reusable_share_helper_renamed',
			'kind' => 'vitest',
			'vitest' => 'tests/js/signature-logo-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/rich-mail-composer.js',
			'from' => 'pageOrigin: origin',
			'to' => "pageOrigin: ''",
			'label' => 'composer_drops_pageOrigin',
			'kind' => 'vitest',
			'vitest' => 'tests/js/personal-mail-ux-contracts.test.js',
		],
		[
			'file' => $appRoot . '/js/common/rich-mail-composer.js',
			'from' => "if (shell && shell.getAttribute('data-crm-logo-busy') === '1') {\n      return Promise.resolve();\n    }",
			'to' => '/* busy gate removed */',
			'label' => 'composer_drops_logo_busy_gate',
			'kind' => 'vitest',
			'vitest' => 'tests/js/personal-mail-ux-contracts.test.js',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$nextcloudRoot = dirname($appRoot, 2);
	// Prefer Docker (DB + NC bootstrap) when compose is available.
	if (is_file($nextcloudRoot . '/docker-compose.yml') || is_file($nextcloudRoot . '/compose.yml')) {
		$cmd = 'cd ' . escapeshellarg($nextcloudRoot)
			. ' && docker compose exec -u www-data -T nextcloud php '
			. '/var/www/html/custom_apps/customercheck/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/customercheck/phpunit.xml '
			. '--testsuite unit --filter ' . escapeshellarg($filter);
		passthru($cmd, $code);

		return (int)$code;
	}
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);

	return (int)$code;
}

function run_vitest(string $appRoot, string $spec): int
{
	$cmd = 'cd ' . escapeshellarg($appRoot) . ' && npm run test:js -- ' . escapeshellarg($spec);
	passthru($cmd, $code);

	return (int)$code;
}

$mutations = signature_logo_mutations($appRoot);
$failed = 0;
$killed = 0;
$skipped = 0;

foreach ($mutations as $m) {
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "SKIP missing needle: {$m['label']}\n");
		$skipped++;
		continue;
	}
	$count = 0;
	$mutated = str_replace($m['from'], $m['to'], $src, $count);
	if ($count < 1) {
		fwrite(STDERR, "SKIP replace failed: {$m['label']}\n");
		$skipped++;
		continue;
	}
	file_put_contents($m['file'], $mutated);
	try {
		if ($m['kind'] === 'vitest') {
			$code = run_vitest($appRoot, (string)$m['vitest']);
		} else {
			$code = run_phpunit($phpunit, $appRoot, (string)$m['filter']);
		}
	} finally {
		file_put_contents($m['file'], $src);
	}
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$m['label']}\n");
		$failed++;
	} else {
		fwrite(STDOUT, "KILLED  {$m['label']}\n");
		$killed++;
	}
}

$total = $killed + $failed;
$msi = $total > 0 ? round(100.0 * $killed / $total, 1) : 0.0;
fwrite(STDOUT, "MSI (signature-logo): {$msi}% killed={$killed} survived={$failed} skipped={$skipped}\n");
exit($failed > 0 || $skipped > 0 ? 1 : 0);
