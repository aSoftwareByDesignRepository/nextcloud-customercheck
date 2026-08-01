<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio §2.1 — dedicated App Admin UX + BudgetCheck OR-semantics in source.
 */
final class DedicatedAppAdminContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testAccessTemplateUsesDirectoryChipsForAppAdmins(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/settings/access.php');
		$this->assertStringContainsString('id="crm-app-admins-search"', $html);
		$this->assertStringContainsString('id="crm-app-admins-chips"', $html);
		$this->assertStringContainsString('id="crm-app-admins-suggest"', $html);
		$this->assertStringContainsString('selectedAppAdmins', $html);
		$this->assertStringNotContainsString('id="crm-app-admins-list"', $html);
		$this->assertStringNotContainsString('Only Nextcloud admins are listed', $html);
		$this->assertStringContainsString('without making them a Nextcloud admin', $html);
		$this->assertStringContainsString('never type a raw user id', $html);
	}

	public function testSettingsJsSubmitsAppAdminChipsNotCheckboxes(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('bindUserChipPicker', $js);
		$this->assertStringContainsString("chipListId: 'crm-app-admins-chips'", $js);
		$this->assertStringContainsString('appAdminChips.selectedUids()', $js);
		$this->assertStringNotContainsString('#crm-app-admins-list input[type="checkbox"]', $js);
	}

	public function testIsAppAdminUsesSystemAdminOrDedicatedList(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/AccessControlService.php');
		$start = strpos($src, 'public function isAppAdmin(string $userId): bool');
		$this->assertNotFalse($start);
		$end = strpos($src, 'public function canUseApp', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertStringContainsString('if ($this->isSystemAdmin($userId))', $body);
		$this->assertStringContainsString('return true;', $body);
		$this->assertStringContainsString('return in_array($userId, $this->getAppAdminIds(), true);', $body);
		$this->assertStringNotContainsString('if (!$this->isSystemAdmin($userId))', $body);
	}

	public function testPageControllerResolvesSelectedAppAdmins(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'selectedAppAdmins'", $src);
		$this->assertStringContainsString('resolveUsers($appAdminUserIds)', $src);
		$this->assertStringNotContainsString('availableAppAdmins()', $src);
	}
}
