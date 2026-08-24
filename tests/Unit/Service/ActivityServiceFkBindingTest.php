<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\VTodoService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/** Same-company FK binding on activity create/update. */
final class ActivityServiceFkBindingTest extends TestCase
{
	public function testCreateRejectsForeignDealId(): void
	{
		$foreign = new Deal();
		$foreign->setId(99);
		$foreign->setCompanyId(2);

		$deals = $this->createMock(DealMapper::class);
		$deals->method('findById')->with(99)->willReturn($foreign);

		$svc = $this->service(
			$this->createMock(ActivityMapper::class),
			$deals,
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
		);

		try {
			$svc->create([
				'companyId' => 1,
				'title' => 'Call',
				'type' => 'call',
				'dueDate' => '2026-08-10',
				'dealId' => 99,
			], 'ae');
			$this->fail('foreign deal must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('dealId', $e->getFields());
		}
	}

	public function testCreateRejectsMissingPersonId(): void
	{
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$svc = $this->service(
			$this->createMock(ActivityMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(AgreementMapper::class),
			$persons,
		);

		try {
			$svc->create([
				'companyId' => 1,
				'title' => 'Call',
				'type' => 'call',
				'dueDate' => '2026-08-10',
				'personId' => 5,
			], 'ae');
			$this->fail('missing person must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('personId', $e->getFields());
		}
	}

	public function testCreateAcceptsSameCompanyAgreement(): void
	{
		$agreement = new Agreement();
		$agreement->setId(7);
		$agreement->setCompanyId(1);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findById')->with(7)->willReturn($agreement);

		$stored = new Activity();
		$stored->setId(3);
		$mapper = $this->createMock(ActivityMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(function (Activity $a) use ($stored) {
			$this->assertSame(7, (int)$a->getAgreementId());
			$this->assertSame(1, (int)$a->getCompanyId());
			return $stored;
		});

		$svc = $this->service(
			$mapper,
			$this->createMock(DealMapper::class),
			$agreements,
			$this->createMock(PersonMapper::class),
		);

		$out = $svc->create([
			'companyId' => 1,
			'title' => 'Renew',
			'type' => 'todo',
			'dueDate' => '2026-08-10',
			'agreementId' => 7,
		], 'ae');
		$this->assertSame(3, (int)$out->getId());
	}

	private function service(
		ActivityMapper $mapper,
		DealMapper $deals,
		AgreementMapper $agreements,
		PersonMapper $persons,
	): ActivityService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$company = new \OCA\CustomerCheck\Db\Company();
		$company->setId(1);
		$company->setWorkspaceId(9);
		$companies = $this->createMock(\OCA\CustomerCheck\Db\CompanyMapper::class);
		$companies->method('findById')->willReturn($company);
		$members = $this->createMock(\OCA\CustomerCheck\Db\WorkspaceMemberMapper::class);
		$members->method('findMembershipOrNull')->willReturn(new \OCA\CustomerCheck\Db\WorkspaceMember());

		return new ActivityService(
			$mapper,
			$this->createMock(AccessControlService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(VTodoService::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$deals,
			$agreements,
			$persons,
			$companies,
			$members,
			$this->createMock(\OCA\CustomerCheck\Service\NotificationService::class),
		);
	}
}
