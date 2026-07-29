<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\MobileIdempotencyMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MobileIdempotencyServiceTest extends TestCase
{
	private MobileIdempotencyMapper&MockObject $mapper;
	private ActivityService&MockObject $activities;
	private IDBConnection&MockObject $db;
	private ITimeFactory&MockObject $time;
	private IL10N&MockObject $l10n;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(MobileIdempotencyMapper::class);
		$this->activities = $this->createMock(ActivityService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $args = []): string => empty($args) ? $text : sprintf($text, ...$args),
		);
		$this->time->method('getTime')->willReturn(1_700_000_000);
	}

	private function service(): MobileIdempotencyService
	{
		return new MobileIdempotencyService(
			$this->mapper,
			$this->activities,
			$this->db,
			$this->time,
			$this->l10n,
		);
	}

	public function testNormaliseKeyRejectsOverlong(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->normaliseKey(str_repeat('a', 129));
	}

	public function testNormaliseKeyRejectsBadCharset(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->normaliseKey('bad key!');
	}

	public function testCreateWithoutKeyDelegates(): void
	{
		$activity = new Activity();
		$activity->setId(7);
		$this->activities->expects($this->once())
			->method('create')
			->with(['companyId' => 1], 'alice')
			->willReturn($activity);
		$this->mapper->expects($this->never())->method('insert');

		$result = $this->service()->createActivity('alice', ['companyId' => 1], null);
		self::assertSame(7, (int)$result->getId());
	}

	public function testCreateReplaysExistingKeyWithoutInsert(): void
	{
		$existing = new Activity();
		$existing->setId(42);
		$this->mapper->method('findActivityId')->with('alice', 'k1')->willReturn(42);
		$this->activities->expects($this->once())->method('get')->with(42)->willReturn($existing);
		$this->activities->expects($this->never())->method('create');
		$this->db->expects($this->never())->method('beginTransaction');

		$result = $this->service()->createActivity('alice', ['companyId' => 1], 'k1');
		self::assertSame(42, (int)$result->getId());
	}

	public function testCreateInsertsLedgerOnFirstWrite(): void
	{
		$created = new Activity();
		$created->setId(9);
		$this->mapper->method('findActivityId')->willReturn(null);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->activities->expects($this->once())->method('create')->willReturn($created);
		$this->mapper->expects($this->once())
			->method('insert')
			->with('alice', 'outbox:1', 9, 1_700_000_000);

		$result = $this->service()->createActivity('alice', ['companyId' => 1], 'outbox:1');
		self::assertSame(9, (int)$result->getId());
	}

	public function testUniqueConstraintRaceReturnsWinner(): void
	{
		$winner = new Activity();
		$winner->setId(11);
		$this->mapper->method('findActivityId')
			->willReturnOnConsecutiveCalls(null, null, 11);
		$this->db->method('inTransaction')->willReturn(true);
		$this->db->expects($this->once())->method('rollBack');
		$this->activities->method('create')->willReturnCallback(static function (): Activity {
			$a = new Activity();
			$a->setId(99);
			return $a;
		});
		$this->mapper->method('insert')->willThrowException(
			$this->createMock(UniqueConstraintViolationException::class),
		);
		$this->activities->expects($this->once())->method('get')->with(11)->willReturn($winner);

		$result = $this->service()->createActivity('alice', ['companyId' => 1], 'race-key');
		self::assertSame(11, (int)$result->getId());
	}
}
