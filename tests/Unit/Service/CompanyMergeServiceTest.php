<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyMergeService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CUC M1 S3 / AC4 — merge validation + transactional orchestration.
 */
class CompanyMergeServiceTest extends TestCase
{
	private CompanyMapper&MockObject $companies;
	private PersonMapper&MockObject $persons;
	private AgreementMapper&MockObject $agreements;
	private DealMapper&MockObject $deals;
	private ActivityMapper&MockObject $activities;
	private EmailTouchMapper&MockObject $emails;
	private TimelineEventMapper&MockObject $events;
	private TimelineService&MockObject $timeline;
	private IDBConnection&MockObject $db;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void
	{
		$this->companies = $this->createMock(CompanyMapper::class);
		$this->persons = $this->createMock(PersonMapper::class);
		$this->agreements = $this->createMock(AgreementMapper::class);
		$this->deals = $this->createMock(DealMapper::class);
		$this->activities = $this->createMock(ActivityMapper::class);
		$this->emails = $this->createMock(EmailTouchMapper::class);
		$this->events = $this->createMock(TimelineEventMapper::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-07-26T12:00:00+00:00'));
	}

	private function service(): CompanyMergeService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, array $a = []): string => $t);

		return new CompanyMergeService(
			$this->companies,
			$this->persons,
			$this->agreements,
			$this->deals,
			$this->activities,
			$this->emails,
			$this->events,
			$this->timeline,
			$this->db,
			$this->time,
			$l10n,
		);
	}

	private function company(int $id, string $name, bool $archived = false): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setName($name);
		$c->setSlug('slug-' . $id);
		$c->setUpdatedAt(new \DateTime('2026-07-26T12:00:00+00:00'));
		if ($archived) {
			$c->setArchivedAt(new \DateTime('2026-07-01T00:00:00+00:00'));
		}

		return $c;
	}

	public function testRejectsMergeIntoSelf(): void
	{
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessageMatches('/itself/');
		$this->service()->merge(5, 5, 'alice', '2026-07-26T12:00:00+00:00', '2026-07-26T12:00:00+00:00');
	}

	public function testRejectsArchivedTarget(): void
	{
		$source = $this->company(1, 'Dup');
		$target = $this->company(2, 'Keep', true);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->companies->method('lockRow')->willReturn(true);
		$this->companies->method('findById')->willReturnMap([[1, $source], [2, $target]]);

		try {
			$this->service()->merge(1, 2, 'alice', '2026-07-26T12:00:00+00:00', '2026-07-26T12:00:00+00:00');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_MERGE_TARGET_ARCHIVED, $e->getErrorCode());
		}
	}

	public function testRejectsConflictingPcLinks(): void
	{
		$source = $this->company(1, 'Dup');
		$source->setPcCustomerId(10);
		$target = $this->company(2, 'Keep');
		$target->setPcCustomerId(99);
		$this->db->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->companies->method('lockRow')->willReturn(true);
		$this->companies->method('findById')->willReturnMap([[1, $source], [2, $target]]);

		try {
			$this->service()->merge(1, 2, 'alice', '2026-07-26T12:00:00+00:00', '2026-07-26T12:00:00+00:00');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_MERGE_LINK_CONFLICT, $e->getErrorCode());
		}
	}

	public function testHappyPathMovesChildrenAndArchivesSource(): void
	{
		$source = $this->company(3, 'Dup Co');
		$source->setPcCustomerId(42);
		$keeper = $this->company(1, 'Keep Co');

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->db->method('tableExists')->willReturn(false);

		// Ascending lock order: 1 then 3
		$locked = [];
		$this->companies->expects($this->exactly(2))
			->method('lockRow')
			->willReturnCallback(function (int $id) use (&$locked): bool {
				$locked[] = $id;
				return true;
			});

		$this->companies->method('findById')->willReturnCallback(function (int $id) use ($source, $keeper): Company {
			return $id === 3 ? $source : $keeper;
		});

		$primary = new Person();
		$primary->setId(7);
		$this->persons->method('findPrimary')->with(1)->willReturn($primary);
		$this->persons->expects($this->once())->method('clearPrimaryFor')->with(3);
		$this->persons->expects($this->once())->method('reassignCompany')->with(3, 1)->willReturn(2);
		$this->agreements->expects($this->once())->method('reassignCompany')->with(3, 1)->willReturn(1);
		$this->deals->expects($this->once())->method('reassignCompany')->with(3, 1)->willReturn(0);
		$this->activities->expects($this->once())->method('reassignCompany')->with(3, 1)->willReturn(3);
		$this->emails->expects($this->once())->method('reassignCompany')->with(3, 1)->willReturn(0);
		$this->events->expects($this->once())->method('reassignCompany')->with(3, 1)->willReturn(4);

		$this->companies->expects($this->exactly(2))->method('update')->willReturnCallback(
			static fn (Company $c): Company => $c
		);

		$this->timeline->expects($this->once())
			->method('append')
			->with(
				1,
				Constants::EVENT_COMPANY_MERGED,
				'alice',
				$this->callback(static function (array $payload): bool {
					return ($payload['sourceId'] ?? null) === 3
						&& ($payload['sourceSlug'] ?? null) === 'slug-3'
						&& ($payload['moved']['persons'] ?? null) === 2
						&& ($payload['moved']['activities'] ?? null) === 3;
				}),
			);

		$result = $this->service()->merge(3, 1, 'alice', '2026-07-26T12:00:00+00:00', '2026-07-26T12:00:00+00:00');

		$this->assertSame([1, 3], $locked);
		$this->assertSame(1, $result['keeperId']);
		$this->assertSame(3, $result['sourceId']);
		$this->assertSame(2, $result['moved']['persons']);
		$this->assertNotNull($source->getArchivedAt());
		$this->assertNull($source->getPcCustomerId());
		$this->assertSame(42, $keeper->getPcCustomerId());
	}

	public function testHappyPathRehomesSharedMailWhenTablesExist(): void
	{
		$source = $this->company(3, 'Dup Co');
		$keeper = $this->company(1, 'Keep Co');

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->method('tableExists')->willReturnCallback(
			static fn (string $table): bool => in_array($table, ['crm_mail_msg', 'crm_mail_dft'], true),
		);

		$msgQb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$dftQb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		foreach ([$msgQb, $dftQb] as $qb) {
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);
			$qb->method('update')->willReturnSelf();
			$qb->method('set')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
		}
		$msgQb->expects($this->once())->method('executeStatement')->willReturn(4);
		$dftQb->expects($this->once())->method('executeStatement')->willReturn(1);

		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($msgQb, $dftQb);

		$this->companies->method('lockRow')->willReturn(true);
		$this->companies->method('findById')->willReturnCallback(
			static fn (int $id): Company => $id === 3 ? $source : $keeper,
		);
		$this->persons->method('findPrimary')->willReturn(null);
		$this->persons->method('reassignCompany')->willReturn(0);
		$this->agreements->method('reassignCompany')->willReturn(0);
		$this->deals->method('reassignCompany')->willReturn(0);
		$this->activities->method('reassignCompany')->willReturn(0);
		$this->emails->method('reassignCompany')->willReturn(0);
		$this->events->method('reassignCompany')->willReturn(0);
		$this->companies->method('update')->willReturnCallback(static fn (Company $c): Company => $c);
		$this->timeline->expects($this->once())->method('append');

		$result = $this->service()->merge(3, 1, 'alice', '2026-07-26T12:00:00+00:00', '2026-07-26T12:00:00+00:00');

		$this->assertSame(4, $result['moved']['sharedMailMessages']);
		$this->assertSame(1, $result['moved']['sharedMailDrafts']);
	}

	public function testRejectsStaleKeeperPrecondition(): void
	{
		$source = $this->company(1, 'Dup');
		$keeper = $this->company(2, 'Keep');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->companies->method('lockRow')->willReturn(true);
		$this->companies->method('findById')->willReturnMap([
			[1, $source],
			[2, $keeper],
		]);

		$this->expectException(\OCA\CustomerCheck\Exception\ConflictException::class);
		$this->service()->merge(
			1,
			2,
			'alice',
			'2026-07-26T12:00:00+00:00',
			'2026-07-25T00:00:00+00:00',
		);
	}

	public function testRejectsMissingKeeperPrecondition(): void
	{
		$source = $this->company(1, 'Dup');
		$keeper = $this->company(2, 'Keep');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->companies->method('lockRow')->willReturn(true);
		$this->companies->method('findById')->willReturnMap([
			[1, $source],
			[2, $keeper],
		]);

		$this->expectException(\OCA\CustomerCheck\Exception\ConflictException::class);
		$this->service()->merge(
			1,
			2,
			'alice',
			'2026-07-26T12:00:00+00:00',
			null,
		);
	}
}
