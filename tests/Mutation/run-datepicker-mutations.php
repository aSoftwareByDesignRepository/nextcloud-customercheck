<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: date/time selectors (calendar + hour/duration <select>).
 *
 * Host apply/revert (UID 1000). PHPUnit via Docker when available; Vitest on host.
 */

$appRoot = dirname(__DIR__, 2);
$GLOBALS['appRoot'] = $appRoot;
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function datepicker_phpunit_command(string $phpunit, string $filter): string
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
				. ' -c ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter)
				. ' --cache-result-file=/tmp/cc-phpunit-datepicker.cache';
		}
	}
	return escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
}

function datepicker_vitest_command(): string
{
	$appRoot = (string)($GLOBALS['appRoot'] ?? dirname(__DIR__, 2));
	return 'npm --prefix ' . escapeshellarg($appRoot)
		. ' run test:js -- tests/js/datepicker-core.test.js tests/js/datepicker-wrap.test.js';
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
 * @return list<array{file:string,from:string,to:string,label:string,tool:string,filter?:string}>
 */
function datepicker_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => 'return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;',
			'to' => 'return true;',
			'label' => 'accepts_impossible_calendar_days',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => 'return (n + 6) % 7;',
			'to' => 'return n % 7;',
			'label' => 'sunday_first_calendar_grid',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => "return y + '-' + m + '-' + d;",
			'to' => "return d + '-' + m + '-' + y;",
			'label' => 'iso_from_ymd_swaps_day_year',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => 'if (left === right) {',
			'to' => 'if (left !== right) {',
			'label' => 'compare_iso_equal_inverted',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => 'const snapped = Math.round(n / DURATION_STEP) * DURATION_STEP;',
			'to' => 'const snapped = n;',
			'label' => 'duration_skips_quarter_hour_snap',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => "const fromHidden = hidden && hidden.value != null ? toIso(hidden.value) : '';\n        if (fromHidden) {\n          return fromHidden;\n        }",
			'to' => "const fromHidden = hidden && hidden.value != null ? toIso(hidden.value) : '';\n        if (false && fromHidden) {\n          return fromHidden;\n        }",
			'label' => 'iso_value_ignores_hidden_source',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => "const fromDisplay = display && display.value != null ? toIso(display.value) : '';\n        if (fromDisplay) {\n          return fromDisplay;\n        }",
			'to' => "const fromDisplay = display && display.value != null ? toIso(display.value) : '';\n        if (false && fromDisplay) {\n          return fromDisplay;\n        }",
			'label' => 'iso_value_ignores_display_fallback',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => "if (options.insideOpenDialog) {\n      return 'dialog-host';\n    }\n    if (options.supportsPopover) {\n      return 'popover';\n    }\n    return 'body';",
			'to' => "if (false && options.insideOpenDialog) {\n      return 'dialog-host';\n    }\n    if (options.supportsPopover) {\n      return 'popover';\n    }\n    return 'body';",
			'label' => 'mount_skips_dialog_host_for_modals',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker-core.js',
			'from' => "if (options.supportsPopover) {\n      return 'popover';\n    }\n    return 'body';",
			'to' => "if (false && options.supportsPopover) {\n      return 'popover';\n    }\n    return 'body';",
			'label' => 'mount_skips_popover_on_page',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker.js',
			'from' => "container.setAttribute('popover', 'manual');",
			'to' => "container.setAttribute('popover', 'none');",
			'label' => 'calendar_popover_attr_disabled',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testDatepickerIsClassicIifeWithoutEval',
		],
		[
			'file' => $appRoot . '/lib/Support/DateMath.php',
			'from' => "if (\$parsed->format('Y-m-d') !== \$value) {\n\t\t\treturn null;\n\t\t}",
			'to' => "if (false && \$parsed->format('Y-m-d') !== \$value) {\n\t\t\treturn null;\n\t\t}",
			'label' => 'php_accepts_rolled_over_dates',
			'tool' => 'phpunit',
			'filter' => 'DateMathTest::testParseStrictDateRejectsImpossibleAndEuropean',
		],
		[
			'file' => $appRoot . '/lib/Service/FrontEndAssetService.php',
			'from' => "Util::addScript(Application::APP_ID, 'common/datepicker-core');\n\t\tUtil::addScript(Application::APP_ID, 'common/datepicker');",
			'to' => "Util::addScript(Application::APP_ID, 'common/datepicker');",
			'label' => 'drops_datepicker_core_script',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testDatepickerAssetsExistAndRegisterInOrder',
		],
		[
			'file' => $appRoot . '/js/common/datepicker.js',
			'from' => "hidden.setAttribute('data-crm-date-iso', '1');",
			'to' => "hidden.setAttribute('data-crm-date-skip', '1');",
			'label' => 'hidden_iso_field_loses_marker',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testDatepickerIsClassicIifeWithoutEval',
		],
		[
			'file' => $appRoot . '/js/common/datepicker.js',
			'from' => "input.setAttribute('role', 'combobox');\n    input.setAttribute('aria-autocomplete', 'none');",
			'to' => "input.setAttribute('role', 'textbox');\n    input.setAttribute('aria-autocomplete', 'none');",
			'label' => 'datepicker_display_loses_combobox_role',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testDatepickerIsClassicIifeWithoutEval',
		],
		[
			'file' => $appRoot . '/js/common/datepicker.js',
			'from' => "    // --flex only when the host is a direct child of a grid/flex row (not nested in form-group).\n    const inFlex = !!(parentEl && (\n      parentEl.classList.contains('crm-date-range__part')\n      || parentEl.classList.contains('crm-form-row-2')\n    ));",
			'to' => "    const inFlex = !!(parentEl && (\n      parentEl.classList.contains('crm-date-range__part')\n      || parentEl.classList.contains('crm-form-row-2')\n      || (parentEl.closest && parentEl.closest('.crm-form-row-2'))\n    ));",
			'label' => 'form_group_datepicker_gets_flex_width_auto',
			'tool' => 'vitest',
		],
		[
			'file' => $appRoot . '/js/common/datepicker.js',
			'from' => "if (btn.tabIndex >= 0) {\n            focusable.push(btn);\n          }",
			'to' => "focusable.push(btn);",
			'label' => 'tab_trap_includes_roving_days',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testDatepickerIsClassicIifeWithoutEval',
		],
		[
			'file' => $appRoot . '/js/pages/agreements.js',
			'from' => '\'<div class="form-group"><label class="form-label" for="\' + id + \'-minutes">\' + escapeHtml(t(\'Minutes\')) + \'</label><select class="crm-input form-select" id="\' + id + \'-minutes" name="minutes" required>\' + durationMinuteOptionsHtml(60) + \'</select><p class="form-help">\' + escapeHtml(t(\'Steps of 15. Default is one hour.\')) + \'</p></div>\' +',
			'to' => '\'<div class="form-group"><label class="form-label" for="\' + id + \'-minutes">\' + escapeHtml(t(\'Minutes\')) + \'</label><input class="crm-input form-input" id="\' + id + \'-minutes" name="minutes" type="number" min="15" step="15" value="60" required /><p class="form-help">\' + escapeHtml(t(\'Steps of 15. Default is one hour.\')) + \'</p></div>\' +',
			'label' => 'hours_minutes_reverts_to_number',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testHoursDurationUsesSelectNotFreeTyping',
		],
		[
			'file' => $appRoot . '/templates/settings/jobs.php',
			'from' => '<select id="crm-reminder-hour" name="reminder_hour" class="form-select crm-input" aria-describedby="crm-reminder-hour-help">',
			'to' => '<input type="number" id="crm-reminder-hour" name="reminder_hour" class="form-input crm-input" min="0" max="23" aria-describedby="crm-reminder-hour-help">',
			'label' => 'reminder_hour_reverts_to_number',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testClockHourSettingsUseSelectNotNumber',
		],
		[
			'file' => $appRoot . '/css/common/datepicker.css',
			'from' => 'width: min(24rem, calc(100vw - 1rem));',
			'to' => 'width: 100%;',
			'label' => 'calendar_popup_stretches_to_viewport',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testCalendarCssMeetsTouchAndContrastContracts',
		],
		[
			'file' => $appRoot . '/css/common/datepicker.css',
			'from' => 'min-height: var(--crm-touch, 44px);',
			'to' => 'min-height: 24px;',
			'label' => 'calendar_day_loses_44px_touch',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testCalendarCssMeetsTouchAndContrastContracts',
		],
		[
			'file' => $appRoot . '/css/common/icons.css',
			'from' => ".crm-datepicker-host {\n\tdisplay: flex;\n\talign-items: stretch;",
			'to' => ".crm-datepicker-host {\n\tdisplay: inline-block;\n\tposition: relative;\n\talign-items: stretch;",
			'label' => 'datepicker_host_reverts_to_absolute_overlay',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testCalendarCssMeetsTouchAndContrastContracts',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'id="crm-account-plan-form" class="crm-form crm-form--stack crm-account-plan__form" novalidate data-plan-updated-at="',
			'to' => 'id="crm-account-plan-form" class="crm-form crm-form--stack crm-account-plan__form" data-plan-updated-at="',
			'label' => 'account_plan_form_loses_novalidate',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testDynamicDateFieldsUseDatepickerClass',
		],
		[
			'file' => $appRoot . '/css/common/datepicker.css',
			'from' => ".crm-datepicker__day--outside {\n\t/* Opacity would drop --color-main-text below 4.5:1 (WCAG 1.4.3). */\n\tcolor: var(--color-text-maxcontrast);\n\tfont-weight: 500;\n}",
			'to' => ".crm-datepicker__day--outside {\n\topacity: 0.45;\n}",
			'label' => 'outside_days_use_opacity_failing_contrast',
			'tool' => 'phpunit',
			'filter' => 'DateSelectorContractTest::testCalendarCssMeetsTouchAndContrastContracts',
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

$killed = 0;
$failed = 0;
foreach (datepicker_mutations($appRoot) as $m) {
	fwrite(STDOUT, "=== MUTATION: {$m['label']} ===\n");
	$original = apply_mutation($m);
	if (($m['tool'] ?? 'phpunit') === 'vitest') {
		$cmd = datepicker_vitest_command();
	} else {
		$cmd = datepicker_phpunit_command($phpunit, (string)($m['filter'] ?? 'DateSelectorContractTest'));
	}
	passthru($cmd, $code);
	restore_file($m['file'], $original);
	if ((int)$code === 0) {
		fwrite(STDOUT, "SURVIVED (bad)\n");
		$failed++;
	} else {
		fwrite(STDOUT, "CAUGHT (good)\n");
		$killed++;
	}
}

fwrite(STDOUT, "Result: {$killed}/" . ($killed + $failed) . " caught\n");
if ($failed > 0) {
	fwrite(STDOUT, "Survivors:\n");
	exit(1);
}
exit(0);
