<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityDueLog;
use OCA\CustomerCheck\Db\ActivityDueLogMapper;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Service\ActivityDueReminderService;
use OCA\CustomerCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ActivityDueReminderServiceTest extends TestCase
{
	private ActivityMapper&MockObject $activities;
	private ActivityDueLogMapper&MockObject $ledger;
	private CompanyMapper&MockObject $companies;
	private NotificationService&MockObject $notifications;
	private IConfig&MockObject $config;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void
	{
		$this->activities = $this->createMock(ActivityMapper::class);
		$this->ledger = $this->createMock(ActivityDueLogMapper::class);
		$this->companies = $this->createMock(CompanyMapper::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-07-26T05:00:00+00:00'));
	}

	private function service(): ActivityDueReminderService
	{
		return new ActivityDueReminderService(
			$this->activities,
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
		$this->config->method('getAppValue')->willReturn('5');
		self::assertTrue($this->service()->shouldRunNow());
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturn('7');
		self::assertFalse($this->service()->shouldRunNow());
	}

	public function testRunSkipsAlreadyLedgedActivities(): void
	{
		$activity = new Activity();
		$activity->setId(12);
		$activity->setCompanyId(3);
		$activity->setTitle('Call back');
		$activity->setDueDate(new \DateTime('2026-07-26'));

		$this->activities->method('findOpenDueUpTo')->willReturn([$activity]);
		$this->ledger->expects($this->once())->method('exists')->with(12, '2026-07-26')->willReturn(true);
		$this->ledger->expects($this->never())->method('insert');
		$this->notifications->expects($this->never())->method('notifyCrmUsers');

		$result = $this->service()->run();
		self::assertSame(['scanned' => 1, 'sent' => 0, 'skipped' => 1], $result);
	}

	public function testRunSkipsWithoutClaimWhenWorkspaceMissing(): void
	{
		$activity = new Activity();
		$activity->setId(12);
		$activity->setCompanyId(3);
		$activity->setTitle('Call back');
		$activity->setDueDate(new \DateTime('2026-07-26'));

		$company = new Company();
		$company->setId(3);
		$company->setName('Orphan Co');
		$company->setWorkspaceId(0);

		$this->activities->method('findOpenDueUpTo')->with(
			$this->anything(),
			200,
			null,
			true,
		)->willReturn([$activity]);
		$this->ledger->method('exists')->willReturn(false);
		$this->ledger->expects($this->never())->method('insert');
		$this->companies->method('findById')->with(3)->willReturn($company);
		$this->notifications->expects($this->never())->method('notifyWorkspaceUsers');
		$this->notifications->expects($this->never())->method('notifyCrmUsers');

		$result = $this->service()->run();
		self::assertSame(0, $result['sent']);
		self::assertSame(1, $result['skipped']);
	}

	public function testRunReleasesLedgerWhenNoRecipients(): void
	{
		$activity = new Activity();
		$activity->setId(12);
		$activity->setCompanyId(3);
		$activity->setTitle('Call back');
		$activity->setDueDate(new \DateTime('2026-07-25'));

		$company = new Company();
		$company->setId(3);
		$company->setName('Müller GmbH');
		$company->setWorkspaceId(9);

		$this->activities->method('findOpenDueUpTo')->willReturn([$activity]);
		$this->ledger->method('exists')->willReturn(false);
		$this->ledger->expects($this->once())->method('insert')->with($this->isInstanceOf(ActivityDueLog::class));
		$this->ledger->expects($this->once())->method('delete')->with($this->isInstanceOf(ActivityDueLog::class));
		$this->companies->method('findById')->with(3)->willReturn($company);
		$this->notifications->expects($this->once())->method('notifyWorkspaceUsers')->willReturn(0);
		$this->notifications->expects($this->never())->method('notifyCrmUsers');

		$result = $this->service()->run();
		self::assertSame(0, $result['sent']);
		self::assertSame(1, $result['skipped']);
	}

	public function testRunInsertsLedgerThenNotifies(): void
	{
		$activity = new Activity();
		$activity->setId(12);
		$activity->setCompanyId(3);
		$activity->setTitle('Call back');
		$activity->setDueDate(new \DateTime('2026-07-25'));

		$company = new Company();
		$company->setId(3);
		$company->setName('Müller GmbH');
		$company->setWorkspaceId(9);

		$this->activities->method('findOpenDueUpTo')->willReturn([$activity]);
		$this->ledger->method('exists')->willReturn(false);
		$this->ledger->expects($this->once())->method('insert')->with($this->isInstanceOf(ActivityDueLog::class));
		$this->companies->method('findById')->with(3)->willReturn($company);
		$this->notifications->expects($this->once())->method('notifyWorkspaceUsers')->with(
			9,
			Constants::NOTIFY_ACTIVITY_DUE,
			'activity',
			'12',
			$this->callback(static function (array $p): bool {
				return ($p['daysLate'] ?? 0) === 1
					&& ($p['companyName'] ?? '') === 'Müller GmbH'
					&& ($p['title'] ?? '') === 'Call back';
			}),
		)->willReturn(1);
		$this->notifications->expects($this->never())->method('notifyCrmUsers');

		$result = $this->service()->run();
		self::assertSame(1, $result['sent']);
		self::assertSame(0, $result['skipped']);
	}

	public function testLedgerExistsGuardIsRequiredForIdempotency(): void
	{
		// Mutation target: disabling exists() must cause a second insert path.
		$activity = new Activity();
		$activity->setId(9);
		$activity->setCompanyId(1);
		$activity->setTitle('Todo');
		$activity->setDueDate(new \DateTime('2026-07-26'));

		$this->activities->method('findOpenDueUpTo')->willReturn([$activity]);
		$this->ledger->expects($this->atLeastOnce())->method('exists')->willReturn(true);
		$this->ledger->expects($this->never())->method('insert');

		$this->service()->run();
	}
}
