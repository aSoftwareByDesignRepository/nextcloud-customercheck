<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Post-Bachus P1 closeout — IMAP CTA when no OAuth, case-insensitive SHARE, OAuth error query.
 */
final class AristotelesPersonalMailBachusP1CloseoutContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testImapDetailsOpenWhenNoOauthProviders(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('oauthConfiguredCount === 0', $js);
		$this->assertMatchesRegularExpression(
			'/oauthConfiguredCount === 0[\s\S]{0,200}imapDetailsEl\.open = true/',
			$js,
		);
	}

	public function testTypedConfirmIsCaseInsensitive(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/components.js');
		$pos = strpos($js, 'validateConfirmEnabled');
		$this->assertNotFalse($pos);
		$chunk = substr($js, (int)$pos, 900);
		$this->assertStringContainsString('toUpperCase()', $chunk);
		$this->assertStringContainsString('typedConfirmPhrase', $chunk);
		$this->assertStringNotContainsString(
			"typedField.value.trim() !== typedConfirmPhrase",
			$chunk,
		);
	}

	public function testOauthCallbackQueryFeedbackSurfaced(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('function consumePersonalMailQueryFeedback', $js);
		$this->assertStringContainsString("params.get('personalMailError')", $js);
		$this->assertStringContainsString('Connection cancelled. You can try again when ready.', $js);
		$this->assertMatchesRegularExpression(
			'/oauthConfiguredCount === 0[\s\S]{0,280}imapDetailsEl\.open = true[\s\S]{0,280}consumePersonalMailQueryFeedback\(\)/',
			$js,
		);
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('personalMailError=', $ctrl);
		$this->assertStringContainsString('personalMail=connected', $ctrl);
	}
}
