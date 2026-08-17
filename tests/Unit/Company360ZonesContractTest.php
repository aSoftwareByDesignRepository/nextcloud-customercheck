<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Bachus: company 360 is five focus tabs, not a long scroll with zone labels. */
final class Company360ZonesContractTest extends TestCase
{
	public function testFiveFocusPanelsExist(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		foreach (['today', 'people', 'deals', 'money', 'more'] as $panel) {
			$this->assertStringContainsString('data-crm-focus-panel="' . $panel . '"', $html);
		}
		$this->assertStringNotContainsString('data-crm-zone="now"', $html);
		$this->assertStringNotContainsString('2 · Sell & keep', $html);
		$this->assertStringNotContainsString('How to use this page', $html);
	}

	public function testFocusTabLabelsInTemplate(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString("\$l->t('Today')", $html);
		$this->assertStringContainsString("['id' => 'people', 'label' => \$l->t('People')]", $html);
		$this->assertStringContainsString("\$l->t('Deals')", $html);
		$this->assertStringContainsString("\$l->t('Bills & files')", $html);
		$this->assertStringContainsString("\$l->t('Plan & links')", $html);
	}

	public function testIdentitySetupIsCollapsedDetails(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-identity-links"', $html);
		$this->assertStringContainsString('$identityMergedIntoBundle', $html);
		$this->assertStringContainsString('$identityForceOpen', $html);
		$this->assertStringContainsString('crm-identity-rows.php', $html);
		$this->assertStringContainsString('One ProjectCheck or TicketCheck link unlocks', $html);
		$this->assertStringNotContainsString('Link apps (setup)', $html);
	}

	public function testDomOrderPutsWorkBeforeSetup(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$persons = strpos($html, 'id="crm-persons"');
		$nextSteps = strpos($html, 'id="crm-next-steps"');
		$deals = strpos($html, 'id="crm-deals"');
		$billing = strpos($html, 'id="crm-billing"');
		$identity = strpos($html, 'id="crm-identity-links"');
		$plan = strpos($html, 'id="crm-account-plan"');
		$this->assertNotFalse($persons);
		$this->assertGreaterThan($nextSteps, $persons, 'People tab follows Today work');
		$this->assertGreaterThan($persons, $deals);
		$this->assertGreaterThan($deals, $billing);
		$this->assertGreaterThan($billing, $identity);
		$this->assertGreaterThan($identity, $plan);
	}
}
