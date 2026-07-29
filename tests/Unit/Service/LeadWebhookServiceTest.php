<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\LeadWebhookService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class LeadWebhookServiceTest extends TestCase
{
	/** @var list<Activity> */
	private array $insertedActivities = [];

	private function service(
		bool $tokenOk = true,
		bool $rateOk = true,
		?Company $created = null,
	): LeadWebhookService {
		$this->insertedActivities = [];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('tokenMatches')->willReturn($tokenOk);
		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn($rateOk);
		$companies = $this->createMock(CompanyService::class);
		$slug = $this->createMock(SlugService::class);
		$slug->method('suggestUnique')->willReturn('lead-co');
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findByEmail')->willReturn([]);
		$personService = $this->createMock(PersonService::class);
		$personService->method('create')->willReturnCallback(static function (int $companyId, array $input) {
			$p = new Person();
			$p->setId(9);
			$p->setCompanyId($companyId);
			$p->setName((string)($input['name'] ?? ''));
			$p->setEmail($input['email'] ?? null);
			$p->setPhone($input['phone'] ?? null);
			$p->setIsPrimary(!empty($input['isPrimary']) ? 1 : 0);
			return $p;
		});
		$deals = $this->createMock(DealMapper::class);
		$deals->method('findOpenByCompany')->willReturn([]);
		$deals->method('insert')->willReturnCallback(static function ($d) {
			$d->setId(55);
			return $d;
		});
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('insert')->willReturnCallback(function ($a) {
			$a->setId(77);
			$this->insertedActivities[] = $a;
			return $a;
		});
		$timeline = $this->createMock(TimelineService::class);
		$notifications = $this->createMock(NotificationService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-20 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$company = $created ?? new Company();
		if ($created === null) {
			$company->setId(42);
			$company->setSlug('lead-co');
			$company->setName('Lead Co');
		}
		$companies->method('create')->willReturn($company);
		$companies->method('get')->willReturn($company);

		return new LeadWebhookService(
			$settings,
			$rate,
			$companies,
			$slug,
			$personService,
			$persons,
			$deals,
			$activities,
			$timeline,
			$notifications,
			$time,
			$l10n,
		);
	}

	public function testInvalidTokenRejected(): void
	{
		$s = $this->service(false, true);
		$this->expectException(ValidationException::class);
		try {
			$s->handle('bad', '1.2.3.4', ['companyName' => 'Acme', 'email' => 'a@b.co']);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_HOOK_TOKEN_INVALID, $e->getErrorCode());
			throw $e;
		}
	}

	public function testRateLimitRejected(): void
	{
		$s = $this->service(true, false);
		$this->expectException(ValidationException::class);
		try {
			$s->handle('tok', '1.2.3.4', ['companyName' => 'Acme', 'email' => 'a@b.co']);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_HOOK_RATE_LIMITED, $e->getErrorCode());
			throw $e;
		}
	}

	public function testRequiresEmailOrPhone(): void
	{
		$s = $this->service();
		$this->expectException(ValidationException::class);
		try {
			$s->handle('tok', '1.2.3.4', ['companyName' => 'Acme']);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_HOOK_VALIDATION, $e->getErrorCode());
			throw $e;
		}
	}

	public function testRequiresCompanyNameMinLength(): void
	{
		$s = $this->service();
		$this->expectException(ValidationException::class);
		try {
			$s->handle('tok', '1.2.3.4', ['companyName' => 'A', 'email' => 'a@b.co']);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_HOOK_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('companyName', $e->getFields());
			throw $e;
		}
	}

	public function testHappyPathCreatesCompanyDealActivity(): void
	{
		$s = $this->service();
		$result = $s->handle('tok', '1.2.3.4', [
			'companyName' => 'Lead Co',
			'email' => 'lead@example.com',
			'contactName' => 'Sam',
			'message' => 'Need a quote',
		]);
		$this->assertSame(42, $result['companyId']);
		$this->assertSame(55, $result['dealId']);
		$this->assertSame('lead-co', $result['slug']);
		$this->assertCount(1, $this->insertedActivities);
		$this->assertSame('email', (string)$this->insertedActivities[0]->getType());
	}

	public function testPhoneOnlyCreatesCallActivity(): void
	{
		$s = $this->service();
		$s->handle('tok', '1.2.3.4', [
			'companyName' => 'Phone Co',
			'phone' => '+49123456789',
			'contactName' => 'Pat',
		]);
		$this->assertCount(1, $this->insertedActivities);
		$this->assertSame('call', (string)$this->insertedActivities[0]->getType());
	}

	public function testArchivedEmailMatchCreatesFreshCompany(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('tokenMatches')->willReturn(true);
		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);

		$archived = new Company();
		$archived->setId(11);
		$archived->setSlug('old-co');
		$archived->setName('Old Co');
		$archived->setArchivedAt(new \DateTime('2026-01-01'));

		$fresh = new Company();
		$fresh->setId(99);
		$fresh->setSlug('fresh-co');
		$fresh->setName('Fresh Co');

		$person = new Person();
		$person->setId(3);
		$person->setCompanyId(11);
		$person->setEmail('lead@example.com');

		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findByEmail')->willReturn([$person]);

		$companies = $this->createMock(CompanyService::class);
		$companies->expects($this->once())->method('get')->with(11)->willReturn($archived);
		$companies->expects($this->once())->method('create')->willReturn($fresh);

		$slug = $this->createMock(SlugService::class);
		$slug->method('suggestUnique')->willReturn('fresh-co');
		$personService = $this->createMock(PersonService::class);
		$personService->method('create')->willReturn(new Person());
		$deals = $this->createMock(DealMapper::class);
		$deals->method('findOpenByCompany')->willReturn([]);
		$deals->method('insert')->willReturnCallback(static function ($d) {
			$d->setId(1);
			return $d;
		});
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('insert')->willReturnCallback(static function ($a) {
			$a->setId(2);
			return $a;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-20 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$svc = new LeadWebhookService(
			$settings,
			$rate,
			$companies,
			$slug,
			$personService,
			$persons,
			$deals,
			$activities,
			$this->createMock(TimelineService::class),
			$this->createMock(NotificationService::class),
			$time,
			$l10n,
		);

		$result = $svc->handle('tok', '1.2.3.4', [
			'companyName' => 'Fresh Co',
			'email' => 'lead@example.com',
		]);
		$this->assertSame(99, $result['companyId']);
		$this->assertSame('fresh-co', $result['slug']);
	}
}
