<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Bachus: company 360 is four granny zones + role cue, not a random inventory. */
final class Company360ZonesContractTest extends TestCase
{
	public function testFourZonesAndRoleCueExist(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		foreach (['data-crm-zone="now"', 'data-crm-zone="sell"', 'data-crm-zone="money"', 'data-crm-zone="later"'] as $z) {
			$this->assertStringContainsString($z, $html);
		}
		$this->assertStringContainsString('How to use this page', $html);
		$this->assertStringContainsString('Look-only role', $html);
		$this->assertStringContainsString('You can edit. Work top to bottom', $html);
		$this->assertStringContainsString('1 · Talk & people', $html);
		$this->assertStringContainsString('2 · Sell & keep', $html);
		$this->assertStringContainsString('3 · Money & files', $html);
		$this->assertStringContainsString('4 · Setup & history', $html);
	}

	public function testJumpPrimariesMatchWorkOrder(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = strpos($html, '$jumpNavItems');
		$end = strpos($html, 'crm-jump-nav.php', (int)$start);
		$chunk = substr($html, (int)$start, (int)$end - (int)$start);
		foreach (["'#crm-glance'", "'#crm-communication'", "'#crm-persons'", "'#crm-deals'", "'#crm-billing'"] as $href) {
			$this->assertStringContainsString($href, $chunk);
			$this->assertStringContainsString("'primary' => true", $chunk);
		}
		$this->assertLessThan(strpos($chunk, "'#crm-communication'"), strpos($chunk, "'#crm-glance'"));
		$this->assertLessThan(strpos($chunk, "'#crm-persons'"), strpos($chunk, "'#crm-communication'"));
		$this->assertLessThan(strpos($chunk, "'#crm-deals'"), strpos($chunk, "'#crm-persons'"));
		$this->assertLessThan(strpos($chunk, "'#crm-billing'"), strpos($chunk, "'#crm-deals'"));
	}

	public function testIdentitySetupIsCollapsedDetails(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-identity-links"', $html);
		$this->assertStringContainsString('crm-card--collapsible" id="crm-identity-links"', $html);
		$this->assertStringContainsString('Link apps (setup)', $html);
		$this->assertStringContainsString('$identityForceOpen', $html);
	}

	public function testDomOrderPutsWorkBeforeSetup(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$persons = strpos($html, 'id="crm-persons"');
		$deals = strpos($html, 'id="crm-deals"');
		$billing = strpos($html, 'id="crm-billing"');
		$identity = strpos($html, 'id="crm-identity-links"');
		$plan = strpos($html, 'id="crm-account-plan"');
		$this->assertNotFalse($persons);
		$this->assertGreaterThan($persons, $deals);
		$this->assertGreaterThan($deals, $billing);
		$this->assertGreaterThan($billing, $plan);
		$this->assertGreaterThan($plan, $identity);
	}
}
