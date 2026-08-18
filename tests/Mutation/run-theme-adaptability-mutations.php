<?php

declare(strict_types=1);

/**
 * Mutations: theme tokens, high-contrast dialogs, OAuth standalone HTML.
 *
 * Host apply/revert (UID 1000). PHPUnit via Docker when available.
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function theme_adaptability_phpunit_command(string $phpunit, string $filter, string $testFile): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	$composeDir = dirname($appRoot, 2);
	$dockerPhpunit = '/var/www/html/custom_apps/customercheck/vendor/bin/phpunit';
	$dockerConfig = '/var/www/html/custom_apps/customercheck/phpunit.xml';
	$dockerTestFile = '/var/www/html/custom_apps/customercheck/' . ltrim($testFile, '/');
	if (is_file($composeDir . '/docker-compose.yml')) {
		$probe = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";');
		exec($probe . ' 2>/dev/null', $probeOut, $probeCode);
		if ($probeCode === 0) {
			return 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
				. ' exec -T -u www-data nextcloud php ' . escapeshellarg($dockerPhpunit)
				. ' -c ' . escapeshellarg($dockerConfig)
				. ' ' . escapeshellarg($dockerTestFile)
				. ' --filter ' . escapeshellarg($filter);
		}
	}
	return escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' ' . escapeshellarg($appRoot . '/' . ltrim($testFile, '/'))
		. ' --filter ' . escapeshellarg($filter);
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
 * @return list<array{file:string,from:string,to:string,label:string,filter:string,testFile:string}>
 */
function theme_adaptability_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Controller/PersonaApiController.php',
			'from' => "\$lang = method_exists(\$this->l10n, 'getLanguageCode') ? (string)\$this->l10n->getLanguageCode() : 'en';",
			'to' => "\$lang = 'en';",
			'label' => 'oauth_callback_hardcodes_html_lang_en',
			'testFile' => 'tests/Unit/Controller/PersonalMailOAuthNativeCallbackControllerTest.php',
			'filter' => 'testNativeStateReturnsHtmlWithoutExchange',
		],
		[
			'file' => $appRoot . '/lib/Controller/PersonaApiController.php',
			'from' => 'body{font-family:system-ui,sans-serif;margin:2rem;line-height:1.5;color:CanvasText;background:Canvas}',
			'to' => 'body{font-family:system-ui,sans-serif;margin:2rem;line-height:1.5;color:#111;background:#fff}',
			'label' => 'oauth_callback_hardcodes_light_hex',
			'testFile' => 'tests/Unit/Controller/PersonalMailOAuthNativeCallbackControllerTest.php',
			'filter' => 'testNativeStateReturnsHtmlWithoutExchange',
		],
		[
			'file' => $appRoot . '/css/common/tokens.css',
			'from' => '--crm-scrim: color-mix(in srgb, var(--color-main-text) 45%, transparent);',
			'to' => '--crm-scrim: #000000;',
			'label' => 'scrim_token_hardcodes_black',
			'testFile' => 'tests/Unit/ThemeAdaptabilityContractTest.php',
			'filter' => 'testRadiusLgMapsToNextcloudBorderRadiusLarge',
		],
		[
			'file' => $appRoot . '/css/common/tokens.css',
			'from' => '--crm-radius-lg: var(--border-radius-large, 16px);',
			'to' => '--crm-radius-lg: 16px;',
			'label' => 'radius_lg_drops_nc_mapping',
			'testFile' => 'tests/Unit/ThemeAdaptabilityContractTest.php',
			'filter' => 'testRadiusLgMapsToNextcloudBorderRadiusLarge',
		],
		[
			'file' => $appRoot . '/css/common/accessibility.css',
			'from' => "body[data-theme-light-highcontrast] dialog.crm-native-dialog .crm-btn,\nbody[data-theme-dark-highcontrast] dialog.crm-native-dialog .crm-btn,\n",
			'to' => '',
			'label' => 'high_contrast_drops_dialog_buttons',
			'testFile' => 'tests/Unit/ThemeAdaptabilityContractTest.php',
			'filter' => 'testHighContrastCoversDialogsAndSettingsPanels',
		],
		[
			'file' => $appRoot . '/css/common/components.css',
			'from' => 'background-color: var(--crm-scrim, color-mix(in srgb, var(--color-main-text) 45%, transparent));',
			'to' => 'background-color: color-mix(in srgb, var(--color-main-text) 50%, transparent);',
			'label' => 'modal_backdrop_skips_scrim_token',
			'testFile' => 'tests/Unit/ThemeAdaptabilityContractTest.php',
			'filter' => 'testModalAndLoadingScrimsUseToken',
		],
		[
			'file' => $appRoot . '/css/common/utilities.css',
			'from' => '--radius-md: var(--crm-radius-md, 12px);',
			'to' => '--radius-md: var(--crm-radius-sm, 6px);',
			'label' => 'utility_radius_md_collapses_to_sm',
			'testFile' => 'tests/Unit/ThemeAdaptabilityContractTest.php',
			'filter' => 'testUtilityRadiusMdFollowsDesignSystemTwelvePx',
		],
	];
}

function apply_mutation(array $m): string
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('Mutation anchor missing for ' . $m['label'] . ' in ' . $m['file']);
	}
	$next = str_replace($m['from'], $m['to'], $src);
	if ($next === $src) {
		throw new RuntimeException('Mutation did not change file for ' . $m['label']);
	}
	file_put_contents($m['file'], $next);
	return $src;
}

$killed = 0;
$failed = 0;
foreach (theme_adaptability_mutations($appRoot) as $m) {
	fwrite(STDOUT, "=== MUTATION: {$m['label']} ===\n");
	$original = apply_mutation($m);
	$cmd = theme_adaptability_phpunit_command($phpunit, $m['filter'], $m['testFile']);
	passthru($cmd, $code);
	file_put_contents($m['file'], $original);
	if ((int)$code === 0) {
		fwrite(STDOUT, "SURVIVED (bad)\n");
		$failed++;
	} else {
		fwrite(STDOUT, "CAUGHT (good)\n");
		$killed++;
	}
}

fwrite(STDOUT, "Result: {$killed}/" . ($killed + $failed) . " caught\n");
exit($failed > 0 ? 1 : 0);
