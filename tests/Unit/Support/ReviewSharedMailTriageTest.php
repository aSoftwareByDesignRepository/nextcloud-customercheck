<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ReviewSharedMailTriage;
use PHPUnit\Framework\TestCase;

/**
 * Pure Assign triage decisions — mutants that resurrect outbound/ghost rows must die.
 */
final class ReviewSharedMailTriageTest extends TestCase
{
	public function testGoneAndMatchedActions(): void
	{
		$this->assertSame('dead', ReviewSharedMailTriage::openReviewAction(null));
		$this->assertSame('dead', ReviewSharedMailTriage::openReviewAction(false));
		$this->assertSame('dead', ReviewSharedMailTriage::openReviewAction([
			'triage_status' => 'remote_deleted',
			'company_id' => 9,
		]));
		$this->assertSame('matched', ReviewSharedMailTriage::openReviewAction([
			'triage_status' => 'matched',
			'company_id' => 9,
			'message_uid' => '42',
			'direction' => 'inbound',
		]));
		$this->assertSame('keep', ReviewSharedMailTriage::openReviewAction([
			'triage_status' => 'open',
			'company_id' => null,
			'message_uid' => '99',
			'direction' => 'inbound',
		]));
	}

	public function testCrmOutboundNeverStaysInAssign(): void
	{
		$this->assertTrue(ReviewSharedMailTriage::isCrmOutboundNotForAssign([
			'message_uid' => 'outbound:crm-send-abc',
			'triage_status' => 'open',
		]));
		$this->assertTrue(ReviewSharedMailTriage::isCrmOutboundNotForAssign([
			'direction' => 'outbound',
			'message_uid' => '12',
			'triage_status' => 'open',
		]));
		$this->assertFalse(ReviewSharedMailTriage::isCrmOutboundNotForAssign([
			'message_uid' => '12',
			'direction' => 'inbound',
			'triage_status' => 'open',
		]));

		// Open outbound without company → close Assign (dead), do not keep.
		$this->assertSame('dead', ReviewSharedMailTriage::openReviewAction([
			'triage_status' => 'open',
			'company_id' => null,
			'message_uid' => 'outbound:crm-send-x',
			'direction' => 'outbound',
		]));

		// Matched outbound with company still auto-closes as assigned (not dead).
		$this->assertSame('matched', ReviewSharedMailTriage::openReviewAction([
			'triage_status' => 'matched',
			'company_id' => 3,
			'message_uid' => 'outbound:crm-send-y',
			'direction' => 'outbound',
		]));
	}

	public function testFetchWindowBounds(): void
	{
		$this->assertSame(200, ReviewSharedMailTriage::fetchWindow(20, true));
		$this->assertSame(150, ReviewSharedMailTriage::fetchWindow(20, false));
		// Floor limit is 1, but non-date window still over-fetches to ≥150.
		$this->assertSame(150, ReviewSharedMailTriage::fetchWindow(0, false));
		$this->assertLessThanOrEqual(500, ReviewSharedMailTriage::fetchWindow(100, true));
	}
}
