<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: platform hub chips (enabled sibling apps only).
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
$config = $appRoot . '/phpunit.xml';

function platform_links_phpunit_command(string $phpunit, string $config, string $filter): string
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
				. ' --cache-result-file=/tmp/cc-phpunit-platform-links.cache';
		}
	}
	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
}

function platform_links_vitest_command(): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	return 'npm --prefix ' . escapeshellarg($appRoot)
		. ' run test:js -- tests/js/platform-links-core.test.js';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string,tool:string,filter?:string}>
 */
function platform_links_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/PlatformLinksService.php',
			'from' => 'if (!$this->appOpenForUser($appId)) {
				continue;
			}',
			'to' => 'if ($appId !== \'files\' && !$this->appOpenForUser($appId)) {
				continue;
			}',
			'label' => 'files_chip_forced_without_app',
			'tool' => 'phpunit',
			'filter' => 'PlatformLinksServiceTest',
		],
		[
			'file' => $appRoot . '/lib/Service/PlatformLinksService.php',
			'from' => 'if (!$this->appManager->isInstalled($appId)) {
				return false;
			}',
			'to' => 'if (false && !$this->appManager->isInstalled($appId)) {
				return false;
			}',
			'label' => 'skips_installed_check',
			'tool' => 'phpunit',
			'filter' => 'PlatformLinksServiceTest',
		],
		[
			'file' => $appRoot . '/lib/Service/PlatformLinksService.php',
			'from' => 'if (trim($userId) === \'\') {
			return [];
		}',
			'to' => 'if (false && trim($userId) === \'\') {
			return [];
		}',
			'label' => 'empty_user_still_gets_chips',
			'tool' => 'phpunit',
			'filter' => 'PlatformLinksServiceTest',
		],
		[
			'file' => $appRoot . '/js/common/platform-links-core.js',
			'from' => "if (parsed.origin !== expectedOrigin) {\n      return '';\n    }",
			'to' => "if (false && parsed.origin !== expectedOrigin) {\n      return '';\n    }",
			'label' => 'accepts_off_origin_hrefs',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'CustomerCheckPlatformLinks.enabledChips(links, origin)',
			'to' => 'CustomerCheckPlatformLinks.missingChips(links, origin)',
			'label' => 'drops_enabled_chips_filter',
			'tool' => 'phpunit',
			'filter' => 'PlatformLinksContractTest',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'id="crm-platform-links" hidden',
			'to' => 'id="crm-platform-links"',
			'label' => 'nav_starts_visible_with_placeholder',
			'tool' => 'phpunit',
			'filter' => 'PlatformLinksContractTest',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => '#app-content.crm-app .crm-platform-links[hidden],
#app-content.crm-app .crm-platform-links:empty {
	display: none;
	margin: 0;
}',
			'to' => '#app-content.crm-app .crm-platform-links[hidden],
#app-content.crm-app .crm-platform-links:empty {
	display: flex;
	margin: 0;
}',
			'label' => 'hidden_strip_still_displays',
			'tool' => 'phpunit',
			'filter' => 'PlatformLinksContractTest',
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

function run_mutation_cmd(array $m, string $phpunit, string $config): string
{
	if (($m['tool'] ?? '') === 'vitest') {
		return platform_links_vitest_command();
	}
	return platform_links_phpunit_command($phpunit, $config, (string)($m['filter'] ?? 'PlatformLinks'));
}

$baselinePhp = platform_links_phpunit_command($phpunit, $config, 'PlatformLinks(Service|Contract)Test');
exec($baselinePhp . ' 2>&1', $baselineOut, $baselineCode);
if ($baselineCode !== 0) {
	fwrite(STDERR, "BASELINE FAIL — PlatformLinks tests must be green before mutations\n");
	fwrite(STDERR, implode("\n", array_slice($baselineOut, -40)) . "\n");
	exit(2);
}
fwrite(STDOUT, "BASELINE OK PlatformLinks PHPUnit\n");

exec(platform_links_vitest_command() . ' 2>&1', $vitestOut, $vitestCode);
if ($vitestCode !== 0) {
	fwrite(STDERR, "BASELINE FAIL — platform-links-core vitest\n");
	fwrite(STDERR, implode("\n", array_slice($vitestOut, -40)) . "\n");
	exit(2);
}
fwrite(STDOUT, "BASELINE OK platform-links vitest\n");

$failed = 0;
$killed = 0;
foreach (platform_links_mutations($appRoot) as $m) {
	apply_mutation($m);
	$cmd = run_mutation_cmd($m, $phpunit, $config);
	exec($cmd . ' 2>&1', $out, $code);
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

fwrite(STDOUT, "Platform links mutations: killed={$killed} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
