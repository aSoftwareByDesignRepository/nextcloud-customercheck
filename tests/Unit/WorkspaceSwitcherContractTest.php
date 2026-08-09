<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Single-organisation mode: no workspace switcher chrome; active workspace id
 * still boots from shared navigation for race-safe persona/API loads.
 */
final class WorkspaceSwitcherContractTest extends TestCase
{
	public function testMultiWorkspaceDisabled(): void
	{
		$this->assertFalse(Constants::MULTI_WORKSPACE_ENABLED);
		$this->assertSame(1, Constants::WS_MAX_PER_INSTANCE);
	}

	public function testNavigationKeepsWorkspaceContextWithoutSwitcher(): void
	{
		$nav = file_get_contents(dirname(__DIR__, 2) . '/js/common/navigation.js');
		$this->assertNotFalse($nav);
		$this->assertStringContainsString('initWorkspaceSwitcher', $nav);
		$this->assertStringContainsString('data-crm-workspace-switch', $nav);
		$this->assertStringContainsString('workspaceId', $nav);
		$this->assertStringNotContainsString('sel.addEventListener(\'change\'', $nav);
		$this->assertStringNotContainsString("createElement('select')", $nav);
	}

	public function testTemplateHasContextHostNotSwitcherChrome(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString('data-crm-workspace-switch', $html);
		$this->assertStringContainsString('data-crm-active-workspace', $html);
		$this->assertStringContainsString('crm-workspace-context', $html);
		$this->assertStringContainsString("\$l->t('Deals, customers & money')", $html);
		$this->assertStringNotContainsString("\$l->t('Your role: %s'", $html);
		$this->assertStringNotContainsString('crm-nav__role', $html);
		$this->assertStringContainsString("\$l->t('Team & mailbox')", $html);
		$this->assertStringNotContainsString('crm-workspace-switcher__trigger', $html);
		$this->assertStringNotContainsString('crm-workspace-switcher__menu', $html);
		$this->assertStringNotContainsString("\$l->t('Pick which customer book you are working in.')", $html);
		$this->assertStringNotContainsString("\$l->t('Create workspace')", $html);
		$this->assertDoesNotMatchRegularExpression(
			'/app-brand__subtitle[^>]*>\s*<\?php\s*p\(\$workspaceName\)/',
			$html,
		);
	}

	public function testShellForcesNoPickerInSingleOrgMode(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageShellTrait.php');
		$this->assertStringContainsString('$needsPicker = false', $src);
		$this->assertStringContainsString('MULTI_WORKSPACE_ENABLED', $src);
		$this->assertStringContainsString("'workspaceId'", $src);
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

	public function testCssHidesSwitcherChrome(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/navigation.css');
		$this->assertStringContainsString('.crm-workspace-switcher', $css);
		$this->assertStringContainsString('display: none', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString(':focus-visible', $css);
		$this->assertStringContainsString('prefers-reduced-motion', $css);
		$this->assertStringContainsString('.app-brand__text h3', $css);
		$this->assertStringContainsString('.app-brand__icon', $css);
		$this->assertStringContainsString('.crm-nav__role', $css);
	}

	public function testCreateAndMoveLockedInServices(): void
	{
		$ws = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/WorkspaceService.php');
		$co = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$this->assertStringContainsString('MULTI_WORKSPACE_ENABLED', $ws);
		$this->assertStringContainsString('Additional workspaces cannot be created', $ws);
		$this->assertStringContainsString('MULTI_WORKSPACE_ENABLED', $co);
		$this->assertStringContainsString('cannot be moved between workspaces', $co);
	}

	public function testCompanyDetailHasNoMoveWorkspaceAction(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$mailbox = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringNotContainsString('crm-company-move-workspace', $html);
		$this->assertStringNotContainsString('crm-ws-create-form', $mailbox);
		$this->assertStringNotContainsString('Create workspace', $mailbox);
	}
}
