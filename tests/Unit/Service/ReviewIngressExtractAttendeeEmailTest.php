<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ReviewIngressService;
use PHPUnit\Framework\TestCase;

final class ReviewIngressExtractAttendeeEmailTest extends TestCase
{
	public function testPrefersAttendeeOverOrganizer(): void
	{
		$email = ReviewIngressService::extractAttendeeEmail([
			'ORGANIZER' => 'mailto:boss@acme.test',
			'ATTENDEE' => [
				['value' => 'mailto:boss@acme.test'],
				['value' => 'mailto:buyer@customer.test'],
			],
		]);
		self::assertSame('buyer@customer.test', $email);
	}

	public function testMailtoString(): void
	{
		self::assertSame(
			'ada@example.com',
			ReviewIngressService::extractAttendeeEmail(['ATTENDEE' => 'mailto:Ada@Example.com']),
		);
	}

	public function testFallsBackToOrganizerWhenOnlyAttendee(): void
	{
		self::assertSame(
			'only@acme.test',
			ReviewIngressService::extractAttendeeEmail([
				'ORGANIZER' => 'mailto:only@acme.test',
				'ATTENDEE' => 'mailto:only@acme.test',
			]),
		);
	}

	public function testReturnsNullWhenMissing(): void
	{
		self::assertNull(ReviewIngressService::extractAttendeeEmail(['SUMMARY' => 'Standup']));
	}
}
