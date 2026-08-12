<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus: empty Linked apps = status roster; identity CTAs merge in when nothing linked.
 */
final class LinkedAppsBundleUxContractTest extends TestCase
{
	public function testCollapsedBundleUsesStatusRosterNotNestedEmptyCards(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-linked-bundle', $detail);
		$this->assertStringContainsString('crm-linked-status', $detail);
		$this->assertStringContainsString("\$l->t('Link apps')", $detail);
		$this->assertStringContainsString("\$l->t('One ProjectCheck or TicketCheck link unlocks projects, tickets, and invoices.')", $detail);
		$this->assertStringContainsString("\$l->t('Not installed')", $detail);
		$this->assertStringContainsString("\$l->t('Linked · none open')", $detail);
		$this->assertStringContainsString("\$l->t('From ProjectCheck')", $detail);
		$this->assertStringContainsString("\$l->t('From TicketCheck')", $detail);
		$this->assertStringContainsString("\$l->t('From InvoiceCheck (via ProjectCheck link)')", $detail);

		$start = (int)strpos($detail, 'id="crm-linked-bundle"');
		$end = (int)strpos($detail, '</details>', $start);
		$chunk = substr($detail, $start, max(0, $end - $start));
		$this->assertStringContainsString('crm-linked-status__row', $chunk);
		$this->assertStringContainsString('id="crm-projects"', $chunk);
		$this->assertStringContainsString('id="crm-tickets"', $chunk);
		$this->assertStringContainsString('id="crm-invoices"', $chunk);
		$this->assertStringContainsString('id="crm-identity-links"', $chunk);
		$this->assertStringContainsString('crm-identity-rows.php', $chunk);
		$this->assertStringNotContainsString('crm-card--nested', $chunk);
		$this->assertStringNotContainsString('crm-linked-bundle__setup', $chunk);
		$this->assertSame(3, substr_count($chunk, 'crm-linked-status__row'));
	}

	public function testCssDefinesTouchFriendlyStatusRows(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-linked-status__row', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		$identityCss = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/identity-links.css');
		$this->assertStringContainsString('.crm-linked-bundle--setup', $identityCss);
	}
}
