<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * AC-CC-21 / AC-CC-23 — contacts_uid soft-link persist + clear + delete lock.
 */
final class PersonServiceContactsUidTest extends TestCase
{
	private PersonMapper $mapper;
	private CompanyService $companies;
	private TimelineService $timeline;
	private IDBConnection $db;
	private PersonService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(PersonMapper::class);
		$this->companies = $this->createMock(CompanyService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-27T12:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$this->svc = new PersonService(
			$this->mapper,
			$this->companies,
			$this->timeline,
			$this->db,
			$time,
			$l,
		);
	}

	public function testCreatePersistsContactsUid(): void
	{
		$company = new Company();
		$company->setId(7);
		$this->companies->method('get')->with(7)->willReturn($company);
		$this->companies->expects(self::once())->method('lockRow')->with(7);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');

		$captured = null;
		$this->mapper->expects(self::once())->method('insert')->willReturnCallback(
			static function (Person $p) use (&$captured): Person {
				$captured = $p;
				$p->setId(42);

				return $p;
			},
		);
		$this->timeline->expects(self::once())->method('append');

		$out = $this->svc->create(7, [
			'name' => 'Ada Lovelace',
			'email' => 'ada@example.com',
			'contactsUid' => 'contact-uid-ada',
		], 'admin');

		self::assertSame(42, (int)$out->getId());
		self::assertInstanceOf(Person::class, $captured);
		self::assertSame('contact-uid-ada', $captured->getContactsUid());
	}

	public function testCreateRejectsControlCharsInContactsUid(): void
	{
		$company = new Company();
		$company->setId(7);
		$this->companies->method('get')->willReturn($company);
		$this->expectException(ValidationException::class);
		$this->svc->create(7, [
			'name' => 'Ada',
			'contactsUid' => "bad\nuid",
		], 'admin');
	}

	public function testUpdateClearsContactsUidWithOptimisticLock(): void
	{
		$person = new Person();
		$person->setId(9);
		$person->setCompanyId(7);
		$person->setName('Ada');
		$person->setContactsUid('old-uid');
		$person->setUpdatedAt(new \DateTime('2026-07-27T10:00:00+00:00'));
		$person->setIsPrimary(0);

		$this->mapper->method('findById')->with(9)->willReturn($person);
		$this->companies->expects(self::once())->method('lockRow')->with(7);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->mapper->expects(self::once())->method('update')->willReturnCallback(
			static function (Person $p): Person {
				self::assertNull($p->getContactsUid());

				return $p;
			},
		);
		$this->timeline->expects(self::once())->method('append');

		$this->svc->update(9, ['contactsUid' => null], 'admin', '2026-07-27T10:00:00+00:00');
	}

	public function testDeleteRequiresUpdatedAtPrecondition(): void
	{
		$person = new Person();
		$person->setId(9);
		$person->setCompanyId(7);
		$person->setName('Ada');
		$person->setUpdatedAt(new \DateTime('2026-07-27T10:00:00+00:00'));
		$this->mapper->method('findById')->with(9)->willReturn($person);

		$this->expectException(ConflictException::class);
		$this->svc->delete(9, 'admin', null);
	}

	public function testDeleteWithMatchingLockRemovesPerson(): void
	{
		$person = new Person();
		$person->setId(9);
		$person->setCompanyId(7);
		$person->setName('Ada');
		$person->setUpdatedAt(new \DateTime('2026-07-27T10:00:00+00:00'));
		$this->mapper->method('findById')->with(9)->willReturn($person);
		$this->mapper->expects(self::once())->method('delete')->with($person);
		$this->timeline->expects(self::once())->method('append');

		$this->svc->delete(9, 'admin', '2026-07-27T10:00:00+00:00');
	}
}
