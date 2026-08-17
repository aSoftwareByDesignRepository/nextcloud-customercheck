<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailInboxPageLogic;
use PHPUnit\Framework\TestCase;

final class MailInboxPageLogicTest extends TestCase
{
	public function testNormalizeTabFallsClosedToMine(): void
	{
		$this->assertSame('mine', MailInboxPageLogic::normalizeTab(''));
		$this->assertSame('mine', MailInboxPageLogic::normalizeTab('MINE'));
		$this->assertSame('mine', MailInboxPageLogic::normalizeTab('all'));
		$this->assertSame('mine', MailInboxPageLogic::normalizeTab('../evil'));
		$this->assertSame('team', MailInboxPageLogic::normalizeTab(' team '));
		$this->assertSame('unmatched', MailInboxPageLogic::normalizeTab('unmatched'));
	}

	public function testEncodeDecodeRoundTrip(): void
	{
		$encoded = MailInboxPageLogic::encodeCursor('2026-08-15 10:00:00', 42);
		$this->assertSame('2026-08-15 10:00:00|42', $encoded);
		$decoded = MailInboxPageLogic::decodeCursor($encoded);
		$this->assertNotNull($decoded);
		$this->assertSame('2026-08-15 10:00:00', $decoded['sentAt']);
		$this->assertSame(42, $decoded['id']);
	}

	public function testNormalizeSentAtFromDateTimeAndIso(): void
	{
		$dt = new \DateTimeImmutable('2026-08-15T10:00:00+00:00');
		$this->assertSame('2026-08-15 10:00:00', MailInboxPageLogic::normalizeSentAtForCursor($dt));
		$this->assertSame('2026-08-15 10:00:00', MailInboxPageLogic::normalizeSentAtForCursor('2026-08-15T10:00:00.123Z'));
		$this->assertSame('2026-08-15 00:00:00', MailInboxPageLogic::normalizeSentAtForCursor('2026-08-15'));
		$this->assertSame('', MailInboxPageLogic::normalizeSentAtForCursor('not-a-date'));
		$this->assertSame('', MailInboxPageLogic::normalizeSentAtForCursor(null));
	}

	public function testDecodeRejectsJunkAndMalformedTimestamps(): void
	{
		$this->assertNull(MailInboxPageLogic::decodeCursor(null));
		$this->assertNull(MailInboxPageLogic::decodeCursor(''));
		$this->assertNull(MailInboxPageLogic::decodeCursor('no-pipe'));
		$this->assertNull(MailInboxPageLogic::decodeCursor('|0'));
		$this->assertNull(MailInboxPageLogic::decodeCursor('at|-3'));
		$this->assertNull(MailInboxPageLogic::decodeCursor('at|abc'));
		$this->assertNull(MailInboxPageLogic::decodeCursor('2026/08/15 10:00:00|9'));
		$this->assertNull(MailInboxPageLogic::decodeCursor('evil<script>|9'));
		$this->assertNull(MailInboxPageLogic::decodeCursor(str_repeat('x', 40) . '|' . str_repeat('9', 60)));
	}

	public function testEmptySentAtCursorAllowed(): void
	{
		$encoded = MailInboxPageLogic::encodeCursor('', 9);
		$this->assertSame('|9', $encoded);
		$decoded = MailInboxPageLogic::decodeCursor($encoded);
		$this->assertNotNull($decoded);
		$this->assertSame('', $decoded['sentAt']);
		$this->assertSame(9, $decoded['id']);
	}

	public function testEncodeNormalizesWeirdSentAtBeforeCursor(): void
	{
		$encoded = MailInboxPageLogic::encodeCursor('not-a-date', 11);
		$this->assertSame('|11', $encoded);
	}

	public function testClampAndFetchWindow(): void
	{
		$this->assertSame(50, MailInboxPageLogic::clampLimit(0));
		$this->assertSame(50, MailInboxPageLogic::clampLimit(-5));
		$this->assertSame(100, MailInboxPageLogic::clampLimit(500));
		$this->assertSame(25, MailInboxPageLogic::clampLimit(25));
		$this->assertSame(51 * 3, MailInboxPageLogic::fetchWindow(50));
		$this->assertSame(5, MailInboxPageLogic::MAX_FETCH_PASSES);
	}

	public function testPageResultHasMoreAndCursor(): void
	{
		$rows = [];
		for ($i = 100; $i >= 90; $i--) {
			$rows[] = ['id' => $i, 'sentAt' => '2026-08-15 12:00:00'];
		}
		$page = MailInboxPageLogic::pageResult($rows, 5);
		$this->assertTrue($page['hasMore']);
		$this->assertCount(5, $page['items']);
		$this->assertSame(100, $page['items'][0]['id']);
		$this->assertSame(96, $page['items'][4]['id']);
		$this->assertSame('2026-08-15 12:00:00|96', $page['nextCursor']);
	}

	public function testPageResultNoMoreWhenShort(): void
	{
		$page = MailInboxPageLogic::pageResult([
			['id' => 3, 'sentAt' => '2026-01-01 00:00:00'],
			['id' => 2, 'sentAt' => '2026-01-01 00:00:00'],
		], 50);
		$this->assertFalse($page['hasMore']);
		$this->assertNull($page['nextCursor']);
		$this->assertCount(2, $page['items']);
	}

	public function testPageResultHasMoreFalseWhenLastIdInvalid(): void
	{
		$page = MailInboxPageLogic::pageResult([
			['id' => 0, 'sentAt' => '2026-01-01 00:00:00'],
			['id' => 0, 'sentAt' => '2026-01-01 00:00:00'],
		], 1);
		$this->assertFalse($page['hasMore']);
		$this->assertNull($page['nextCursor']);
		$this->assertCount(1, $page['items']);
	}
}
