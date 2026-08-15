<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailSendService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Personal / OAuth send must not misdirect users to Team & mailbox.
 */
final class MailSendServiceCredentialCopyTest extends TestCase
{
	public function testOAuthFailureUsesMyEmailReconnectCopy(): void
	{
		$msg = $this->invoke(true, true, true);
		$this->assertStringContainsString('Mail provider sign-in expired', $msg);
		$this->assertStringContainsString('My email', $msg);
		$this->assertStringNotContainsString('Shared mailbox password', $msg);
	}

	public function testTeamDecryptUsesTeamMailboxCopy(): void
	{
		$msg = $this->invoke(true, false, false);
		$this->assertStringContainsString('Team & mailbox', $msg);
	}

	public function testPersonalDecryptMentionsMyEmail(): void
	{
		$msg = $this->invoke(true, false, true);
		$this->assertStringContainsString('My email', $msg);
	}

	private function invoke(bool $decryptFailed, bool $oauthFailed, bool $isPersonal): string
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$ref = new \ReflectionClass(MailSendService::class);
		$svc = $ref->newInstanceWithoutConstructor();
		$prop = $ref->getProperty('l10n');
		$prop->setAccessible(true);
		$prop->setValue($svc, $l10n);

		$method = new ReflectionMethod(MailSendService::class, 'smtpCredentialMissingMessage');
		$method->setAccessible(true);

		return (string)$method->invoke($svc, $decryptFailed, $oauthFailed, $isPersonal);
	}

	public function testSourceWiresPersonalKindAndOAuthFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('oauthResolveFailed', $src);
		$this->assertStringContainsString('smtpCredentialMissingMessage', $src);
		$this->assertStringContainsString('MailAclLogic::KIND_PERSONAL', $src);
		$this->assertMatchesRegularExpression(
			'/function smtpCredentialMissingMessage[\s\S]{0,600}Mail provider sign-in expired/',
			$src,
		);
	}
}
