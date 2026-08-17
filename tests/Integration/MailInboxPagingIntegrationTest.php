<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\MailInboxPageLogic;
use PHPUnit\Framework\TestCase;

/**
 * Integration-shaped paging proofs: cursor stability across encode/page boundaries.
 */
final class MailInboxPagingIntegrationTest extends TestCase
{
	public function testCursorChainWalksDescendingPagesWithoutOverlap(): void
	{
		$all = [];
		for ($i = 30; $i >= 1; $i--) {
			$day = sprintf('2026-08-%02d', max(1, min(28, (int)ceil($i / 2))));
			$all[] = [
				'id' => $i,
				'sentAt' => $day . ' 12:00:00',
				'subject' => 'm' . $i,
			];
		}

		$seen = [];
		$cursor = null;
		$pages = 0;
		while (true) {
			$slice = $this->sliceAfterCursor($all, $cursor, 7);
			$page = MailInboxPageLogic::pageResult($slice, 7);
			$pages++;
			foreach ($page['items'] as $row) {
				$id = (int)$row['id'];
				$this->assertArrayNotHasKey($id, $seen, 'duplicate id across pages');
				$seen[$id] = true;
			}
			if (!$page['hasMore']) {
				break;
			}
			$cursor = $page['nextCursor'];
			$this->assertNotNull($cursor);
			$this->assertNotNull(MailInboxPageLogic::decodeCursor($cursor));
			$this->assertLessThan(10, $pages, 'should finish in few pages');
		}

		$this->assertCount(30, $seen);
		$this->assertSame(range(30, 1), array_map('intval', array_keys($seen)));
	}

	public function testInvalidCursorIsIgnoredAsStart(): void
	{
		$this->assertNull(MailInboxPageLogic::decodeCursor('2026-08-01 12:00:00|not-int'));
		$this->assertNull(MailInboxPageLogic::decodeCursor('DROP TABLE|1'));
		$start = MailInboxPageLogic::pageResult([
			['id' => 2, 'sentAt' => '2026-08-01 12:00:00'],
			['id' => 1, 'sentAt' => '2026-08-01 11:00:00'],
		], 50);
		$this->assertCount(2, $start['items']);
		$this->assertFalse($start['hasMore']);
	}

	/**
	 * @param list<array{id:int,sentAt:string,subject:string}> $all
	 * @return list<array{id:int,sentAt:string,subject:string}>
	 */
	private function sliceAfterCursor(array $all, ?string $cursor, int $limit): array
	{
		$parsed = MailInboxPageLogic::decodeCursor($cursor);
		$out = [];
		foreach ($all as $row) {
			if ($parsed !== null) {
				$at = (string)$row['sentAt'];
				$id = (int)$row['id'];
				$cAt = $parsed['sentAt'];
				$cId = $parsed['id'];
				$older = $at < $cAt || ($at === $cAt && $id < $cId);
				if (!$older) {
					continue;
				}
			}
			$out[] = $row;
			if (count($out) >= $limit + 1) {
				break;
			}
		}

		return $out;
	}
}
