<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * US-002 ok⇄error: IMAP reconnect heals same-email mailbox (parity with OAuth heal).
 */
final class AristotelesPersonalMailImapHealContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testSameEmailResolvesHealIdWithoutClientId(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/PersonalMailService.php');
		$pos = strpos($src, 'function connectImap(');
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos, 9000);
		$this->assertStringContainsString('US-002 ok⇄error', $chunk);
		$this->assertMatchesRegularExpression(
			'/\$boxEmail === \$emailAddress[\s\S]{0,120}\$id = \(int\)/',
			$chunk,
		);
		$this->assertStringContainsString('personal_mail.reconnected', $chunk);
		$this->assertStringContainsString("set('token_enc'", $chunk);
		$this->assertStringContainsString("set('refresh_enc'", $chunk);
	}

	public function testWebPassesMailboxIdOnReconnectWhenEmailMatches(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$core = (string)file_get_contents($this->root . '/js/common/personal-mail-settings-core.js');
		$this->assertStringContainsString('personalMailHealId', $js);
		$this->assertStringContainsString('function personalMailHealId', $core);
		$this->assertMatchesRegularExpression(
			'/if \(healId > 0\) \{\s*\n\s*payload\.id = healId;/',
			$js,
		);
	}

	public function testOAuthHealClearsImapSecret(): void
	{
		$oauth = (string)file_get_contents($this->root . '/lib/Service/PersonalMailOAuthService.php');
		$pos = strpos($oauth, 'function healExistingOAuthMailbox');
		$this->assertNotFalse($pos);
		$chunk = substr($oauth, (int)$pos, 2000);
		$this->assertStringContainsString("set('secret_enc'", $chunk);
		$this->assertStringContainsString("createNamedParameter('')", $chunk);
	}
}
