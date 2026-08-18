<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Collapsible CRM cards must expose an obvious Open/Close affordance (not a hidden chevron).
 */
final class CollapsibleCardUxContractTest extends TestCase
{
	public function testDisclosurePartialUsesOpenCloseLabelsAndTouchTarget(): void
	{
		$partial = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-collapsible-disclosure.php');
		$this->assertStringContainsString('crm-card__disclosure', $partial);
		$this->assertStringContainsString('crm-card__disclosure-label--closed', $partial);
		$this->assertStringContainsString('crm-card__disclosure-label--open', $partial);
		$this->assertStringContainsString('crm-card__disclosure-icon', $partial);
		$this->assertStringContainsString("\$l->t('Open')", $partial);
		$this->assertStringContainsString("\$l->t('Close')", $partial);
		$this->assertStringContainsString('aria-hidden="true"', $partial);
	}

	public function testAccountPlanIsAlwaysVisibleSectionNotCollapsible(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('<section class="crm-card crm-card--account-plan" id="crm-account-plan"', $detail);
		$this->assertStringContainsString('<header class="crm-card__header">', $detail);
		$this->assertStringNotContainsString('<details class="crm-card crm-card--collapsible" id="crm-account-plan"', $detail);
		$start = (int)strpos($detail, 'id="crm-account-plan"');
		$chunk = substr($detail, $start, 700);
		$this->assertStringNotContainsString('crm-collapsible-disclosure.php', $chunk);
	}

	public function testAllCompany360CollapsibleCardsUseDisclosurePartial(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		foreach ([
			'crm-identity-links' => '<details class="crm-card crm-card--collapsible" id="crm-identity-links"',
			'crm-timeline' => '<details class="crm-card crm-card--collapsible" id="crm-timeline"',
		] as $id => $needle) {
			$pos = (int)strpos($detail, $needle);
			$this->assertNotFalse($pos, $id . ' must exist on company 360');
			$chunk = substr($detail, $pos, 800);
			$this->assertStringContainsString('crm-collapsible-disclosure.php', $chunk, $id);
			$this->assertStringContainsString('crm-card__header--summary', $chunk, $id);
		}
	}

	public function testEmailsSectionSummaryIncludesDisclosure(): void
	{
		$emails = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('crm-collapsible-disclosure.php', $emails);
		$this->assertStringContainsString('crm-card__header--summary', $emails);
	}

	public function testCollapsibleCssUsesFlexSummaryAndOpenCloseLabels(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-card__disclosure', $css);
		$this->assertStringContainsString('.crm-card__disclosure-label--closed', $css);
		$this->assertStringContainsString('.crm-card__disclosure-label--open', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-card__disclosure[^{]*\{[^}]*min-width:\s*44px/s',
			$css,
			'Disclosure pill must meet WCAG 2.5.5 minimum width',
		);
		$this->assertStringContainsString('display: flex', $css);
		$this->assertStringContainsString('details:not([open]) > summary .crm-card__disclosure-label--open', $css);
		$this->assertStringContainsString('details[open] > summary .crm-card__disclosure-label--closed', $css);
		$this->assertStringNotContainsString('crm-card--collapsible > summary.crm-card__header--summary::after', $css);
		$this->assertMatchesRegularExpression(
			'/summary\.crm-card__header--summary[^{]*\{[^}]*display:\s*flex/s',
			$css,
			'Collapsible summaries must stay flex rows so the Open pill is visible',
		);
	}

	public function testAccountPlanUsesMoreOptionsDisclosureInJs(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('crm-advanced crm-account-plan__more', $js);
		$this->assertStringContainsString("t('More options')", $js);
		$this->assertStringNotContainsString('html += planDisclosureHtml();', $js);
		$this->assertStringNotContainsString('function planDisclosureHtml()', $js);
		$this->assertDoesNotMatchRegularExpression(
			'/crm-card__disclosure-label--closed">\s*\'\s*\+\s*\'\'\s*\+/',
			$js,
		);
	}

	public function testLinkedBundleSummaryIncludesDisclosure(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-linked-bundle', $detail);
		$pos = (int)strpos($detail, 'crm-linked-bundle__summary');
		$this->assertNotFalse($pos);
		$chunk = substr($detail, $pos, 600);
		$this->assertStringContainsString('crm-collapsible-disclosure.php', $chunk);
	}
}
