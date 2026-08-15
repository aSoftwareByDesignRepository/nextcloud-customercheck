<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailSendService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * SF-04 — failed draft TTL + hollow claim purge (never deletes smtp_acked ledger).
 */
final class MailDraftPurgeStaleTest extends TestCase
{
	public function testPurgeStaleDeletesOldFailedDraftsOnly(): void
	{
		$selectCalls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$selectCalls) {
			$selectCalls++;
			// 1) failed-draft select → one id
			// 2) failed-draft delete
			// 3) hollow select → empty
			if ($selectCalls === 1) {
				return $this->selectIdsQb([55]);
			}
			if ($selectCalls === 2) {
				return $this->deleteQb();
			}
			return $this->selectIdsQb([]);
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-15 12:00:00'));
		$l10n = $this->createMock(IL10N::class);

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$time,
			$l10n,
		);
		$out = $svc->purgeStale(50);
		$this->assertSame(1, $out['failedDrafts']);
		$this->assertSame(0, $out['hollowClaims']);
	}

	/** @param list<int> $ids */
	private function selectIdsQb(array $ids): object
	{
		return new class ($ids) {
			/** @param list<int> $ids */
			public function __construct(private array $ids) {}
			public function select(...$a)
			{
				return $this;
			}
			public function from(...$a)
			{
				return $this;
			}
			public function where(...$a)
			{
				return $this;
			}
			public function andWhere(...$a)
			{
				return $this;
			}
			public function setMaxResults(...$a)
			{
				return $this;
			}
			public function expr()
			{
				return new class {
					public function isNull(...$a)
					{
						return 'isnull';
					}
					public function isNotNull(...$a)
					{
						return 'isnotnull';
					}
					public function lt(...$a)
					{
						return 'lt';
					}
					public function eq(...$a)
					{
						return 'eq';
					}
				};
			}
			public function createNamedParameter($v, ...$a)
			{
				return $v;
			}
			public function executeQuery()
			{
				$ids = $this->ids;
				return new class ($ids) {
					private int $i = 0;
					/** @param list<int> $ids */
					public function __construct(private array $ids) {}
					public function fetch()
					{
						if ($this->i >= count($this->ids)) {
							return false;
						}
						return ['id' => $this->ids[$this->i++]];
					}
					public function closeCursor(): void
					{
					}
				};
			}
		};
	}

	private function deleteQb(): object
	{
		return new class {
			public function delete(...$a)
			{
				return $this;
			}
			public function where(...$a)
			{
				return $this;
			}
			public function expr()
			{
				return new class {
					public function eq(...$a)
					{
						return 'eq';
					}
				};
			}
			public function createNamedParameter($v, ...$a)
			{
				return $v;
			}
			public function executeStatement(): int
			{
				return 1;
			}
		};
	}
}
