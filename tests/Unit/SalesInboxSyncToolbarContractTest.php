<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Inbox "Update mail" must be tab-aware: Personal + Team sync; Unassigned has no folder sync.
 */
final class SalesInboxSyncToolbarContractTest extends TestCase
{
	public function testSyncVisibilityIsNotMineOnly(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('function syncableBoxesForActiveTab', $js);
		$this->assertStringContainsString('function syncButtonVisibility', $js);
		// Regression: old Bachus gate hid the button on Team entirely.
		$this->assertStringNotContainsString(
			"personalMailEnabled && personalBoxes.length > 0 && activeTab === 'mine'",
			$js,
		);
		$this->assertStringContainsString("activeTab === 'unmatched'", $js);
		$this->assertStringContainsString("'/personal-mailboxes/' + id + '/sync'", $js);
		$this->assertStringContainsString("'/mailboxes/' + id + '/sync'", $js);
	}

	public function testAccountFilterUsesPhpBakedLabels(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/sales-inbox.php');
		$this->assertStringContainsString('data-crm-all-personal', $html);
		$this->assertStringContainsString('data-crm-all-team', $html);
		$this->assertStringContainsString('data-crm-aria-personal', $html);
		$this->assertStringContainsString('data-crm-aria-team', $html);
		$this->assertStringContainsString('Fetch new messages for this tab', $html);
	}

	public function testClickCapturesActiveTabForSyncRace(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('void syncPersonal({ tab: activeTab })', $js);
		$this->assertStringContainsString('if (syncInFlight) return false', $js);
		$this->assertStringContainsString('if (activeTab === tab)', $js);
	}
}
