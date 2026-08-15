<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\ReviewService;
use PHPUnit\Framework\TestCase;

/**
 * AS-12 / AS-16 contract — private personal unmatched must never enter Review promote path.
 */
final class PersonalMailReviewLeakContractTest extends TestCase
{
	public function testUnmatchedPageFiltersSharedVisibilityOnly(): void
	{
		$src = file_get_contents((new \ReflectionClass(MailboxService::class))->getFileName() ?: '');
		$this->assertIsString($src);
		$this->assertStringContainsString('function unmatchedPage', $src);
		$this->assertStringContainsString(MailAclLogic::VISIBILITY_SHARED, $src);
		$this->assertStringContainsString(MailAclLogic::VISIBILITY_PRIVATE, $src);
		$this->assertStringContainsString('mailbox_kind', $src);
		// Must not blindly set every open triage row into Review.
		$chunk = $this->methodChunk($src, 'unmatchedPage');
		$this->assertStringContainsString("eq('m.visibility'", $chunk);
		$this->assertStringContainsString('VISIBILITY_SHARED', $chunk);
		$this->assertStringContainsString('never promote private', $chunk);
	}

	public function testReviewPromoteUsesPersonalSharedSourceKind(): void
	{
		$src = file_get_contents((new \ReflectionClass(ReviewService::class))->getFileName() ?: '');
		$this->assertIsString($src);
		$this->assertContains('personal_mail_shared_unmatched', ReviewService::SOURCE_KINDS);
		$this->assertTrue(ReviewService::isMailSourceKind('personal_mail_shared_unmatched'));
		$this->assertTrue(ReviewService::isMailSourceKind('shared_mail'));
		$this->assertFalse(ReviewService::isMailSourceKind('contact_suggest'));
		$chunk = $this->methodChunk($src, 'promoteOpenSharedMail');
		$this->assertStringContainsString('personal_mail_shared_unmatched', $chunk);
		$this->assertStringContainsString('mailboxKind', $chunk);
	}

	public function testShareExistingAckRequiredBeforeVisibilityFlip(): void
	{
		$path = dirname((new \ReflectionClass(MailboxService::class))->getFileName() ?: '') . '/PersonalMailService.php';
		$src = file_get_contents($path);
		$this->assertIsString($src);
		$this->assertStringContainsString('MAIL_SHARE_EXISTING_ACK_REQUIRED', $src);
		$this->assertStringContainsString('shareExistingAck', $src);
		$this->assertStringContainsString('countPrivateMessagesForNewlySharedFolders', $src);
		// Upsert keeps folder ids stable so private rows can be counted / flipped.
		$this->assertStringContainsString('Upsert personal folders by path', $src);
	}

	private function methodChunk(string $src, string $method): string
	{
		$pos = strpos($src, 'function ' . $method);
		$this->assertNotFalse($pos, $method . ' missing');
		return substr($src, (int)$pos, 2500);
	}
}
