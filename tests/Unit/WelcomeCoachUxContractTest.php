<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Welcome coach — BudgetCheck-style step cards on CRM callout tokens.
 * Pins granny UX, WCAG targets, trigger wiring, and responsive layout.
 */
final class WelcomeCoachUxContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testWelcomeCoachUsesStepCardsWithSafeTriggers(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$start = strpos($html, 'crm-welcome-coach" id="crm-welcome-coach"');
		if ($start === false) {
			$start = strpos($html, 'id="crm-welcome-coach"');
		}
		$this->assertNotFalse($start);
		$end = strpos($html, 'id="crm-communication"', (int)$start);
		$this->assertNotFalse($end);
		$chunk = substr($html, max(0, (int)$start - 80), $end - max(0, (int)$start - 80));

		$this->assertStringContainsString('crm-callout crm-callout--info crm-welcome-coach', $chunk);
		$this->assertStringContainsString('role="region"', $chunk);
		$this->assertStringContainsString('aria-labelledby="crm-welcome-coach-title"', $chunk);
		$this->assertStringContainsString('crm-welcome-coach__header', $chunk);
		$this->assertStringContainsString('crm-welcome-coach__lead', $chunk);
		$this->assertStringContainsString('Three short steps to make this customer useful.', $chunk);
		$this->assertStringContainsString('crm-welcome-coach__steps', $chunk);
		$this->assertStringContainsString('crm-welcome-coach__step', $chunk);
		$this->assertStringContainsString('crm-welcome-coach__step-num', $chunk);
		$this->assertStringContainsString('crm-welcome-coach__step--done', $chunk);
		$this->assertStringContainsString('IconCatalog::renderCalloutWell', $chunk);
		$this->assertStringContainsString('data-crm-trigger="crm-add-person"', $chunk);
		$this->assertStringContainsString('data-crm-trigger="crm-add-deal"', $chunk);
		$this->assertStringContainsString('data-crm-trigger="crm-log-email"', $chunk);
		$this->assertStringContainsString('data-crm-trigger="crm-schedule-callback"', $chunk);
		$this->assertStringContainsString('$hasPersonWithEmail', (string)file_get_contents($this->root . '/templates/company-detail.php'));
		$this->assertStringContainsString('crm-btn--lg', $chunk);
		$this->assertStringNotContainsString('crm-welcome-coach__actions', $chunk);
		$this->assertStringNotContainsString('crm-callout__text', $chunk);
		$this->assertSame(1, substr_count($chunk, 'data-crm-trigger="crm-add-person"'));
		$this->assertSame(1, substr_count($chunk, 'data-crm-trigger="crm-add-deal"'));
		$this->assertSame(1, substr_count($chunk, 'data-crm-trigger="crm-log-email"'));
		$this->assertSame(1, substr_count($chunk, 'data-crm-trigger="crm-schedule-callback"'));
	}

	public function testWelcomeCoachCssIsResponsiveTokenSafeAndTouchFriendly(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$start = strpos($css, '/* Welcome coach');
		$this->assertNotFalse($start);
		$chunk = substr($css, $start, 4500);

		$this->assertStringContainsString('.crm-callout.crm-welcome-coach', $chunk);
		$this->assertStringContainsString('flex-direction: column', $chunk);
		$this->assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr))', $chunk);
		$this->assertStringContainsString('min-height: var(--crm-touch, 44px)', $chunk);
		$this->assertStringContainsString('@media (forced-colors: active)', $chunk);
		$this->assertStringContainsString('crm-welcome-coach__step--done', $chunk);
		$this->assertStringContainsString('var(--color-primary-element', $chunk);
		$this->assertStringContainsString('var(--crm-border', $chunk);
	}

	public function testTriggerDelegationStillResolvesWelcomeCoachTargets(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString("closest('[data-crm-trigger]')", $js);
		$this->assertStringContainsString('document.getElementById(targetId)', $js);
		$this->assertStringContainsString('id="crm-add-person"', (string)file_get_contents($this->root . '/templates/company-detail.php'));
		$this->assertStringContainsString('id="crm-log-email"', (string)file_get_contents($this->root . '/templates/company-detail.php'));
	}
}
