<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\EmailTouch;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class EmailTouchServiceSimpleLogTest extends TestCase
{
	public function testCreateManualFromPersonIdFillsAddresses(): void
	{
		$company = $this->createMock(CompanyService::class);
		$company->expects($this->once())->method('get');

		$person = new Person();
		$person->setId(5);
		$person->setCompanyId(1);
		$person->setName('Ada');
		$person->setEmail('ada@acme.example');

		$persons = $this->createMock(PersonService::class);
		$persons->method('get')->with(5)->willReturn($person);

		$stored = new EmailTouch();
		$stored->setId(99);
		$mapper = $this->createMock(EmailTouchMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(function (EmailTouch $t) use ($stored) {
			$this->assertSame('outbound', $t->getDirection());
			$this->assertSame('crm@local', $t->getFromAddr());
			$this->assertSame('ada@acme.example', $t->getToAddr());
			$this->assertSame('Quote', $t->getSubject());
			$this->assertSame(5, (int)$t->getPersonId());
			return $stored;
		});

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25 12:00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$svc = new EmailTouchService($mapper, $company, $persons, $timeline, $time, $l10n);
		$result = $svc->createManual(1, [
			'personId' => 5,
			'subject' => 'Quote',
			'direction' => 'outbound',
		], 'admin');
		$this->assertSame(99, (int)$result->getId());
	}

	public function testPersonWithoutEmailRejected(): void
	{
		$company = $this->createMock(CompanyService::class);
		$company->method('get');

		$person = new Person();
		$person->setId(5);
		$person->setCompanyId(1);
		$person->setName('Ada');
		$person->setEmail('');

		$persons = $this->createMock(PersonService::class);
		$persons->method('get')->willReturn($person);

		$svc = new EmailTouchService(
			$this->createMock(EmailTouchMapper::class),
			$company,
			$persons,
			$this->createMock(TimelineService::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);
		$this->expectException(ValidationException::class);
		$svc->createManual(1, ['personId' => 5, 'subject' => 'Hi'], 'admin');
	}

	public function testForeignPersonRejectedEvenWithAddresses(): void
	{
		$company = $this->createMock(CompanyService::class);
		$company->method('get');

		$person = new Person();
		$person->setId(5);
		$person->setCompanyId(99);
		$person->setName('Eve');
		$person->setEmail('eve@other.example');

		$persons = $this->createMock(PersonService::class);
		$persons->method('get')->with(5)->willReturn($person);

		$mapper = $this->createMock(EmailTouchMapper::class);
		$mapper->expects($this->never())->method('insert');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$svc = new EmailTouchService(
			$mapper,
			$company,
			$persons,
			$this->createMock(TimelineService::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);

		try {
			$svc->createManual(1, [
				'personId' => 5,
				'subject' => 'Hi',
				'fromAddr' => 'ae@crm.example',
				'toAddr' => 'eve@other.example',
			], 'admin');
			$this->fail('foreign person must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('personId', $e->getFields());
		}
	}
}
