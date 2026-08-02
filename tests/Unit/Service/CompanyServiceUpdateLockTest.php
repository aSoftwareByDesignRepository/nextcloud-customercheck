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
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Company update/archive/restore must FOR UPDATE + re-read (parity with move).
 */
final class CompanyServiceUpdateLockTest extends TestCase
{
	public function testUpdateTakesLockRowAndReReadsStamp(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setName('Acme');
		$company->setSlug('acme');
		$company->setStatus('active');
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(4)->willReturn(true);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->once())->method('update')->willReturnCallback(static function (Company $c): Company {
			return $c;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01T11:00:00+00:00'));

		$svc = $this->service($mapper, $db, $timeline, $time);
		$stored = $svc->update(4, ['name' => 'Acme GmbH'], 'mgr', '2026-08-01T10:00:00+00:00');
		$this->assertSame('Acme GmbH', $stored->getName());
	}

	public function testUpdateRejectsWhenLockMisses(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(4)->willReturn(false);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$svc = $this->service($mapper, $db);
		$this->expectException(NotFoundException::class);
		$svc->update(4, ['name' => 'X'], 'mgr', '2026-08-01T10:00:00+00:00');
	}

	public function testUpdateReAssertsStampUnderLock(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setName('Acme');
		$company->setSlug('acme');
		$company->setStatus('active');
		$company->setUpdatedAt(new \DateTime('2026-08-01T12:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$svc = $this->service($mapper, $db);
		$this->expectException(ConflictException::class);
		$svc->update(4, ['name' => 'X'], 'mgr', '2026-08-01T10:00:00+00:00');
	}

	public function testArchiveTakesLockRow(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setName('Acme');
		$company->setSlug('acme');
		$company->setStatus('active');
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(4)->willReturn(true);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->once())->method('update')->willReturnCallback(static function (Company $c): Company {
			return $c;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('commit');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01T11:00:00+00:00'));

		$svc = $this->service($mapper, $db, null, $time);
		$stored = $svc->archive(4, 'mgr', '2026-08-01T10:00:00+00:00');
		$this->assertNotNull($stored->getArchivedAt());
	}

	public function testGdprEraseLocksBeforeSlugConfirm(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setName('Acme');
		$company->setSlug('acme');
		$company->setStatus('active');

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(4)->willReturn(true);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->never())->method('update');

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->expects($this->never())->method('findIdsByCompany');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);

		$svc = new CompanyService(
			$mapper,
			$this->createMock(PersonMapper::class),
			$agreements,
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(SlugService::class),
			$this->createMock(TimelineService::class),
			$db,
			$this->createMock(ITimeFactory::class),
			$l10n,
		);

		try {
			$svc->gdprErase(4, 'wrong-slug', 'mgr');
			$this->fail('Expected ValidationException');
		} catch (\OCA\CustomerCheck\Exception\ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('confirmSlug', $e->getFields());
		}
	}

	private function service(
		CompanyMapper $mapper,
		?IDBConnection $db = null,
		?TimelineService $timeline = null,
		?ITimeFactory $time = null,
	): CompanyService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		$conn = $db ?? $this->createMock(IDBConnection::class);
		// Standalone updates own the txn; conflict-resolve nesting sets true in those tests.
		$conn->method('inTransaction')->willReturn(false);
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
			$conn,
			$time ?? $this->createMock(ITimeFactory::class),
			$l10n,
		);
	}
}
