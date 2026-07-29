<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\OooCoverService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class OooCoverServiceTest extends TestCase
{
	public function testMineOwnerIdsIncludesActivePrimary(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$resultFactory = static function (): object {
			return new class {
				private int $i = 0;
				private array $rows = [
					[
						'id' => 1,
						'primary_user_id' => 'alex',
						'backup_user_id' => 'sam',
						'starts_on' => '2026-07-01',
						'ends_on' => '2026-07-31',
					],
					[
						'id' => 2,
						'primary_user_id' => 'pat',
						'backup_user_id' => 'sam',
						'starts_on' => '2026-01-01',
						'ends_on' => '2026-01-05',
					],
				];
				public function fetch(): array|false
				{
					$row = $this->rows[$this->i] ?? null;
					$this->i++;
					return $row ?? false;
				}
				public function closeCursor(): void {}
			};
		};
		$qb = new class($resultFactory) {
			public function __construct(private $resultFactory) {}
			public function select(...$a): self { return $this; }
			public function from(...$a): self { return $this; }
			public function where(...$a): self { return $this; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
				};
			}
			public function createNamedParameter(...$a): string { return '?'; }
			public function executeQuery(): object { return ($this->resultFactory)(); }
		};
		$db->method('getQueryBuilder')->willReturn($qb);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));

		$svc = new OooCoverService($db, $time);
		$this->assertSame(['alex'], $svc->primariesCoveredBy(7, 'sam'));
		$this->assertSame(['sam', 'alex'], $svc->mineOwnerIds(7, 'sam'));
	}

	public function testEmptyBackupReturnsEmpty(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('tableExists');
		$time = $this->createMock(ITimeFactory::class);
		$svc = new OooCoverService($db, $time);
		$this->assertSame([], $svc->primariesCoveredBy(7, ''));
		$this->assertSame([], $svc->mineOwnerIds(7, ''));
	}
}
