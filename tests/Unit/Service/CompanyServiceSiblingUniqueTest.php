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
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SHARED-IDENTITY I5 — unique sibling-link races must surface as LINK_CONFLICT, not 500 / SLUG_TAKEN.
 */
final class CompanyServiceSiblingUniqueTest extends TestCase
{
	private CompanyMapper&MockObject $companies;
	private SlugService&MockObject $slugs;
	private TimelineService&MockObject $timeline;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void
	{
		$this->companies = $this->createMock(CompanyMapper::class);
		$this->slugs = $this->createMock(SlugService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-07-26T12:00:00+00:00'));
	}

	private function service(): CompanyService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, array $a = []): string => $t);

		return new CompanyService(
			$this->companies,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->slugs,
			$this->timeline,
			$this->createMock(IDBConnection::class),
			$this->time,
			$l10n,
		);
	}

	private function company(int $id): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setName('Acme');
		$c->setSlug('acme');
		$c->setStatus('lead');
		$c->setLang('de');
		$c->setCountry('DE');
		$c->setCreatedBy('admin');
		$c->setCreatedAt(new \DateTime('2026-07-01T00:00:00+00:00'));
		$c->setUpdatedAt(new \DateTime('2026-07-26T11:00:00+00:00'));

		return $c;
	}

	private function uniqueDbException(string $message): DbException
	{
		$ex = $this->getMockBuilder(DbException::class)
			->setConstructorArgs([$message])
			->onlyMethods(['getReason'])
			->getMock();
		$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		return $ex;
	}

	public function testUpdateMapsPcUniqueRaceToLinkConflict(): void
	{
		$existing = $this->company(7);
		$this->companies->method('findById')->with(7)->willReturn($existing);
		$this->companies->method('findByPcCustomerId')->willReturn(null);
		$this->companies->method('update')->willThrowException(
			$this->uniqueDbException("Duplicate entry '42' for key 'crm_co_pc_uq'"),
		);

		try {
			$this->service()->update(7, ['pcCustomerId' => 42], 'admin', '2026-07-26 11:00:00');
			$this->fail('Expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame(Constants::ERR_LINK_CONFLICT, $e->getErrorCode());
			$this->assertStringContainsString('ProjectCheck', $e->getMessage());
		}
	}

	public function testUpdateMapsTcUniqueRaceToLinkConflict(): void
	{
		$existing = $this->company(8);
		$this->companies->method('findById')->with(8)->willReturn($existing);
		$this->companies->method('findByTcCustomerId')->willReturn(null);
		$this->companies->method('update')->willThrowException(
			$this->uniqueDbException("duplicate key value violates unique constraint \"crm_co_tc_uq\""),
		);

		try {
			$this->service()->update(8, ['tcCustomerId' => 9], 'admin', '2026-07-26 11:00:00');
			$this->fail('Expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame(Constants::ERR_LINK_CONFLICT, $e->getErrorCode());
			$this->assertStringContainsString('TicketCheck', $e->getMessage());
		}
	}

	public function testCreateDoesNotMislabelPcUniqueAsSlugTaken(): void
	{
		$this->slugs->method('validateForCreate')->willReturn('race-co');
		$this->companies->method('insert')->willThrowException(
			$this->uniqueDbException("Duplicate entry '99' for key 'crm_co_pc_uq'"),
		);

		try {
			$this->service()->create([
				'name' => 'Race Co',
				'slug' => 'race-co',
				'workspaceId' => 1,
				'pcCustomerId' => 99,
			], 'admin');
			$this->fail('Expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame(Constants::ERR_LINK_CONFLICT, $e->getErrorCode());
		}
	}
}
