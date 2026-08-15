<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Service\AppSettingsSectionCatalog;
use OCA\CustomerCheck\Service\WorkspaceSettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cross-artifact drift protection for split Workspace + App settings sub-pages.
 */
final class SettingsPagesContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private static function read(string $relative): string
	{
		$path = self::appRoot() . '/' . $relative;
		self::assertFileExists($path);

		return (string)file_get_contents($path);
	}

	/** @return list<array<string, mixed>> */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);

		return $config['routes'];
	}

	private static function routeByName(string $name): array
	{
		foreach (self::routes() as $route) {
			if (($route['name'] ?? '') === $name) {
				return $route;
			}
		}
		self::fail("Route '{$name}' is not registered");
	}

	public function testWorkspaceRouteAllowlistMatchesCatalog(): void
	{
		$route = self::routeByName('page#settingsSection');
		self::assertSame('/settings/{section}', $route['url']);
		self::assertSame(
			WorkspaceSettingsSectionCatalog::routeRequirement(),
			$route['requirements']['section'] ?? null,
		);
	}

	public function testAppRouteAllowlistMatchesCatalog(): void
	{
		$route = self::routeByName('page#appSettingsSection');
		self::assertSame('/app-settings/{section}', $route['url']);
		self::assertSame(
			AppSettingsSectionCatalog::routeRequirement(),
			$route['requirements']['section'] ?? null,
		);
	}

	public function testWorkspaceDispatcherIsFailClosedLiteralMap(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertMatchesRegularExpression('/\$crmSettingsSectionFiles\s*=\s*\[/', $dispatcher);
		self::assertStringContainsString(
			"if (!isset(\$crmSettingsSectionFiles[\$crmRequestedSection]))",
			$dispatcher,
		);
		self::assertStringContainsString('unknown section reached the template dispatcher', $dispatcher);
		self::assertStringNotContainsString('$_[\'settingsSection\'] . \'.php\'', $dispatcher);
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $section) {
			self::assertStringContainsString("'" . $section . "'", $dispatcher);
		}
	}

	public function testAppDispatcherIsFailClosedLiteralMap(): void
	{
		$dispatcher = self::read('templates/app-settings.php');
		self::assertMatchesRegularExpression('/\$crmAppSettingsSectionFiles\s*=\s*\[/', $dispatcher);
		self::assertStringContainsString(
			"if (!isset(\$crmAppSettingsSectionFiles[\$crmRequestedSection]))",
			$dispatcher,
		);
		self::assertStringContainsString('unknown section reached the template dispatcher', $dispatcher);
		foreach (AppSettingsSectionCatalog::SECTIONS as $section) {
			self::assertStringContainsString("'" . $section . "'", $dispatcher);
		}
	}

	public function testLegacyJsAnchorsMatchCatalog(): void
	{
		$wsJs = self::read('js/workspace-settings-legacy-redirect.js');
		foreach (WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertStringContainsString("'" . $anchor . "': '" . $section . "'", $wsJs);
		}
		$appJs = self::read('js/app-settings-legacy-redirect.js');
		foreach (AppSettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertStringContainsString("'" . $anchor . "': '" . $section . "'", $appJs);
		}
	}

	public function testSettingsJsConsumesLegacyRedirectsFirst(): void
	{
		$js = self::read('js/pages/settings.js');
		self::assertStringContainsString('CustomerCheckWorkspaceSettingsLegacyRedirect', $js);
		self::assertStringContainsString('CustomerCheckAppSettingsLegacyRedirect', $js);
		$legacyPos = strpos($js, 'CustomerCheckWorkspaceSettingsLegacyRedirect');
		$rootPos = strpos($js, "getElementById('crm-settings-root')");
		self::assertNotFalse($legacyPos);
		self::assertNotFalse($rootPos);
		self::assertLessThan($rootPos, $legacyPos);
	}

	public function testPageChromeExposesSectionDataAttributes(): void
	{
		$start = self::read('templates/common/page-start.php');
		self::assertStringContainsString('data-crm-settings-section', $start);
		self::assertStringContainsString('data-crm-app-settings-section', $start);
		self::assertStringContainsString("\$_['settingsSection'] ?? ''", $start);
		self::assertStringContainsString('breadcrumbParent', $start);
	}

	public function testControllerWiresCatalogsAndSectionUrls(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertStringContainsString('settingsSection(string $section)', $controller);
		self::assertStringContainsString('appSettingsSection(string $section)', $controller);
		self::assertStringContainsString('WorkspaceSettingsSectionCatalog', $controller);
		self::assertStringContainsString('AppSettingsSectionCatalog', $controller);
		self::assertStringContainsString('workspace-settings-legacy-redirect', $controller);
		self::assertStringContainsString('app-settings-legacy-redirect', $controller);
		$shell = self::read('lib/Controller/PageShellTrait.php');
		self::assertStringContainsString("'settingsSections'", $shell);
		self::assertStringContainsString("'appSettingsSections'", $shell);
	}
}
