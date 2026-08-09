<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Bachus: agreement edit is essentials-first — never dump More or Cancel by default. */
final class AgreementFormUxContractTest extends TestCase
{
	public function testEssentialsBeforeMoreAndNoForcedOpen(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/agreement-form.php');
		$this->assertStringContainsString('crm-ag-essentials', $html);
		$this->assertStringContainsString('What matters', $html);
		$this->assertStringContainsString('crm-ag-money-row', $html);
		$this->assertStringContainsString('crm-ag-lifecycle', $html);
		$this->assertStringContainsString('id="crm-agreement-end"', $html);
		$this->assertStringContainsString('Renew for one year', $html);
		$this->assertStringContainsString('End this agreement', $html);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-agreement-advanced"[^>]*\sopen/',
			$html,
		);
		$this->assertLessThan(strpos($html, 'crm-agreement-advanced'), strpos($html, 'crm-ag-amount'));
		$this->assertGreaterThan(strpos($html, 'crm-agreement-advanced'), strpos($html, 'crm-ag-invoice-status'));
	}

	public function testCreateDefaultsStartTodayInTemplateAndScript(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/agreement-form.php');
		$this->assertStringContainsString("DateTimeImmutable('today')", $html);
		$this->assertStringContainsString('Defaults to today', $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/agreement-form.js');
		$this->assertStringContainsString('todayIso', $js);
		$this->assertStringContainsString("form.dataset.mode === 'create'", $js);
	}

	public function testEditSaveOmitsForcedActiveStatus(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/agreement-form.js');
		$this->assertStringContainsString('goCompanyOrList', $js);
		$this->assertStringContainsString("body.status = 'active'", $js);
		// status:active only on create path — not a blanket body.status before mode branch.
		$this->assertDoesNotMatchRegularExpression(
			'/const body = \{[\s\S]*?status:\s*[\'"]active[\'"]/',
			$js,
		);
		// SECURITY: edit/create must not hardcode limits that wipe partner hoursQuota.
		$this->assertStringContainsString('buildAgreementSaveBody', $js);
		$this->assertStringNotContainsString('mobileSeats: 1', $js);
		$this->assertStringNotContainsString("limits: { mobileSeats", $js);
		$this->assertStringContainsString('never send limits', $js);
		$this->assertStringContainsString('data-terminal', (string)file_get_contents(dirname(__DIR__, 2) . '/templates/agreement-form.php'));
		$this->assertStringContainsString('setBusy', $js);
		$this->assertStringContainsString('escapeHtml', $js);
	}

	public function testEditUsesCompanyLinkNotDisabledSelect(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/agreement-form.php');
		$this->assertStringContainsString('crm-ag-company-link', $html);
		$this->assertStringContainsString('type="hidden" id="crm-ag-company"', $html);
		$this->assertStringContainsString('data-terminal', $html);
		$this->assertStringContainsString('AGREEMENT_TERMINAL_STATUSES', $html);
		// Company picker stays a real <select> on create only — never a disabled select on edit.
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-ag-company"[^>]*disabled/',
			$html,
		);
	}
}
