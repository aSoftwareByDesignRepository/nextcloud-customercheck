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
		$this->assertStringContainsString('crm-settings-switch', $src);
		$this->assertStringContainsString("'preferences' => 'preferences.php'", $src);
		$this->assertStringContainsString("'mailbox' => 'workspace-mailbox.php'", $src);
		$this->assertStringContainsString("'integrations' => 'integrations.php'", $src);
		$this->assertStringNotContainsString("'access'", $src);
		$this->assertStringNotContainsString("'tokens'", $src);
		$this->assertStringNotContainsString("'license'", $src);
		$this->assertStringNotContainsString("'jobs'", $src);
	}

	public function testAppSettingsHostsPolicySections(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/app-settings.php');
		$this->assertStringContainsString("'access' => 'access.php'", $src);
		$this->assertStringContainsString("'jobs' => 'jobs.php'", $src);
		$this->assertStringContainsString("'tokens' => 'tokens.php'", $src);
		$this->assertStringContainsString("'license' => 'license.php'", $src);
		$this->assertStringContainsString("'product-codes' => 'product-codes.php'", $src);
		$this->assertStringContainsString('crm-settings-switch', $src);
		$this->assertStringNotContainsString('workspace-mailbox', $src);
		$this->assertStringNotContainsString('preferences.php', $src);
	}

	public function testAppSettingsRouteIsRegistered(): void
	{
		$routes = include dirname(__DIR__, 2) . '/appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		$this->assertContains('page#appSettings', $names);
		$this->assertContains('page#appSettingsSection', $names);
		$this->assertContains('page#settingsSection', $names);
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
		$this->assertStringContainsString('Team & mailbox', $nav);
		$this->assertStringContainsString('App settings', $nav);
		$this->assertStringContainsString("u('appSettings')", $nav);
		$this->assertStringContainsString('$canManageNav', $nav);
	}
}
