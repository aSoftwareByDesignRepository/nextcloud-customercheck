<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Settings must stay split: Workspace vs App (BudgetCheck pattern).
 */
final class SettingsSplitContractTest extends TestCase
{
	public function testWorkspaceSettingsOmitsAppAdminMonolithSections(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings.php');
		$this->assertStringContainsString("settings/preferences.php", $src);
		$this->assertStringContainsString("settings/workspace-mailbox.php", $src);
		$this->assertStringContainsString("settings/integrations.php", $src);
		$this->assertStringContainsString('crm-settings-switch', $src);
		$this->assertStringNotContainsString("settings/access.php", $src);
		$this->assertStringNotContainsString("settings/tokens.php", $src);
		$this->assertStringNotContainsString("settings/license.php", $src);
		$this->assertStringNotContainsString("settings/jobs.php", $src);
	}

	public function testAppSettingsHostsPolicySections(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/app-settings.php');
		$this->assertStringContainsString("settings/access.php", $src);
		$this->assertStringContainsString("settings/jobs.php", $src);
		$this->assertStringContainsString("settings/tokens.php", $src);
		$this->assertStringContainsString("settings/license.php", $src);
		$this->assertStringContainsString("settings/product-codes.php", $src);
		$this->assertStringContainsString('crm-settings-switch', $src);
		$this->assertStringNotContainsString("settings/workspace-mailbox.php", $src);
		$this->assertStringNotContainsString("settings/preferences.php", $src);
	}

	public function testAppSettingsRouteIsRegistered(): void
	{
		$routes = include dirname(__DIR__, 2) . '/appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		$this->assertContains('page#appSettings', $names);
		$urls = [];
		foreach ($routes['routes'] as $route) {
			if (($route['name'] ?? '') === 'page#appSettings') {
				$urls[] = $route['url'] ?? '';
			}
		}
		$this->assertContains('/app-settings', $urls);
	}

	public function testNavListsBothSettingsEntriesForAdmins(): void
	{
		$nav = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString('Workspace settings', $nav);
		$this->assertStringContainsString('App settings', $nav);
		$this->assertStringContainsString("u('appSettings')", $nav);
		$this->assertStringContainsString('$canManageNav', $nav);
	}
}
