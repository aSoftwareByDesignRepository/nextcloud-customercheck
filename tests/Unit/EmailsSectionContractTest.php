<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmailsSectionContractTest extends TestCase
{
	public function testPartialTeachesWorkflowAndShowsWithWhomColumns(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('crm-howto', $src);
		$this->assertStringContainsString('How this works', $src);
		$this->assertStringContainsString('Last logged contact', $src);
		$this->assertStringContainsString('Logged messages', $src);
		$this->assertStringNotContainsString('CRM email log', $src);
		$this->assertStringContainsString('data-crm-trigger="crm-log-email"', $src);
		$this->assertStringContainsString('Tap Log email under Talk', $src);
		$this->assertStringContainsString('withLabel', $src);
		$this->assertStringContainsString('directionLabel', $src);
		$this->assertStringContainsString('sourceLabel', $src);
		$this->assertStringContainsString('mailRelated', $src);
		$this->assertStringContainsString('data-crm-mail-mode', $src);
		$this->assertStringContainsString('Communication above', $src);
		$this->assertStringNotContainsString('id="crm-log-email"', $src);
	}

	public function testCompanyDetailIncludesEmailsPartialAndClarifiesActivities(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-emails-section.php', $html);
		$this->assertStringNotContainsString('<section class="crm-card" id="crm-emails"', $html);
		$partial = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('Who wrote what', $partial);
		$this->assertStringContainsString('crm-more-menu', $html);
		$this->assertStringContainsString('companyId=', $html);
	}

	public function testLogDialogUsesPersonAndSubjectOnly(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("name: 'personId'", $js);
		$this->assertStringContainsString("name: 'subject'", $js);
		$this->assertStringContainsString('You wrote them', $js);
		$this->assertStringNotContainsString("name: 'fromAddr'", $js);
		$this->assertStringContainsString('Add a person first', $js);
		// Bachus: no people → confirm opens Add person (never alert-only dead end).
		$this->assertStringContainsString("confirmLabel: t('Add person')", $js);
		$this->assertStringContainsString("t('Not now')", $js);
		// Single contact skips "With whom"; direction stays under More.
		$this->assertStringContainsString('personOptions.length > 1', $js);
		$this->assertMatchesRegularExpression(
			"/name: 'direction'[\s\S]*?advanced: true/",
			$js,
		);
	}
}
