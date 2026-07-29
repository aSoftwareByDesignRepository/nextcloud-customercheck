<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\WebsiteOrderWebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class WebsiteOrderWebhookServiceTest extends TestCase
{
	/** @var list<Activity> */
	private array $activities = [];
	/** @var list<array<string, mixed>> */
	private array $agreements = [];
	/** @var list<Deal> */
	private array $openDeals = [];

	private function service(bool $tokenOk = true, bool $rateOk = true): WebsiteOrderWebhookService
	{
		$this->activities = [];
		$this->agreements = [];
		$this->openDeals = [];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('tokenMatches')->willReturn($tokenOk);
		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn($rateOk);
		$company = new Company();
		$company->setId(42);
		$company->setSlug('order-co');
		$company->setName('Order Co');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('create')->willReturn($company);
		$companies->method('get')->willReturn($company);
		$slug = $this->createMock(SlugService::class);
		$slug->method('suggestUnique')->willReturn('order-co');
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findByEmail')->willReturn([]);
		$deals = $this->createMock(DealMapper::class);
		$deals->method('findOpenByTitle')->willReturnCallback(fn () => $this->openDeals);
		$deals->method('insert')->willReturnCallback(function (Deal $deal): Deal {
			$deal->setId(55);
			$this->openDeals[] = $deal;
			return $deal;
		});
		$agreementService = $this->createMock(AgreementService::class);
		$agreementService->method('create')->willReturnCallback(function (array $input) {
			$this->agreements[] = $input;
			return $this->createMock(\OCA\CustomerCheck\Db\Agreement::class);
		});
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('insert')->willReturnCallback(function (Activity $activity): Activity {
			$this->activities[] = $activity;
			return $activity;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-20 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new WebsiteOrderWebhookService(
			$settings,
			$rate,
			$companies,
			$slug,
			$this->createMock(PersonService::class),
			$persons,
			$deals,
			$agreementService,
			$activities,
			$this->createMock(TimelineService::class),
			$this->createMock(NotificationService::class),
			$time,
			$l10n,
			$this->createMock(ILockingProvider::class),
		);
	}

	/** @return array<string, mixed> */
	private function payload(): array
	{
		return [
			'orderId' => 'abc',
			'source' => 'website-order',
			'lang' => 'en',
			'companyName' => 'Order Co',
			'contactName' => 'Ada',
			'email' => 'ada@example.com',
			'startsAt' => '2026-08-01',
			'validUntil' => '2027-07-31',
			'totalCents' => 4500,
			'agreements' => [[
				'productCode' => 'azc',
				'label' => 'AZC 5 seats',
				'seats' => 5,
				'devices' => 0,
				'amountCents' => 4500,
				'startsAt' => '2026-08-01',
				'validUntil' => '2027-07-31',
			]],
		];
	}

	public function testInvalidTokenRejected(): void
	{
		$this->expectException(ValidationException::class);
		$this->service(false)->handle('bad', '1.2.3.4', $this->payload());
	}

	public function testRateLimitRejected(): void
	{
		$this->expectException(ValidationException::class);
		$this->service(true, false)->handle('token', '1.2.3.4', $this->payload());
	}

	public function testInvalidPayloadRejected(): void
	{
		$payload = $this->payload();
		unset($payload['agreements']);
		$this->expectException(ValidationException::class);
		$this->service()->handle('token', '1.2.3.4', $payload);
	}

	public function testHappyPathCreatesAgreementsAndTwoTodos(): void
	{
		$result = $this->service()->handle('token', '1.2.3.4', $this->payload());

		$this->assertSame(['companyId' => 42, 'dealId' => 55, 'slug' => 'order-co'], $result);
		$this->assertCount(1, $this->agreements);
		$this->assertSame('draft', $this->agreements[0]['status']);
		$this->assertSame('license', $this->agreements[0]['kind']);
		$this->assertSame(['mobileSeats' => 5, 'terminalDevices' => 0, 'bundle' => false], $this->agreements[0]['limits']);
		$this->assertCount(2, $this->activities);
		$this->assertSame(['Issue licence key(s)', 'Write invoice'], array_map(static fn (Activity $a) => $a->getTitle(), $this->activities));
		$this->assertSame(['todo', 'todo'], array_map(static fn (Activity $a) => $a->getType(), $this->activities));
	}

	public function testRepostReturnsExistingDealWithoutCreatingDuplicates(): void
	{
		$service = $this->service();
		$first = $service->handle('token', '1.2.3.4', $this->payload());
		$second = $service->handle('token', '1.2.3.4', $this->payload());

		$this->assertSame($first, $second);
		$this->assertCount(1, $this->agreements);
		$this->assertCount(2, $this->activities);
	}

	public function testPcAndCucOrdersCreateSeparateLicenseAgreements(): void
	{
		$payload = $this->payload();
		$payload['agreements'] = [
			['productCode' => 'pc', 'label' => 'PC', 'seats' => 1, 'devices' => 0, 'amountCents' => 2000, 'startsAt' => '2026-08-01', 'validUntil' => '2027-07-31'],
			['productCode' => 'cuc', 'label' => 'CUC', 'seats' => 0, 'devices' => 1, 'amountCents' => 2500, 'startsAt' => '2026-08-01', 'validUntil' => '2027-07-31'],
		];

		$this->service()->handle('token', '1.2.3.4', $payload);

		$this->assertSame(['pc', 'cuc'], array_column($this->agreements, 'productCode'));
		$this->assertSame(['license', 'license'], array_column($this->agreements, 'kind'));
	}
}
