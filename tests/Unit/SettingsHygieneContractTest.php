<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Settings hygiene — no dead mega-page leftovers or misleading integration probes.
 */
final class SettingsHygieneContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testAccessTemplateHasNoUnreachableNonAdminBranch(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/settings/access.php');
		$this->assertStringContainsString('crm-settings-access-form', $src);
		$this->assertStringNotContainsString('!$canManage', $src);
		$this->assertStringNotContainsString('pipeline view preference can still be changed below', $src);
	}

	public function testPersonalMailOAuthHasNoUnreachableNonAdminBranch(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/settings/personal-mail.php');
		$this->assertStringContainsString('crm-personal-mail-oauth-form', $src);
		$this->assertStringNotContainsString('!$canManage', $src);
	}

	public function testSettingsJsDoesNotScrubRemovedCreateWorkspaceDom(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringNotContainsString('crm-ws-create-form', $js);
		$this->assertStringNotContainsString('crm-ws-create-card', $js);
		$this->assertStringNotContainsString('CustomerCheckWorkspaceSettingsLegacyRedirect', $js);
		$this->assertStringNotContainsString('CustomerCheckAppSettingsLegacyRedirect', $js);
	}

	public function testLegacyRedirectScriptsRemoved(): void
	{
		$this->assertFileDoesNotExist($this->root . '/js/workspace-settings-legacy-redirect.js');
		$this->assertFileDoesNotExist($this->root . '/js/app-settings-legacy-redirect.js');
	}

	public function testPromisesSectionUsesClearSlugAndTemplate(): void
	{
		$dispatcher = (string)file_get_contents($this->root . '/templates/settings.php');
		$this->assertStringContainsString("'promises' => 'promises-roll-call.php'", $dispatcher);
		$this->assertStringNotContainsString("'circles'", $dispatcher);

		$template = (string)file_get_contents($this->root . '/templates/settings/promises-roll-call.php');
		$this->assertStringContainsString('id="section-promises-roll-call"', $template);
		$this->assertStringContainsString('id="crm-promises-roll-call"', $template);

		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('/promises-roll-call', $js);
		$this->assertStringNotContainsString('/circles-roll-call', $js);
	}

	public function testIntegrationsListsOnlyBooleanCapabilitiesWithHumanLabels(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/settings/integrations.php');
		$this->assertStringContainsString('Optional Check apps and Nextcloud Mail', $src);
		$this->assertStringContainsString('is_bool($raw)', $src);
		$this->assertStringContainsString('maintenancecheck', $src);
		$this->assertStringNotContainsString('Sibling apps are read-only', $src);
	}

	public function testJobsFormExposesHeadlessConfigKeys(): void
	{
		$jobs = (string)file_get_contents($this->root . '/templates/settings/jobs.php');
		$this->assertStringContainsString('identity_rename_push', $jobs);
		$this->assertStringContainsString('vtodo_calendar_uri', $jobs);
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('identity_rename_push', $js);
		$this->assertStringContainsString('vtodo_calendar_uri', $js);
	}

	public function testSettingsHelpAndQuickStartMatchChipNav(): void
	{
		$help = (string)file_get_contents($this->root . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString('Your email, team mailbox, preferences, checklists, and linked apps.', $help);

		$qs = (string)file_get_contents($this->root . '/lib/Service/PageQuickStartCatalog.php');
		$this->assertStringContainsString('Use the chips above', $qs);
		$this->assertStringContainsString('1. Team mailbox (managers)', $qs);
	}
}
