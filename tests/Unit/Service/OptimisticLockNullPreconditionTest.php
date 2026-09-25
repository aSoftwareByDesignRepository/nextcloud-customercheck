<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\EditConflictService;
use OCA\CustomerCheck\Service\EntityRevisionService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression: a missing `updatedAt` precondition must throw ConflictException
 * (HTTP 409 CONFLICT_STALE), never crash with TypeError inside
 * EntityRevisionService::snapshotAt() (live-verified 500 SERVER_ERROR).
 *
 * The conflict-resolution branch must rethrow the stale conflict when the
 * precondition is null/empty instead of calling snapshotAt(null) — the same
 * guard DealService already had.
 */
final class OptimisticLockNullPreconditionTest extends TestCase
{
	/** @var IDBConnection&MockObject */
	private IDBConnection $db;
	/** @var IL10N&MockObject */
	private IL10N $l10n;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $timeFactory;
	/** @var EditConflictService&MockObject */
	private EditConflictService $editConflicts;
	/** @var EntityRevisionService&MockObject */
	private EntityRevisionService $entityRevisions;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('inTransaction')->willReturn(false);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-09-25 12:00:00'));
		$this->editConflicts = $this->createMock(EditConflictService::class);
		$this->entityRevisions = $this->createMock(EntityRevisionService::class);
	}

	public function testCompanyUpdateMissingPreconditionIs409NotTypeError(): void
	{
		$companyMapper = $this->createMock(CompanyMapper::class);
		$company = new Company();
		$company->setId(7);
		$company->setWorkspaceId(3);
		$company->setName('Acme');
		$company->setUpdatedAt(new \DateTime('2026-09-01 10:00:00'));
		$companyMapper->method('lockRow')->with(7)->willReturn(true);
		$companyMapper->method('findById')->with(7)->willReturn($company);
		// The bug: snapshotAt('company', 7, null) → TypeError → 500. Must never run.
		$this->entityRevisions->expects($this->never())->method('snapshotAt');

		$service = new CompanyService(
			$companyMapper,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(SlugService::class),
			$this->createMock(TimelineService::class),
			$this->db,
			$this->timeFactory,
			$this->l10n,
			$this->editConflicts,
			$this->entityRevisions,
		);

		$this->expectException(ConflictException::class);
		$service->update(7, ['name' => 'Renamed'], 'alice', null);
	}

	public function testCompanyUpdateEmptyPreconditionIs409NotTypeError(): void
	{
		$companyMapper = $this->createMock(CompanyMapper::class);
		$company = new Company();
		$company->setId(7);
		$company->setWorkspaceId(3);
		$company->setName('Acme');
		$company->setUpdatedAt(new \DateTime('2026-09-01 10:00:00'));
		$companyMapper->method('lockRow')->with(7)->willReturn(true);
		$companyMapper->method('findById')->with(7)->willReturn($company);
		$this->entityRevisions->expects($this->never())->method('snapshotAt');

		$service = new CompanyService(
			$companyMapper,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(SlugService::class),
			$this->createMock(TimelineService::class),
			$this->db,
			$this->timeFactory,
			$this->l10n,
			$this->editConflicts,
			$this->entityRevisions,
		);

		$this->expectException(ConflictException::class);
		$service->update(7, ['name' => 'Renamed'], 'alice', '');
	}

	public function testAgreementUpdateMissingPreconditionIs409NotTypeError(): void
	{
		$agreementMapper = $this->createMock(AgreementMapper::class);
		$agreement = new Agreement();
		$agreement->setId(11);
		$agreement->setCompanyId(7);
		$agreement->setUpdatedAt(new \DateTime('2026-09-01 10:00:00'));
		$agreementMapper->method('lockRow')->with(11)->willReturn(true);
		$agreementMapper->method('findById')->with(11)->willReturn($agreement);
		$this->entityRevisions->expects($this->never())->method('snapshotAt');

		$service = new AgreementService(
			$agreementMapper,
			$this->createMock(CompanyService::class),
			$this->createMock(AgreementStatusService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(ProductCodeRegistry::class),
			$this->db,
			$this->timeFactory,
			$this->l10n,
			$this->editConflicts,
			$this->entityRevisions,
		);

		$this->expectException(ConflictException::class);
		$service->update(11, ['label' => 'New label'], 'alice', null);
	}

	public function testPersonUpdateMissingPreconditionIs409NotTypeError(): void
	{
		$this->entityRevisions->expects($this->never())->method('snapshotAt');

		$service = new PersonService(
			$this->createMock(PersonMapper::class),
			$this->createMock(CompanyService::class),
			$this->createMock(TimelineService::class),
			$this->db,
			$this->timeFactory,
			$this->l10n,
			$this->editConflicts,
			$this->entityRevisions,
		);

		$this->expectException(ConflictException::class);
		$service->update(5, ['name' => 'Jane'], 'alice', null);
	}

	/**
	 * CAS bypass regression: stale precondition + NO recorded base revision
	 * must fail closed (409), never merge against current snapshot —
	 * the old `?? $currentSnap` fallback applied the stale patch (LWW).
	 */
	public function testCompanyUpdateStaleWithoutBaseRevisionFailsClosed(): void
	{
		$companyMapper = $this->createMock(CompanyMapper::class);
		$company = new Company();
		$company->setId(7);
		$company->setWorkspaceId(3);
		$company->setName('Acme');
		$company->setUpdatedAt(new \DateTime('2026-09-01 10:00:00'));
		$companyMapper->method('lockRow')->with(7)->willReturn(true);
		$companyMapper->method('findById')->with(7)->willReturn($company);
		// No base revision at that stamp → must rethrow stale, not apply patch.
		$this->entityRevisions->expects($this->once())
			->method('snapshotAt')
			->with('company', 7, $this->isType('string'))
			->willReturn(null);
		$companyMapper->expects($this->never())->method('update');

		$service = new CompanyService(
			$companyMapper,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(SlugService::class),
			$this->createMock(TimelineService::class),
			$this->db,
			$this->timeFactory,
			$this->l10n,
			$this->editConflicts,
			$this->entityRevisions,
		);

		// Stale precondition + no base revision → rethrows the stale conflict.
		$this->expectException(ConflictException::class);
		$service->update(7, ['name' => 'Stale Write'], 'alice', '2020-01-01T00:00:00+00:00');
	}

	/**
	 * Positive control: stale precondition WITH a recorded base revision and a
	 * non-overlapping field still merges (GNQ §6.3 three-way merge stays live).
	 */
	public function testCompanyUpdateStaleWithBaseRevisionMergesNonOverlapping(): void
	{
		$companyMapper = $this->createMock(CompanyMapper::class);
		$company = new Company();
		$company->setId(7);
		$company->setWorkspaceId(3);
		$company->setName('Acme');
		$company->setStatus('lead');
		$company->setUpdatedAt(new \DateTime('2026-09-01 10:00:00'));
		$companyMapper->method('lockRow')->with(7)->willReturn(true);
		$companyMapper->method('findById')->with(7)->willReturn($company);
		// Base revision exists: name was already 'Acme' at the client's stamp.
		$this->entityRevisions->method('snapshotAt')->willReturn([
			'name' => 'Acme',
			'status' => 'lead',
			'accountOwnerUserId' => '',
			'kamTier' => 'standard',
			'touchIntervalDays' => 0,
			'nextTouchOn' => '',
			'filesFolderId' => 0,
		]);
		$companyMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (Company $c): Company => $c);
		$this->entityRevisions->method('append');
		$timeline = $this->createMock(TimelineService::class);
		$timeline->method('append');

		$service = new CompanyService(
			$companyMapper,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(SlugService::class),
			$timeline,
			$this->db,
			$this->timeFactory,
			$this->l10n,
			$this->editConflicts,
			$this->entityRevisions,
		);

		$stored = $service->update(7, ['name' => 'Merged Name'], 'alice', '2026-08-30T09:00:00+00:00');
		$this->assertSame('Merged Name', $stored->getName());
	}
}
