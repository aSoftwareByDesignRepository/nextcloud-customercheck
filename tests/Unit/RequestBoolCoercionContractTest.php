<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Permanent probe for the string-"false" coercion class (Atlas 3.5.14
 * live-probe finding): request/transport booleans must go through
 * StrictBool — `!empty($body['flag'])` on a request payload treats the
 * literal string "false" as truthy and silently granted canMailSend,
 * would clear OAuth secrets, and could record a DSGVO ack.
 */
final class RequestBoolCoercionContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 2);
	}

	private static function read(string $rel): string
	{
		$body = file_get_contents(self::appRoot() . '/' . $rel);
		self::assertNotFalse($body, $rel . ' unreadable');

		return (string)$body;
	}

	public function testControllersRouteBoolFlagsThroughStrictParser(): void
	{
		foreach ([
			'lib/Controller/PersonaApiController.php',
			'lib/Controller/ReviewApiController.php',
			'lib/Controller/PersonApiController.php',
		] as $rel) {
			$src = self::read($rel);
			self::assertStringNotContainsString("!empty(\$body['", $src,
				"{$rel} must not truthy-test raw request bool keys — use bodyBool/StrictBool");
		}
		$persona = self::read('lib/Controller/PersonaApiController.php');
		self::assertSame(3, substr_count($persona, "\$this->bodyBool(\$body, 'canMailSend', true)"),
			'canMailSend must be StrictBool-parsed at add/import member sites');
	}

	public function testServicesUseStrictBoolForTransportFlags(): void
	{
		$mail = self::read('lib/Service/MailSendService.php');
		self::assertStringContainsString("StrictBool::field(\$data, 'includeSignature', true)", $mail);
		self::assertStringNotContainsString("!empty(\$data['includeSignature'])", $mail);

		$oauth = self::read('lib/Service/PersonalMailOAuthService.php');
		self::assertStringContainsString("StrictBool::field(\$data, 'clearGoogleSecret')", $oauth);
		self::assertStringContainsString("StrictBool::field(\$data, 'clearMicrosoftSecret')", $oauth);
		self::assertStringNotContainsString("!empty(\$data['clearGoogleSecret'])", $oauth);
		self::assertStringNotContainsString("!empty(\$data['clearMicrosoftSecret'])", $oauth);

		$ws = self::read('lib/Service/WorkspaceService.php');
		self::assertStringContainsString("StrictBool::of(\$data['digestEnabled'])", $ws);
		self::assertStringContainsString("StrictBool::of(\$data['personalMailEnabled'])", $ws);
		self::assertStringContainsString("StrictBool::of(\$data['personalMailDsgvoAck'] ?? null)", $ws);
	}
}
