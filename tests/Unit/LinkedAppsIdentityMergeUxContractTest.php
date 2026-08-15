<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus: cold company has ONE Link apps card — not Linked apps empty + Link apps (setup).
 */
final class LinkedAppsIdentityMergeUxContractTest extends TestCase
{
	public function testColdPathMergesIdentityIntoLinkedBundle(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('$identityMergedIntoBundle', $detail);
		$this->assertStringContainsString('crm-linked-bundle--setup', $detail);
		$this->assertStringContainsString('common/crm-identity-rows.php', $detail);
		$this->assertStringContainsString("\$l->t('One ProjectCheck or TicketCheck link unlocks projects, tickets, and invoices.')", $detail);
		$this->assertStringContainsString("\$l->t('App links')", $detail);
		$this->assertStringContainsString("\$l->t('Manage links')", $detail);
		$this->assertStringNotContainsString('crm-linked-bundle__setup', $detail);
		$this->assertStringNotContainsString('Link apps (setup)', $detail);
		$this->assertStringNotContainsString('Nothing linked yet', $detail);
	}

	public function testIdentityRowsDropDuplicateNotLinkedCopy(): void
	{
		$rows = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-identity-rows.php');
		$this->assertStringContainsString('crm-identity-row__app', $rows);
		$this->assertStringContainsString('crm-identity-row__head', $rows);
		$this->assertStringContainsString("\$l->t('ProjectCheck')", $rows);
		$this->assertStringContainsString("\$l->t('TicketCheck')", $rows);
		$this->assertStringContainsString('crm-identity-more', $rows);
		$this->assertStringNotContainsString('crm-identity-row__status', $rows);
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/identity-links.css');
		$this->assertStringContainsString('.crm-identity-row__head', $css);
		$this->assertStringContainsString('.crm-identity-more__summary', $css);
	}
}
