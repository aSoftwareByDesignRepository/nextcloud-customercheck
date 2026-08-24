<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\DealRotLog;
use OCA\CustomerCheck\Db\DealRotLogMapper;
use OCA\CustomerCheck\Service\DealRottingReminderService;
use OCA\CustomerCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DealRottingReminderServiceTest extends TestCase
{
	private DealMapper&MockObject $deals;
	private DealRotLogMapper&MockObject $ledger;
	private CompanyMapper&MockObject $companies;
	private NotificationService&MockObject $notifications;
	private IConfig&MockObject $config;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void
	{
		$this->deals = $this->createMock(DealMapper::class);
		$this->ledger = $this->createMock(DealRotLogMapper::class);
		$this->companies = $this->createMock(CompanyMapper::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-08-24T05:00:00+00:00'));
		$this->config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, ?string $default = null): string {
			return match ($key) {
				Constants::CONFIG_STALE_DEAL_DAYS => '14',
				Constants::CONFIG_REMINDER_HOUR => '5',
				default => (string)($default ?? ''),
			};
		});
	}

	private function service(): DealRottingReminderService
	{
		return new DealRottingReminderService(
			$this->deals,
			$this->ledger,
			$this->companies,
			$this->notifications,
			$this->config,
			$this->time,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testShouldRunNowHonoursReminderHour(): void
	{
		self::assertTrue($this->service()->shouldRunNow());
	}

	public function testRunSkipsUnownedDeals(): void
	{
		$deal = new Deal();
		$deal->setId(9);
		$deal->setCompanyId(3);
		$deal->setTitle('Big deal');
		$deal->setStage('quoted');
		$deal->setStageChangedAt(new \DateTime('2026-08-10'));
		$deal->setOwnerUserId(null);

		$this->deals->method('findOpenStaleBefore')->willReturn([$deal]);
		$this->ledger->expects($this->never())->method('insert');
		$this->notifications->expects($this->never())->method('notifyUser');

		$result = $this->service()->run();
		self::assertSame(1, $result['scanned']);
		self::assertSame(0, $result['sent']);
		self::assertSame(1, $result['skipped']);
	}

	public function testRunNotifiesOwnerOncePerSnap(): void
	{
		$deal = new Deal();
		$deal->setId(9);
		$deal->setCompanyId(3);
		$deal->setTitle('Big deal');
		$deal->setStage('quoted');
		$deal->setStageChangedAt(new \DateTime('2026-08-10'));
		$deal->setOwnerUserId('lara');

		$company = new Company();
		$company->setId(3);
		$company->setName('Acme');
		$company->setWorkspaceId(1);

		$this->deals->method('findOpenStaleBefore')->willReturn([$deal]);
		$this->ledger->method('exists')->with(9, '2026-08-10#1')->willReturn(false);
		$this->ledger->expects($this->once())->method('insert')->with($this->isInstanceOf(DealRotLog::class));
		$this->companies->method('findById')->with(3)->willReturn($company);
		$this->notifications->expects($this->once())->method('notifyUser')->with(
			'lara',
			Constants::NOTIFY_DEAL_ROTTING,
			'deal',
			'9',
			$this->callback(static function (array $p): bool {
				return ($p['stageIdleDays'] ?? 0) === 14
					&& ($p['title'] ?? '') === 'Big deal'
					&& (int)($p['workspaceId'] ?? 0) === 1;
			}),
		)->willReturn(true);

		$result = $this->service()->run();
		self::assertSame(['scanned' => 1, 'sent' => 1, 'skipped' => 0], $result);
	}

	public function testRunSkipsWhenLedgerExists(): void
	{
		$deal = new Deal();
		$deal->setId(9);
		$deal->setCompanyId(3);
		$deal->setTitle('Big deal');
		$deal->setStage('quoted');
		$deal->setStageChangedAt(new \DateTime('2026-08-10'));
		$deal->setOwnerUserId('lara');

		$this->deals->method('findOpenStaleBefore')->willReturn([$deal]);
		$this->ledger->method('exists')->willReturn(true);
		$this->ledger->expects($this->never())->method('insert');
		$this->notifications->expects($this->never())->method('notifyUser');

		$result = $this->service()->run();
		self::assertSame(1, $result['skipped']);
	}

	public function testRunReleasesLedgerWhenNotifyFails(): void
	{
		$deal = new Deal();
		$deal->setId(9);
		$deal->setCompanyId(3);
		$deal->setTitle('Big deal');
		$deal->setStage('quoted');
		$deal->setStageChangedAt(new \DateTime('2026-08-10'));
		$deal->setOwnerUserId('lara');

		$company = new Company();
		$company->setId(3);
		$company->setName('Acme');
		$company->setWorkspaceId(1);

		$this->deals->method('findOpenStaleBefore')->willReturn([$deal]);
		$this->ledger->method('exists')->willReturn(false);
		$this->ledger->expects($this->once())->method('insert')->with($this->isInstanceOf(DealRotLog::class));
		$this->ledger->expects($this->once())->method('delete')->with($this->isInstanceOf(DealRotLog::class));
		$this->companies->method('findById')->willReturn($company);
		$this->notifications->method('notifyUser')->willReturn(false);

		$result = $this->service()->run();
		self::assertSame(0, $result['sent']);
		self::assertSame(1, $result['skipped']);
	}
}
