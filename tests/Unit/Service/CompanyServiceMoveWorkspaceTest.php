<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Company workspace move — optimistic lock, slug confirm, mail rematch.
 */
final class CompanyServiceMoveWorkspaceTest extends TestCase
{
	public function testMoveRequiresUpdatedAtPrecondition(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);

		$svc = $this->service($mapper);
		$this->expectException(ConflictException::class);
		$svc->moveToWorkspace(7, 2, 'acme', 'mgr', null);
	}

	public function testMoveRejectsWrongSlug(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);

		$svc = $this->service($mapper);
		try {
			$svc->moveToWorkspace(7, 2, 'wrong', 'mgr', '2026-08-01T10:00:00+00:00');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('confirmSlug', $e->getFields());
		}
	}

	public function testGenericUpdateRejectsWorkspaceChange(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setName('Acme');
		$company->setStatus('active');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$svc = $this->service($mapper, $db);
		try {
			$svc->update(7, ['workspaceId' => 2], 'mgr', '2026-08-01T10:00:00+00:00');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('workspaceId', $e->getFields());
		}
	}

	public function testMoveHappyPathUnmatchesMailAndUpdatesWorkspace(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setName('Acme');
		$company->setStatus('active');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$stored = clone $company;
		$stored->setWorkspaceId(2);
		$stored->setUpdatedAt(new \DateTime('2026-08-01T11:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->once())->method('lockRow')->with(7)->willReturn(true);
		$mapper->expects($this->once())->method('update')->willReturn($stored);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$selectWs = $this->createMock(IQueryBuilder::class);
		$selectWs->method('expr')->willReturn($expr);
		$selectWs->method('select')->willReturnSelf();
		$selectWs->method('from')->willReturnSelf();
		$selectWs->method('where')->willReturnSelf();
		$selectWs->method('setMaxResults')->willReturnSelf();
		$selectWs->method('createNamedParameter')->willReturn('p');
		$wsResult = $this->createMock(\OCP\DB\IResult::class);
		$wsResult->method('fetch')->willReturn(['id' => 2]);
		$wsResult->method('closeCursor');
		$selectWs->method('executeQuery')->willReturn($wsResult);

		$updateMail = $this->createMock(IQueryBuilder::class);
		$updateMail->method('expr')->willReturn($expr);
		$updateMail->method('update')->willReturnSelf();
		$updateMail->method('set')->willReturnSelf();
		$updateMail->method('where')->willReturnSelf();
		$updateMail->method('andWhere')->willReturnSelf();
		$updateMail->method('createNamedParameter')->willReturn('p');
		$updateMail->expects($this->once())->method('executeStatement')->willReturn(3);

		$updateDraft = $this->createMock(IQueryBuilder::class);
		$updateDraft->method('expr')->willReturn($expr);
		$updateDraft->method('update')->willReturnSelf();
		$updateDraft->method('set')->willReturnSelf();
		$updateDraft->method('where')->willReturnSelf();
		$updateDraft->method('andWhere')->willReturnSelf();
		$updateDraft->method('createNamedParameter')->willReturn('p');
		$updateDraft->expects($this->once())->method('executeStatement')->willReturn(1);

		$ledgerUpdates = [];
		foreach ([2, 1, 0, 0] as $affected) {
			$u = $this->createMock(IQueryBuilder::class);
			$u->method('expr')->willReturn($expr);
			$u->method('update')->willReturnSelf();
			$u->method('set')->willReturnSelf();
			$u->method('where')->willReturnSelf();
			$u->method('andWhere')->willReturnSelf();
			$u->method('createNamedParameter')->willReturn('p');
			$u->expects($this->once())->method('executeStatement')->willReturn($affected);
			$ledgerUpdates[] = $u;
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$selectWs,
			$updateMail,
			$updateDraft,
			$ledgerUpdates[0],
			$ledgerUpdates[1],
			$ledgerUpdates[2],
			$ledgerUpdates[3],
		);

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append')
			->with(7, 'company.moved', 'mgr', $this->callback(static function (array $p): bool {
				return ($p['fromWorkspaceId'] ?? null) === 1
					&& ($p['toWorkspaceId'] ?? null) === 2
					&& ($p['mailUnmatched'] ?? null) === 3
					&& ($p['promisesRestamped'] ?? null) === 2
					&& ($p['plansRestamped'] ?? null) === 1;
			}));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01T11:00:00+00:00'));

		$svc = $this->service($mapper, $db, $timeline, $time);
		$result = $svc->moveToWorkspace(7, 2, 'acme', 'mgr', '2026-08-01T10:00:00+00:00');

		$this->assertSame(2, (int)$result['company']->getWorkspaceId());
		$this->assertSame(3, $result['mailUnmatched']);
		$this->assertSame(1, $result['draftsDetached']);
		$this->assertSame(2, $result['promisesRestamped']);
		$this->assertSame(1, $result['plansRestamped']);
	}

	public function testMoveRejectsWhenLockMisses(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->once())->method('lockRow')->with(7)->willReturn(false);
		$mapper->expects($this->never())->method('update');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$selectWs = $this->createMock(IQueryBuilder::class);
		$selectWs->method('expr')->willReturn($expr);
		$selectWs->method('select')->willReturnSelf();
		$selectWs->method('from')->willReturnSelf();
		$selectWs->method('where')->willReturnSelf();
		$selectWs->method('setMaxResults')->willReturnSelf();
		$selectWs->method('createNamedParameter')->willReturn('p');
		$wsResult = $this->createMock(\OCP\DB\IResult::class);
		$wsResult->method('fetch')->willReturn(['id' => 2]);
		$wsResult->method('closeCursor');
		$selectWs->method('executeQuery')->willReturn($wsResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->method('getQueryBuilder')->willReturn($selectWs);

		$svc = $this->service($mapper, $db);
		$this->expectException(NotFoundException::class);
		$svc->moveToWorkspace(7, 2, 'acme', 'mgr', '2026-08-01T10:00:00+00:00');
	}

	public function testMoveMissingDestinationWorkspace(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$selectWs = $this->createMock(IQueryBuilder::class);
		$selectWs->method('expr')->willReturn($expr);
		$selectWs->method('select')->willReturnSelf();
		$selectWs->method('from')->willReturnSelf();
		$selectWs->method('where')->willReturnSelf();
		$selectWs->method('setMaxResults')->willReturnSelf();
		$selectWs->method('createNamedParameter')->willReturn('p');
		$wsResult = $this->createMock(\OCP\DB\IResult::class);
		$wsResult->method('fetch')->willReturn(false);
		$wsResult->method('closeCursor');
		$selectWs->method('executeQuery')->willReturn($wsResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($selectWs);

		$svc = $this->service($mapper, $db);
		$this->expectException(NotFoundException::class);
		$svc->moveToWorkspace(7, 99, 'acme', 'mgr', '2026-08-01T10:00:00+00:00');
	}

	private function service(
		CompanyMapper $mapper,
		?IDBConnection $db = null,
		?TimelineService $timeline = null,
		?ITimeFactory $time = null,
	): CompanyService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		return new CompanyService(
			$mapper,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(SlugService::class),
			$timeline ?? $this->createMock(TimelineService::class),
			$db ?? $this->createMock(IDBConnection::class),
			$time ?? $this->createMock(ITimeFactory::class),
			$l10n,
		);
	}
}
