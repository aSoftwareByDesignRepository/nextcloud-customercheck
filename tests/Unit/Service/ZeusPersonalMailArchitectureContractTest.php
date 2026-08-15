<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Zeus FULL AUDIT 1.6.106 — architecture Must-Fix regression contracts.
 */
final class ZeusPersonalMailArchitectureContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testMailSyncMaxMessagesAliasExists(): void
	{
		$this->assertSame(Constants::MAILBOX_SYNC_CAP, Constants::MAIL_SYNC_MAX_MESSAGES);
		$sync = (string)file_get_contents($this->root . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('Constants::MAIL_SYNC_MAX_MESSAGES', $sync);
		$this->assertStringContainsString('personalMailEnabled', $sync);
		$this->assertStringNotContainsString(
			'Personal IMAP is never ingested — only workspace-owned rows',
			$sync,
		);
	}

	public function testFindByIdempotencyRequiresSmtpAckOrOutbound(): void
	{
		$send = (string)file_get_contents($this->root . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('function findByIdempotency', $send);
		$this->assertStringContainsString('smtp_acked', $send);
		$this->assertStringContainsString('outboundExistsForIdempotency', $send);
		$this->assertStringContainsString('markSmtpAcked', $send);
		// SMTP ACK must land before CRM index so crash replay never fakes success.
		$this->assertMatchesRegularExpression(
			'/markSmtpAcked\(\$claimId\);[\s\S]{0,500}indexOutboundMessage\(/',
			$send,
		);
		$this->assertMatchesRegularExpression(
			'/function findByIdempotency[\s\S]{0,2000}releaseFailedDraftClaim/',
			$send,
		);
	}

	public function testOutboundIndexTakesMailboxLock(): void
	{
		$send = (string)file_get_contents($this->root . '/lib/Service/MailSendService.php');
		$this->assertMatchesRegularExpression(
			'/function indexOutboundMessage[\s\S]{0,500}crm_mbox_/',
			$send,
		);
	}

	public function testOAuthMaxPerUserCheckedUnderEmailLock(): void
	{
		$oauth = (string)file_get_contents($this->root . '/lib/Service/PersonalMailOAuthService.php');
		$this->assertMatchesRegularExpression(
			'/withEmailConnectLock\([\s\S]{0,1200}PERSONAL_MAIL_MAX_PER_USER/',
			$oauth,
		);
		$this->assertStringContainsString('healExistingOAuthMailbox', $oauth);
		$this->assertStringContainsString('personal_mail.reconnected', $oauth);
	}

	public function testSmtpAckedMigrationPresent(): void
	{
		$mig = (string)file_get_contents($this->root . '/lib/Migration/Version1016Date20260815123000.php');
		$this->assertStringContainsString('smtp_acked', $mig);
		$this->assertStringContainsString('crm_mail_dft', $mig);
	}
}
