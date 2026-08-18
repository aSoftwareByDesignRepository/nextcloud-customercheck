<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Date and time fields must use a selector as the primary writer.
 * Native type="date" remains as a no-JS fallback; JS upgrades to the calendar.
 */
final class DateSelectorContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testDatepickerAssetsExistAndRegisterInOrder(): void
	{
		$this->assertFileExists($this->root . '/js/common/datepicker-core.js');
		$this->assertFileExists($this->root . '/js/common/datepicker.js');
		$this->assertFileExists($this->root . '/css/common/datepicker.css');

		$assets = (string)file_get_contents($this->root . '/lib/Service/FrontEndAssetService.php');
		$iconsPos = strpos($assets, "addScript(Application::APP_ID, 'common/navigation-icons')");
		$corePos = strpos($assets, "addScript(Application::APP_ID, 'common/datepicker-core')");
		$uiPos = strpos($assets, "addScript(Application::APP_ID, 'common/datepicker')");
		$filterPos = strpos($assets, "addScript(Application::APP_ID, 'common/filter-live-core')");
		$this->assertNotFalse($iconsPos);
		$this->assertNotFalse($corePos);
		$this->assertNotFalse($uiPos);
		$this->assertNotFalse($filterPos);
		$this->assertLessThan($corePos, $iconsPos);
		$this->assertLessThan($uiPos, $corePos);
		$this->assertLessThan($filterPos, $uiPos);

		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString("url('common/datepicker.css')", $appCss);
	}

	public function testDatepickerIsClassicIifeWithoutEval(): void
	{
		$core = (string)file_get_contents($this->root . '/js/common/datepicker-core.js');
		$ui = (string)file_get_contents($this->root . '/js/common/datepicker.js');
		$this->assertStringContainsString('(function (global)', $core);
		$this->assertStringContainsString('(function ()', $ui);
		$this->assertDoesNotMatchRegularExpression('/\beval\s*\(/', $core);
		$this->assertDoesNotMatchRegularExpression('/\beval\s*\(/', $ui);
		$this->assertStringNotContainsString('import(', $ui);
		$this->assertStringContainsString('data-crm-date-iso', $ui);
		$this->assertStringContainsString("hidden.setAttribute('data-crm-date-iso', '1')", $ui);
		$this->assertStringContainsString('role', $ui);
		$this->assertStringContainsString('dialog', $ui);
		$this->assertStringContainsString("createElement('table')", $ui);
		$this->assertStringNotContainsString("setAttribute('role', 'grid')", $ui);
		$this->assertStringNotContainsString("setAttribute('aria-selected'", $ui);
		$this->assertStringContainsString("setAttribute('aria-pressed', 'true')", $ui);
		$this->assertStringContainsString('btn.tabIndex >= 0', $ui);
		$this->assertStringContainsString('aria-haspopup', $ui);
		$this->assertStringContainsString("setAttribute('role', 'combobox')", $ui);
		$this->assertStringContainsString('data-datepicker-locked', $ui);
		$this->assertStringContainsString('setCustomValidity', $ui);
		$this->assertStringContainsString('bindFormDateGuard', $ui);
		$this->assertStringContainsString("input.type = 'text'", $ui);
		$this->assertStringContainsString('data-datepicker-required', $ui);
		$this->assertStringContainsString('CustomerCheckDatepicker', $ui);
		$this->assertStringContainsString('ISO_RE', $core);
		$this->assertStringContainsString('isRealYmd', $core);
		$this->assertStringContainsString('dt.getDate() === d', $core);
		$this->assertStringNotContainsString('Date.parse(', $core);
		$this->assertStringNotContainsString("new Date('", $core);
		$this->assertStringNotContainsString('toISOString(', $core);
		$this->assertStringContainsString('DURATION_STEP', $core);
	}

	public function testServerDateTemplatesKeepNativeDateFallbackAndDatepickerClass(): void
	{
		$agreement = (string)file_get_contents($this->root . '/templates/agreement-form.php');
		$pipeline = (string)file_get_contents($this->root . '/templates/pipeline.php');
		$this->assertStringContainsString('type="date"', $agreement);
		$this->assertStringContainsString('datepicker-input', $agreement);
		$this->assertStringContainsString('id="crm-ag-until"', $agreement);
		$this->assertStringContainsString('id="crm-ag-starts"', $agreement);
		$this->assertStringContainsString('datepicker-input', $pipeline);
		$this->assertStringContainsString('data-datepicker-min-from="crm-filter-from"', $pipeline);
		$this->assertStringContainsString('data-datepicker-max-from="crm-filter-to"', $pipeline);
	}

	public function testDynamicDateFieldsUseDatepickerClass(): void
	{
		$files = [
			'/js/pages/agreement-form.js',
			'/js/pages/agreements.js',
			'/js/pages/pipeline.js',
			'/js/pages/persona.js',
			'/js/pages/company-detail.js',
		];
		foreach ($files as $rel) {
			$src = (string)file_get_contents($this->root . $rel);
			if (!str_contains($src, 'type="date"') && !str_contains($src, "type: 'date'")) {
				continue;
			}
			$this->assertStringContainsString('datepicker-input', $src, $rel . ' date fields must opt into the calendar');
		}
		$detail = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString("f.type === 'date'", $detail);
		$this->assertStringContainsString('isoValue', $detail);
		$this->assertStringContainsString("id=\"crm-account-plan-form\" class=\"crm-form crm-form--stack crm-account-plan__form\" novalidate", $detail);
		$this->assertStringContainsString("document.getElementById('crm-plan-review')", $detail);
		$this->assertStringContainsString('form.isConnected', $detail);
		$this->assertStringContainsString('} finally {', $detail);
	}

	public function testHoursDurationUsesSelectNotFreeTyping(): void
	{
		$agreements = (string)file_get_contents($this->root . '/js/pages/agreements.js');
		$detail = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString('name="minutes"', $agreements);
		$this->assertStringContainsString('<select', $agreements);
		$this->assertStringContainsString('durationMinuteOptionsHtml', $agreements);
		$this->assertDoesNotMatchRegularExpression(
			'/name="minutes"[^>]*type="number"/',
			$agreements,
			'Hours minutes must be a select, not a number spinner',
		);
		$this->assertStringContainsString("type: 'select'", $detail);
		$this->assertStringContainsString('durationMinuteOptions', $detail);
		$this->assertStringContainsString('DURATION_MINUTES', $detail);
	}

	public function testClockHourSettingsUseSelectNotNumber(): void
	{
		$jobs = (string)file_get_contents($this->root . '/templates/settings/jobs.php');
		$this->assertStringContainsString('id="crm-reminder-hour"', $jobs);
		$this->assertStringContainsString('id="crm-digest-hour"', $jobs);
		$this->assertStringContainsString('<select id="crm-reminder-hour"', $jobs);
		$this->assertStringContainsString('<select id="crm-digest-hour"', $jobs);
		$this->assertStringNotContainsString('id="crm-reminder-hour" name="reminder_hour" class="form-input', $jobs);
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]+id="crm-reminder-hour"/',
			$jobs,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]+id="crm-digest-hour"/',
			$jobs,
		);
		$this->assertStringContainsString('for ($h = 0; $h <= 23; $h++)', $jobs);
		$this->assertGreaterThanOrEqual(2, substr_count($jobs, 'for ($h = 0; $h <= 23; $h++)'));
		$this->assertStringContainsString("sprintf('%02d:00'", $jobs);
		$this->assertStringContainsString('aria-describedby="crm-reminder-hour-help"', $jobs);
		$this->assertStringContainsString('aria-describedby="crm-digest-hour-help"', $jobs);
	}

	public function testCalendarCssMeetsTouchAndContrastContracts(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/datepicker.css');
		$icons = (string)file_get_contents($this->root . '/css/common/icons.css');
		$this->assertStringContainsString('.crm-datepicker', $css);
		$this->assertStringContainsString('min-height: var(--crm-touch, 44px)', $css);
		$this->assertStringContainsString(':focus-visible', $css);
		$this->assertStringContainsString('prefers-reduced-motion', $css);
		$this->assertStringContainsString('prefers-contrast: more', $css);
		$this->assertStringContainsString('z-index: 11000', $css);
		$this->assertStringContainsString('width: min(24rem, calc(100vw - 1rem))', $css);
		$this->assertStringContainsString('table.crm-datepicker__grid', $css);
		$this->assertStringContainsString('display: table-cell', $css);
		$this->assertStringContainsString('width: 14.2857%', $css);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-datepicker\s*\{[^}]*\bmin-width:\s*min\(20rem/s',
			$css,
			'Popup must set a definite width; min-width-only lets table 100% fill the viewport',
		);
		$this->assertStringContainsString('.crm-datepicker-host', $icons);
		$this->assertStringContainsString('js/common/datepicker.js', $icons);
		$this->assertStringContainsString('display: flex', $icons);
		$this->assertStringContainsString('align-items: stretch', $icons);
		$this->assertStringContainsString('align-self: center', $icons);
		$this->assertStringContainsString('min-width: 44px', $icons);
		$this->assertStringContainsString('min-height: 44px', $icons);
		$this->assertStringNotContainsString('padding-inline-end: 3rem', $icons);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-datepicker-toggle\s*\{[^}]*\bposition\s*:\s*absolute/s',
			$icons,
			'Calendar toggle must sit in the flex row, not overlay the input',
		);
		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.crm-form--stack .crm-datepicker-host .datepicker-input', $appCss);
		$this->assertStringContainsString('flex: 1 1 auto', $appCss);
		$this->assertStringContainsString('table-layout: fixed', $css);
		$this->assertStringContainsString('--color-text-maxcontrast', $css);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-datepicker__day--outside\s*\{[^}]*\bopacity\s*:/s',
			$css,
			'Outside-month days must not use opacity (fails WCAG 1.4.3 contrast)',
		);
		$patterns = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		$this->assertStringContainsString('#crm-pipeline-filter .crm-filter-grid', $patterns);
		$this->assertStringContainsString('minmax(12rem', $patterns);
	}

	public function testApiDateParserStaysIsoOnly(): void
	{
		$math = (string)file_get_contents($this->root . '/lib/Support/DateMath.php');
		$this->assertStringContainsString("createFromFormat('!Y-m-d'", $math);
		$this->assertStringNotContainsString('d.m.Y', $math);
		$hours = (string)file_get_contents($this->root . '/lib/Controller/HoursApiController.php');
		$this->assertStringContainsString('parseStrictDate', $hours);
		$this->assertStringContainsString('Use YYYY-MM-DD.', $hours);
	}
}
