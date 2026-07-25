<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Tests\Unit\Service;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\ReminderLogMapper;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\ReminderScheduler;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReminderSchedulerTest extends TestCase
{
	private ReminderScheduler $scheduler;

	protected function setUp(): void
	{
		$this->scheduler = new ReminderScheduler(
			$this->createMock(AgreementMapper::class),
			$this->createMock(ReminderLogMapper::class),
			$this->createMock(CompanyMapper::class),
			$this->createMock(AgreementStatusService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(ActivityService::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testDueOffsetsAt61Empty(): void
	{
		$this->assertSame([], $this->scheduler->dueOffsets(61));
	}

	public function testDueOffsetsAt60Includes60(): void
	{
		$this->assertSame([60], $this->scheduler->dueOffsets(60));
	}

	public function testDueOffsetsAtMinus7IncludesAllCadence(): void
	{
		$this->assertSame([60, 30, 7, 0, -7], $this->scheduler->dueOffsets(-7));
	}

	public function testSelectMostUrgentPicksSmallest(): void
	{
		$this->assertSame(-7, $this->scheduler->selectMostUrgent([60, 30, -7]));
		$this->assertNull($this->scheduler->selectMostUrgent([]));
	}

	public function testShouldRunNowRespectsReminderHour(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			if ($key === 'reminder_hour') {
				return '5';
			}
			return $default;
		});
		$time = $this->createMock(ITimeFactory::class);
		$scheduler = new ReminderScheduler(
			$this->createMock(AgreementMapper::class),
			$this->createMock(ReminderLogMapper::class),
			$this->createMock(CompanyMapper::class),
			$this->createMock(AgreementStatusService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(ActivityService::class),
			$config,
			$time,
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertTrue($scheduler->shouldRunNow(new \DateTimeImmutable('2026-07-25 05:15:00')));
		$this->assertFalse($scheduler->shouldRunNow(new \DateTimeImmutable('2026-07-25 06:00:00')));
	}
}
