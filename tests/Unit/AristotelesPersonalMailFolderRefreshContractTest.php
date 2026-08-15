<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: personal mail folder rediscovery + umlaut/subfolder UX.
 */
final class AristotelesPersonalMailFolderRefreshContractTest extends TestCase
{
	public function testRefreshFoldersApiAndServiceExist(): void
	{
		$svc = file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PersonalMailService.php');
		$this->assertNotFalse($svc);
		$this->assertStringContainsString('function refreshFolders(', $svc);
		$this->assertStringContainsString('listRemoteFoldersForMailbox', $svc);
		$this->assertStringContainsString('ImapUtf7::folderKey', $svc);
		$this->assertStringContainsString('MAILBOX_MAX_FOLDERS', $svc);

		$sync = file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxSyncService.php');
		$this->assertNotFalse($sync);
		$this->assertStringContainsString('function listRemoteFoldersForMailbox(', $sync);
		$this->assertStringContainsString('MAILBOX_LIST_FOLDERS_MAX', $sync);
		$this->assertStringContainsString('folderToWire', $sync);

		$routes = file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertNotFalse($routes);
		$this->assertStringContainsString('refreshPersonalMailboxFolders', $routes);
		$this->assertStringContainsString('/folders/refresh', $routes);

		$persona = file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonaApiController.php');
		$this->assertNotFalse($persona);
		$this->assertStringContainsString('function refreshPersonalMailboxFolders(', $persona);

		$mobile = file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/MobileMailController.php');
		$this->assertNotFalse($mobile);
		$this->assertStringContainsString('function refreshPersonalMailboxFolders(', $mobile);
	}

	public function testSettingsUxHasRefreshFoldersControl(): void
	{
		$js = file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$tpl = file_get_contents(dirname(__DIR__, 2) . '/templates/settings/my-email.php');
		$this->assertNotFalse($js);
		$this->assertNotFalse($tpl);
		$this->assertStringContainsString('id="crm-personal-mail-folders-refresh"', $tpl);
		$this->assertStringContainsString('Refresh folders', $tpl);
		$this->assertStringContainsString('/folders/refresh', $js);
		$this->assertStringContainsString('folderDisplayMeta', $js);
		$this->assertStringContainsString('Found %s new folder(s)', $js);
		$this->assertStringContainsString('--crm-folder-depth', $js);
		$this->assertStringContainsString('crm-personal-mail-folder--nested', $js);
		$this->assertStringContainsString('crm-personal-mail-folder__mark', $js);
		$this->assertStringNotContainsString('crm-personal-mail-folder__path', $js);

		$css = file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertNotFalse($css);
		$this->assertStringContainsString('.crm-personal-mail-folder--nested', $css);
		$this->assertMatchesRegularExpression(
			'/--crm-folder-depth,\s*0\)\s*\*\s*1\.75rem/',
			$css,
		);
	}

	public function testListDiscoveryCapAbovePersistCap(): void
	{
		$constants = file_get_contents(dirname(__DIR__, 2) . '/lib/Constants.php');
		$this->assertNotFalse($constants);
		$this->assertMatchesRegularExpression('/MAILBOX_MAX_FOLDERS\s*=\s*20/', $constants);
		$this->assertMatchesRegularExpression('/MAILBOX_LIST_FOLDERS_MAX\s*=\s*200/', $constants);
	}

	public function testImapUtf7HelperShipped(): void
	{
		$path = dirname(__DIR__, 2) . '/lib/Support/ImapUtf7.php';
		$this->assertFileExists($path);
		$src = file_get_contents($path);
		$this->assertNotFalse($src);
		$this->assertStringContainsString('Modified UTF-7', $src);
		$this->assertStringContainsString('function toUtf8', $src);
		$this->assertStringContainsString('function toWire', $src);
	}
}
