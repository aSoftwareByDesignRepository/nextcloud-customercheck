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

	public function testNoLegacyMegaPageRedirectScripts(): void
	{
		self::assertFileDoesNotExist(self::appRoot() . '/js/workspace-settings-legacy-redirect.js');
		self::assertFileDoesNotExist(self::appRoot() . '/js/app-settings-legacy-redirect.js');
		$js = self::read('js/pages/settings.js');
		self::assertStringNotContainsString('CustomerCheckWorkspaceSettingsLegacyRedirect', $js);
		self::assertStringNotContainsString('CustomerCheckAppSettingsLegacyRedirect', $js);
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
		self::assertStringNotContainsString('workspace-settings-legacy-redirect', $controller);
		self::assertStringNotContainsString('app-settings-legacy-redirect', $controller);
		$shell = self::read('lib/Controller/PageShellTrait.php');
		self::assertStringContainsString("'settingsSections'", $shell);
		self::assertStringContainsString("'appSettingsSections'", $shell);
	}

	public function testPromisesRollCallApiRouteRegistered(): void
	{
		$route = self::routeByName('persona_api#promisesRollCall');
		self::assertSame('/api/v1/promises-roll-call', $route['url']);
	}

	/**
	 * deny_page_status_200: the in-template non-admin deny on app-settings must
	 * fail closed at HTTP level (403) — a 200 reads as success to scanners/CDN.
	 */
	public function testAppSettingsDeniedSurfaceFailsClosedAtHttp(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		$pos = strpos($controller, 'appSettingsSection(string $section)');
		self::assertNotFalse($pos);
		$body = substr($controller, (int)$pos, 6000);
		self::assertMatchesRegularExpression(
			'/if \(!\$canManage\) \{[\s\S]{0,300}\$response->setStatus\(Http::STATUS_FORBIDDEN\)/',
			$body,
		);

		$middleware = self::read('lib/Middleware/AppAccessMiddleware.php');
		self::assertMatchesRegularExpression(
			'/\)\)->setStatus\(Http::STATUS_FORBIDDEN\)/',
			$middleware,
		);
		self::assertMatchesRegularExpression(
			'/\)\)->setStatus\(Http::STATUS_NOT_FOUND\)/',
			$middleware,
		);
	}
}
