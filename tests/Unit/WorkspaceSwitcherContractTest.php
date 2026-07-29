<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workspace switcher must boot from shared navigation on every page
 * (not only persona.js mounts), use a disclosure (not select-onchange),
 * and expose SSR workspace context for race-safe persona loads.
 */
final class WorkspaceSwitcherContractTest extends TestCase
{
	public function testNavigationOwnsWorkspaceSwitcher(): void
	{
		$nav = file_get_contents(dirname(__DIR__, 2) . '/js/common/navigation.js');
		$this->assertNotFalse($nav);
		$this->assertStringContainsString('initWorkspaceSwitcher', $nav);
		$this->assertStringContainsString('data-crm-workspace-switch', $nav);
		$this->assertStringContainsString("api.get('/workspaces')", $nav);
		$this->assertStringContainsString('workspaceId', $nav);
		$this->assertStringContainsString('crm-workspace-switcher__trigger', $nav);
		$this->assertStringContainsString('aria-expanded', $nav);
		$this->assertStringNotContainsString('sel.addEventListener(\'change\'', $nav);
		$this->assertStringNotContainsString("createElement('select')", $nav);
		$this->assertStringNotContainsString('window.location.href = url.toString()', $nav);
	}

	public function testTemplateIsDisclosureNotSelect(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString('crm-workspace-switcher', $html);
		$this->assertStringContainsString('data-crm-active-workspace', $html);
		$this->assertStringContainsString('crm-workspace-switcher__label', $html);
		$this->assertStringContainsString('crm-workspace-switcher__trigger', $html);
		$this->assertStringContainsString('crm-workspace-switcher__menu', $html);
		$this->assertStringContainsString('workspaceSwitchUrls', $html);
		$this->assertStringContainsString('count($workspaces) > 1', $html);
		$this->assertStringNotContainsString('<select', $html);
		$this->assertStringContainsString("\$l->t('Customers & renewals')", $html);
		$this->assertStringContainsString("\$l->t('Workspace')", $html);
		$this->assertStringContainsString("\$l->t('Your role: %s'", $html);
		$this->assertStringContainsString("\$l->t('Pick which customer book you are working in.')", $html);
		// Brand no longer duplicates the workspace name as subtitle.
		$this->assertDoesNotMatchRegularExpression(
			'/app-brand__subtitle[^>]*>\s*<\?php\s*p\(\$workspaceName\)/',
			$html,
		);
	}

	public function testShellPassesWorkspaceListAndSwitchUrls(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageShellTrait.php');
		$this->assertStringContainsString("'workspaces'", $src);
		$this->assertStringContainsString("'needsPicker'", $src);
		$this->assertStringContainsString("'workspaceSwitchUrls'", $src);
		$this->assertStringContainsString('listVisibleSummaries', $src);
		$this->assertStringContainsString('WorkspaceSwitchUrlBuilder::buildMap', $src);
		$this->assertStringContainsString("l10n->t('App admin')", $src);
	}

	public function testPersonaReadsActiveWorkspaceAttribute(): void
	{
		$persona = file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertNotFalse($persona);
		$this->assertStringNotContainsString('async function initWorkspaceSwitcher', $persona);
		$this->assertStringContainsString('data-crm-active-workspace', $persona);
	}

	public function testApiRegistersBeforeNavigation(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FrontEndAssetService.php');
		$this->assertNotFalse($src);
		$apiPos = strpos($src, "addScript(Application::APP_ID, 'common/api')");
		$navPos = strpos($src, "addScript(Application::APP_ID, 'common/navigation')");
		$this->assertNotFalse($apiPos);
		$this->assertNotFalse($navPos);
		$this->assertLessThan($navPos, $apiPos);
	}

	public function testCssDefinesAccessibleSwitcherChrome(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/navigation.css');
		$this->assertStringContainsString('.crm-workspace-switcher__trigger', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString(':focus-visible', $css);
		$this->assertStringContainsString('prefers-reduced-motion', $css);
		$this->assertStringContainsString('.app-brand__text h3', $css);
		$this->assertStringContainsString('.app-brand__icon', $css);
	}

	public function testIconCatalogHasChevronDown(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/IconCatalog.php');
		$this->assertStringContainsString("'chevron-down'", $src);
	}
}
