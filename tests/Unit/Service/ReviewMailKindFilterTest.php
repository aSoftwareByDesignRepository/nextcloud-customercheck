<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Inbox↔Assign merge — virtual `mail` kind + resolveKindFilter (AS-12 mail sources).
 */
final class ReviewMailKindFilterTest extends TestCase
{
	public function testIsMailSourceKindCoversTeamAndPersonalShared(): void
	{
		$this->assertTrue(ReviewService::isMailSourceKind('shared_mail'));
		$this->assertTrue(ReviewService::isMailSourceKind('personal_mail_shared_unmatched'));
		$this->assertFalse(ReviewService::isMailSourceKind('contact_suggest'));
		$this->assertFalse(ReviewService::isMailSourceKind('mail'));
		$this->assertFalse(ReviewService::isMailSourceKind(''));
	}

	public function testResolveKindFilterMailExpandsBothMailKinds(): void
	{
		$this->assertSame(
			['shared_mail', 'personal_mail_shared_unmatched'],
			ReviewService::resolveKindFilter('mail'),
		);
		$this->assertSame(
			['shared_mail', 'personal_mail_shared_unmatched'],
			ReviewService::resolveKindFilter('  mail  '),
		);
	}

	public function testResolveKindFilterExactAndEmpty(): void
	{
		$this->assertNull(ReviewService::resolveKindFilter(null));
		$this->assertNull(ReviewService::resolveKindFilter(''));
		$this->assertNull(ReviewService::resolveKindFilter('nope'));
		$this->assertSame(['shared_mail'], ReviewService::resolveKindFilter('shared_mail'));
		$this->assertSame(
			['personal_mail_shared_unmatched'],
			ReviewService::resolveKindFilter('personal_mail_shared_unmatched'),
		);
		$this->assertSame(['contact_suggest'], ReviewService::resolveKindFilter('contact_suggest'));
	}
}
