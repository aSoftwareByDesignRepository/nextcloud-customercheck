<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * My email: update password/servers without Disconnect wipe (voluntary edit).
 */
final class PersonalMailUpdateConnectionContractTest extends TestCase
{
	public function testCoreUtilsAndBrowserMirrorStayAligned(): void
	{
		$utils = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/personal-mail-settings-core.js');
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/personal-mail-settings-core.js');
		foreach ([
			'showPersonalMailConnectForm',
			'needsPersonalMailReconnect',
			'personalMailHealId',
			'hidePersonalMailOpsForReconnect',
			'personalEditConnectionButtonLabel',
		] as $needle) {
			$this->assertStringContainsString($needle, $utils);
			$this->assertStringContainsString($needle, $boot);
		}
		$this->assertStringContainsString('CustomerCheckPersonalMailSettingsCore', $boot);
		// Voluntary edit must open the form; heal id must return matching mailbox id.
		$this->assertStringContainsString('return !!input.editConnection;', $utils);
		$this->assertMatchesRegularExpression(
			'/boxEmail !== form\) \{\s*return 0;\s*\}\s*return id;/',
			$utils,
		);
	}

	public function testSettingsPageWiresEditConnection(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/my-email.php');
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');

		$this->assertStringContainsString('id="crm-personal-mail-edit-connection"', $tpl);
		$this->assertStringContainsString('id="crm-personal-mail-edit-cancel"', $tpl);
		$this->assertStringContainsString('Leave blank to keep your current password', $tpl);
		$this->assertStringContainsString('openPersonalMailEditConnection', $js);
		$this->assertStringContainsString('personalMailEditMode', $js);
		$this->assertStringContainsString('payload.id = healId', $js);
		$this->assertStringContainsString("'common/personal-mail-settings-core'", $controller);
		// Plain ok must not force connect form — only quarantine or edit mode.
		$this->assertStringContainsString('showPersonalMailConnectForm', $js);
		$this->assertStringNotContainsString('if (connectForm) connectForm.hidden = !needsReconnect;', $js);
	}

	public function testServiceHealKeepsBlankPasswordSecret(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString("if (\$secret === '' && \$id <= 0)", $src);
		$this->assertStringContainsString('personal_mail.reconnected', $src);
		$this->assertStringContainsString('assertOwnedPersonalEmailUnchanged', $src);
		$this->assertStringContainsString("\$enc = \$secret !== '' ? \$this->crypto->encrypt(\$secret) : null;", $src);
	}
}
