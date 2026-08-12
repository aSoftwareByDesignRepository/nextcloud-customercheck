<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\CallbackService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class CallbackServiceTest extends TestCase
{
	private function service(
		?ActivityService $activities = null,
		?PersonService $persons = null,
		?CompanyService $companies = null,
		?TimelineService $timeline = null,
	): CallbackService {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $s, array $a = []): string {
			return $a === [] ? $s : vsprintf(str_replace('%s', '%s', $s), $a);
		});

		return new CallbackService(
			$activities ?? $this->createMock(ActivityService::class),
			$persons ?? $this->createMock(PersonService::class),
			$companies ?? $this->createMock(CompanyService::class),
			$timeline ?? $this->createMock(TimelineService::class),
			$l,
		);
	}

	public function testScheduleRequiresDueDate(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::once())->method('get')->with(7)->willReturn(new Company());
		$svc = $this->service(companies: $companies);
		$this->expectException(ValidationException::class);
		$svc->schedule(7, ['dueDate' => ''], 'admin');
	}

	public function testScheduleRejectsImpossibleCalendarDate(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->expects(self::once())->method('get')->with(7)->willReturn(new Company());
		$svc = $this->service(companies: $companies);
		$this->expectException(ValidationException::class);
		$svc->schedule(7, ['dueDate' => '2026-02-30'], 'admin');
	}

	public function testScheduleRejectsPersonFromOtherCompany(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn(new Company());
		$person = new Person();
		$person->setCompanyId(99);
		$person->setName('Ada');
		$persons = $this->createMock(PersonService::class);
		$persons->method('get')->with(3)->willReturn($person);
		$svc = $this->service(persons: $persons, companies: $companies);
		$this->expectException(ValidationException::class);
		$svc->schedule(7, ['dueDate' => '2026-08-01', 'personId' => 3], 'admin');
	}

	public function testScheduleCreatesCallbackFlaggedActivity(): void
	{
		$company = new Company();
		$company->setName('Acme');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$created = new Activity();
		$created->setId(42);
		$activities = $this->createMock(ActivityService::class);
		$activities->expects(self::once())->method('create')->with(
			self::callback(static function (array $in): bool {
				return ($in['type'] ?? '') === 'call'
					&& ($in['isCallback'] ?? 0) === 1
					&& ($in['dueDate'] ?? '') === '2026-08-01'
					&& str_contains((string)($in['title'] ?? ''), 'Callback:');
			}),
			'admin',
		)->willReturn($created);
		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects(self::once())->method('append');
		$svc = $this->service(activities: $activities, companies: $companies, timeline: $timeline);
		$out = $svc->schedule(7, ['dueDate' => '2026-08-01'], 'admin');
		self::assertSame(42, (int)$out->getId());
	}

	public function testIsCallbackActivityUsesFlagOrCallTypeOrTitle(): void
	{
		$svc = $this->service();
		$flagged = new Activity();
		$flagged->setIsCallback(1);
		$flagged->setType('meeting');
		$flagged->setTitle('x');
		self::assertTrue($svc->isCallbackActivity($flagged));

		$call = new Activity();
		$call->setIsCallback(0);
		$call->setType('call');
		$call->setTitle('Chat');
		self::assertTrue($svc->isCallbackActivity($call));

		$titled = new Activity();
		$titled->setIsCallback(0);
		$titled->setType('note');
		$titled->setTitle('Callback: Ada');
		self::assertTrue($svc->isCallbackActivity($titled));

		$plain = new Activity();
		$plain->setIsCallback(0);
		$plain->setType('note');
		$plain->setTitle('Just a note');
		self::assertFalse($svc->isCallbackActivity($plain));
	}
}
