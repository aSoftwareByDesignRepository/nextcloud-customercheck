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
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Account owner reassignment mirrors deal steal guard (§13.5).
 */
final class CompanyAccountOwnerGuardTest extends TestCase
{
	private function companyOwnedBy(string $owner): Company
	{
		$c = new Company();
		$c->setId(5);
		$c->setName('Acme');
		$c->setSlug('acme');
		$c->setStatus('customer');
		$c->setAccountOwnerUserId($owner);
		$c->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));
		return $c;
	}

	private function service(CompanyMapper $mapper): CompanyService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');

		$mapper->method('lockRow')->willReturn(true);

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
			$this->createMock(TimelineService::class),
			$db,
			$time,
			$l10n,
		);
	}

	public function testMemberCannotStealAccountOwner(): void
	{
		$company = $this->companyOwnedBy('sam');
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->never())->method('update');

		$this->expectException(ValidationException::class);
		try {
			$this->service($mapper)->update(
				5,
				['accountOwnerUserId' => 'ae', 'updatedAt' => '2026-07-20T10:00:00+00:00'],
				'ae',
				'2026-07-20T10:00:00+00:00',
				false,
			);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_DEAL_OWNED, $e->getErrorCode());
			throw $e;
		}
	}

	public function testManagerMayReassignAccountOwner(): void
	{
		$company = $this->companyOwnedBy('sam');
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->once())->method('update')->willReturnCallback(static function (Company $c): Company {
			return $c;
		});

		$result = $this->service($mapper)->update(
			5,
			['accountOwnerUserId' => 'ae', 'updatedAt' => '2026-07-20T10:00:00+00:00'],
			'manager',
			'2026-07-20T10:00:00+00:00',
			true,
		);
		$this->assertSame('ae', $result->getAccountOwnerUserId());
	}
}
