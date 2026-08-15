<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\SignatureLogoUrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Meaningful coverage for signature logo URL policy (AS-18 / Argus SF-01).
 */
final class SignatureLogoUrlPolicyTest extends TestCase
{
	public function testHttpsAnywhereAllowed(): void
	{
		$this->assertTrue(SignatureLogoUrlPolicy::isAllowedSrc('https://cdn.example/logo.png'));
		$this->assertTrue(SignatureLogoUrlPolicy::isAllowedSrc('https://cdn.example/s/NotAShare.png'));
	}

	public function testBlocksDangerousSchemesAndJunk(): void
	{
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc(''));
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc('data:image/png;base64,xx'));
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc('javascript:alert(1)'));
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc('vbscript:msg'));
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc('ftp://x/y'));
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc(str_repeat('https://x/', 300)));
	}

	public function testHttpThirdPartyBlockedEvenWithShareShapedPath(): void
	{
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc(
			'http://evil.example/s/Token/download',
			['localhost:8081'],
		));
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc(
			'http://files.local/logo.png',
			['files.local'],
		));
	}

	public function testHttpSameOriginPublicShareAllowed(): void
	{
		$this->assertTrue(SignatureLogoUrlPolicy::isAllowedSrc(
			'http://localhost:8081/s/AbCdEfGh/download',
			['localhost:8081'],
		));
		$this->assertTrue(SignatureLogoUrlPolicy::isAllowedSrc(
			'http://localhost:8081/index.php/s/AbCdEfGh/download',
			['localhost:8081'],
		));
		$this->assertTrue(SignatureLogoUrlPolicy::isAllowedSrc(
			'http://127.0.0.1/s/Token123',
			['127.0.0.1'],
		));
	}

	public function testHttpSameOriginNonSharePathBlocked(): void
	{
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc(
			'http://localhost:8081/remote.php/dav/files/admin/logo.png',
			['localhost:8081'],
		));
		$this->assertFalse(SignatureLogoUrlPolicy::isAllowedSrc(
			'http://localhost:8081/apps/files/logo.png',
			['localhost:8081'],
		));
	}

	public function testPublicSharePathRecognition(): void
	{
		$this->assertTrue(SignatureLogoUrlPolicy::isNextcloudPublicSharePath('/s/AbC123'));
		$this->assertTrue(SignatureLogoUrlPolicy::isNextcloudPublicSharePath('/s/AbC123/download'));
		$this->assertTrue(SignatureLogoUrlPolicy::isNextcloudPublicSharePath('/s/Ab-Cd_12/download'));
		$this->assertFalse(SignatureLogoUrlPolicy::isNextcloudPublicSharePath('/s/../etc/passwd'));
		$this->assertFalse(SignatureLogoUrlPolicy::isNextcloudPublicSharePath('/s/'));
		$this->assertFalse(SignatureLogoUrlPolicy::isNextcloudPublicSharePath('/files/s/Token'));
	}

	public function testPublicShareToImageSrc(): void
	{
		$this->assertSame(
			'https://nc.example/s/Token/download',
			SignatureLogoUrlPolicy::publicShareToImageSrc('https://nc.example/s/Token'),
		);
		$this->assertSame(
			'https://nc.example/s/Token/download',
			SignatureLogoUrlPolicy::publicShareToImageSrc('https://nc.example/s/Token/download'),
		);
		$this->assertSame(
			'http://localhost:8081/s/Tok/download',
			SignatureLogoUrlPolicy::publicShareToImageSrc('http://localhost:8081/s/Tok', ['localhost:8081']),
		);
		$this->assertSame('', SignatureLogoUrlPolicy::publicShareToImageSrc('http://evil/s/Tok', ['localhost:8081']));
		$this->assertSame('', SignatureLogoUrlPolicy::publicShareToImageSrc('data:x'));
	}
}
