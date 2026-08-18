<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/** Deal create/update binds personId + leadSource with same-company FK guards. */
final class DealServicePersonLeadSourceTest extends TestCase
{
	public function testCreateSetsPersonAndLeadSource(): void
	{
		$company = new Company();
		$company->setId(4);

		$person = new Person();
		$person->setId(9);
		$person->setCompanyId(4);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(4)->willReturn($company);

		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->with(9)->willReturn($person);

		$inserted = null;
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (Deal $d) use (&$inserted): Deal {
			$d->setId(42);
			$inserted = $d;
			return $d;
		});

		$svc = $this->service($mapper, $persons);

		$deal = $svc->create([
			'companyId' => 4,
			'title' => 'Expansion',
			'personId' => 9,
			'leadSource' => 'referral',
		], 'ae');

		$this->assertSame(42, (int)$deal->getId());
		$this->assertNotNull($inserted);
		$this->assertSame(9, $inserted->getPersonId());
		$this->assertSame('referral', $inserted->getLeadSource());
	}

	public function testCreateRejectsForeignPerson(): void
	{
		$company = new Company();
		$company->setId(1);

		$foreign = new Person();
		$foreign->setId(5);
		$foreign->setCompanyId(2);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(1)->willReturn($company);

		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->with(5)->willReturn($foreign);

		$svc = $this->service($this->createMock(DealMapper::class), $persons);

		try {
			$svc->create([
				'companyId' => 1,
				'title' => 'Bad link',
				'personId' => 5,
			], 'ae');
			$this->fail('foreign person must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('personId', $e->getFields());
		}
	}

	public function testCreateRejectsMissingPerson(): void
	{
		$company = new Company();
		$company->setId(1);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(1)->willReturn($company);

		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$svc = $this->service($this->createMock(DealMapper::class), $persons);

		try {
			$svc->create([
				'companyId' => 1,
				'title' => 'Ghost contact',
				'personId' => 99,
			], 'ae');
			$this->fail('missing person must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('personId', $e->getFields());
		}
	}

	public function testCreateDefaultsLeadSourceManual(): void
	{
		$company = new Company();
		$company->setId(3);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(3)->willReturn($company);

		$inserted = null;
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (Deal $d) use (&$inserted): Deal {
			$d->setId(1);
			$inserted = $d;
			return $d;
		});

		$svc = $this->service($mapper, $this->createMock(PersonMapper::class));
		$svc->create(['companyId' => 3, 'title' => 'Quick'], 'ae');

		$this->assertSame('manual', $inserted->getLeadSource());
	}

	public function testCreateMapsWinPercentToBucket(): void
	{
		$company = new Company();
		$company->setId(3);
		$inserted = null;
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (Deal $d) use (&$inserted): Deal {
			$d->setId(7);
			$inserted = $d;
			return $d;
		});
		$svc = $this->service($mapper, $this->createMock(PersonMapper::class));
		$svc->create(['companyId' => 3, 'title' => 'Percent', 'winChance' => '75%'], 'ae');
		$this->assertSame('commit', $inserted->getForecastBucket());
	}

	public function testCreateMapsHundredPercentToCertainNotWon(): void
	{
		$inserted = null;
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (Deal $d) use (&$inserted): Deal {
			$d->setId(8);
			$inserted = $d;
			return $d;
		});
		$svc = $this->service($mapper, $this->createMock(PersonMapper::class));
		$svc->create(['companyId' => 3, 'title' => 'Sure', 'forecastBucket' => 100], 'ae');
		$this->assertSame('certain', $inserted->getForecastBucket());
		$this->assertSame('lead', $inserted->getStage());
	}

	public function testCreateRejectsUnknownWinChance(): void
	{
		$svc = $this->service($this->createMock(DealMapper::class), $this->createMock(PersonMapper::class));
		try {
			$svc->create(['companyId' => 3, 'title' => 'Bad', 'forecastBucket' => '33'], 'ae');
			$this->fail('invalid percent must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('forecastBucket', $e->getFields());
		}
	}

	private function service(DealMapper $mapper, PersonMapper $persons): DealService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-18 10:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$sequences = $this->createMock(StageSequenceService::class);
		$sequences->method('fireForStage')->willReturn([]);
		$timeline = $this->createMock(TimelineService::class);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturnCallback(static function (int $id): Company {
			$c = new Company();
			$c->setId($id);
			return $c;
		});

		return new DealService(
			$mapper,
			$companies,
			$this->createMock(WorkspaceService::class),
			$sequences,
			$timeline,
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(AgreementMapper::class),
			$persons,
		);
	}
}
