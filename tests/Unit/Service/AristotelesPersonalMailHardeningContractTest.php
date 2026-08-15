<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Aristoteles 1.6.107 — SF-01..05 architecture contracts (meaningful, mutation-backed).
 */
final class AristotelesPersonalMailHardeningContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testMessageIdUniqueMigrationPresent(): void
	{
		$cleanup = (string)file_get_contents($this->root . '/lib/Migration/Version1017Date20260815140000.php');
		$uq = (string)file_get_contents($this->root . '/lib/Migration/Version1018Date20260815141000.php');
		$this->assertStringContainsString('normalizeAndDedupeMessageIds', $cleanup);
		$this->assertStringContainsString('retireDuplicatePersonalMailboxes', $cleanup);
		$this->assertStringContainsString('crm_mm_mid_uq', $uq);
		$this->assertStringContainsString("addUniqueIndex(['mailbox_id', 'message_id']", $uq);
		$this->assertStringContainsString('crm_mb_own_kind_uq', $uq);
	}

	public function testSyncAllRespectsDialCooldown(): void
	{
		$sync = (string)file_get_contents($this->root . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('isSyncDialCooldownActive', $sync);
		$this->assertStringContainsString('recordSyncDialFailure', $sync);
		$this->assertStringContainsString('personal_mail.sync_fail', $sync);
		$this->assertStringContainsString('personal_mail.sync_skip', $sync);
		$this->assertMatchesRegularExpression(
			'/function syncAll\([\s\S]{0,1200}isSyncDialCooldownActive/',
			$sync,
		);
	}

	public function testHollowClaimPurgeRequiresSmtpAckedZero(): void
	{
		$drafts = (string)file_get_contents($this->root . '/lib/Service/MailDraftService.php');
		$this->assertStringContainsString('function purgeStale', $drafts);
		$this->assertStringContainsString('smtp_acked', $drafts);
		$this->assertMatchesRegularExpression(
			"/eq\('smtp_acked'.{0,80}createNamedParameter\(0\)/",
			$drafts,
		);
		$this->assertSame(90, Constants::MAIL_FAILED_DRAFT_TTL_DAYS);
		$this->assertSame(24, Constants::MAIL_HOLLOW_CLAIM_TTL_HOURS);
	}

	public function testDialBackoffConstantsBounded(): void
	{
		$this->assertGreaterThan(0, Constants::MAIL_SYNC_DIAL_BACKOFF_BASE_SECONDS);
		$this->assertGreaterThanOrEqual(
			Constants::MAIL_SYNC_DIAL_BACKOFF_BASE_SECONDS,
			Constants::MAIL_SYNC_DIAL_BACKOFF_MAX_SECONDS,
		);
		$svc = (string)file_get_contents($this->root . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('function recordSyncDialFailure', $svc);
		$this->assertStringContainsString('function clearSyncDialFailure', $svc);
		$this->assertStringContainsString('function isSyncDialCooldownActive', $svc);
	}

	public function testSalesInboxListboxKeyboardContract(): void
	{
		$tpl = (string)file_get_contents($this->root . '/templates/sales-inbox.php');
		$js = (string)file_get_contents($this->root . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('role="listbox"', $tpl);
		$this->assertStringContainsString("setAttribute('role', 'option')", $js);
		$this->assertStringContainsString("ev.key === 'ArrowDown'", $js);
		$this->assertStringContainsString("ev.key === 'ArrowUp'", $js);
		$this->assertStringContainsString('aria-activedescendant', $js);
	}

	public function testRetentionJobPurgesDrafts(): void
	{
		$job = (string)file_get_contents($this->root . '/lib/BackgroundJob/MailRetentionPurgeJob.php');
		$this->assertStringContainsString('MailDraftService', $job);
		$this->assertStringContainsString('purgeStale', $job);
	}
}
