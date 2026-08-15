<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Heal must not rebind an owned mailbox id onto a different email (erase bypass).
 */
final class AristotelesPersonalMailEmailRebindGuardContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testConnectImapGuardsSameEmailOnHeal(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('function assertOwnedPersonalEmailUnchanged', $src);
		$this->assertStringContainsString('disconnect this mailbox first', $src);
		$pos = strpos($src, 'function connectImap(');
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos, 12000);
		$this->assertStringContainsString('assertOwnedPersonalEmailUnchanged', $chunk);
		// Heal UPDATE must not rewrite email_address (identity stays fixed).
		$updPos = strpos($chunk, "if (\$id > 0) {\n\t\t\t\t\$this->assertOwnedPersonalEmailUnchanged");
		$this->assertNotFalse($updPos);
		$updChunk = substr($chunk, (int)$updPos, 2500);
		$this->assertStringNotContainsString("set('email_address'", $updChunk);
	}

	public function testWebPassesIdOnlyWhenEmailsMatch(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$core = (string)file_get_contents($this->root . '/js/common/personal-mail-settings-core.js');
		$this->assertStringContainsString('personalMailHealId', $js);
		$this->assertStringContainsString('personalMailHealId', $core);
		$this->assertMatchesRegularExpression(
			'/personalMailHealId\(personalMailBox, emailAddress\)/',
			$js,
		);
		$this->assertMatchesRegularExpression(
			'/if \(healId > 0\) \{\s*\n\s*payload\.id = healId;/',
			$js,
		);
		// Core must refuse heal when form email ≠ existing box (no id rebind).
		$this->assertStringContainsString('boxEmail !== form', $core);
		$this->assertStringContainsString('.toLowerCase()', $core);
	}

	public function testAuthFailCountIncrementsAtomically(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/MailboxService.php');
		$pos = strpos($src, 'function recordAuthFailure');
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos, 1200);
		$this->assertStringContainsString("createFunction('auth_fail_count + 1')", $chunk);
		$this->assertStringContainsString('$next === 3', $chunk);
	}
}
