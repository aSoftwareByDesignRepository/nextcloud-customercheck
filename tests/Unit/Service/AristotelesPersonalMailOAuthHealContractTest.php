<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * US-002 ok⇄error: OAuth re-consent heals tokens in place (no Disconnect erase).
 */
final class AristotelesPersonalMailOAuthHealContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testHealPathPresent(): void
	{
		$oauth = (string)file_get_contents($this->root . '/lib/Service/PersonalMailOAuthService.php');
		$this->assertStringContainsString('function healExistingOAuthMailbox', $oauth);
		$this->assertStringContainsString('personal_mail.reconnected', $oauth);
		$this->assertMatchesRegularExpression(
			'/\$boxEmail === \$email[\s\S]{0,200}\$healId/',
			$oauth,
		);
		$this->assertMatchesRegularExpression(
			'/if \(\$healId > 0\) \{\s+return \$this->healExistingOAuthMailbox/',
			$oauth,
		);
		$pos = strpos($oauth, 'function healExistingOAuthMailbox');
		$this->assertNotFalse($pos);
		$chunk = substr($oauth, (int)$pos, 2500);
		$this->assertStringContainsString("'auth_fail_count'", $chunk);
		$this->assertStringContainsString("createNamedParameter(0)", $chunk);
		$this->assertStringContainsString("'status'", $chunk);
		$this->assertStringContainsString("createNamedParameter('ok')", $chunk);
		$this->assertStringContainsString('PERSONAL_MAIL_MAX_PER_USER', $oauth);
	}

	public function testWebSettingsShowsReconnectOauth(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('needsReconnect', $js);
		$this->assertStringContainsString('Needs reconnect — sign in again below. Your synced mail stays.', $js);
		$this->assertStringContainsString('(!personalMailBox || needsReconnect)', $js);
	}
}
