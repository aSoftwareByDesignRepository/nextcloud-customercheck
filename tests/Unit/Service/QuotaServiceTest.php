<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Tests\Unit\Service;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\ReminderLogMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class QuotaServiceTest extends TestCase
{
	private QuotaService $quota;

	protected function setUp(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn ($s) => $s);
		$this->quota = new QuotaService(
			$this->createMock(HoursMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(ReminderLogMapper::class),
			$this->createMock(NotificationService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$l,
		);
	}

	public function testQuarterHourRejects20(): void
	{
		$this->expectException(ValidationException::class);
		$this->quota->assertQuarterHour(20);
	}

	public function testQuarterHourAllows15AndNegative(): void
	{
		$this->quota->assertQuarterHour(15);
		$this->quota->assertQuarterHour(-30);
		$this->addToAssertionCount(1);
	}

	public function testAlertThresholdBoundaries(): void
	{
		// 10h = 600 min; 79.9% = 479.4 → 479 min should not alert; 480 = 80%
		$this->assertFalse($this->quota->hasReachedAlertThreshold(479, 600));
		$this->assertTrue($this->quota->hasReachedAlertThreshold(480, 600));
		$this->assertTrue($this->quota->hasReachedAlertThreshold(481, 600));
	}

	public function testWrongKindRejected(): void
	{
		$a = new Agreement();
		$a->setKind('license');
		$this->expectException(ValidationException::class);
		$this->quota->assertPartnerAgreement($a);
	}

	public function testUsedPercent(): void
	{
		$this->assertSame(0.0, $this->quota->usedPercent(10, 0));
		$this->assertEqualsWithDelta(80.0, $this->quota->usedPercent(480, 600), 0.01);
	}

	public function testHoursLockedAfterSevenDays(): void
	{
		$entry = new \OCA\CustomerCheck\Db\HoursEntry();
		$entry->setCreatedAt(new \DateTime('2026-07-01 12:00:00'));
		$this->expectException(ValidationException::class);
		try {
			$this->quota->assertEntryEditable($entry, new \DateTime('2026-07-10 12:00:00'));
		} catch (ValidationException $e) {
			$this->assertSame('HOURS_LOCKED', $e->getErrorCode());
			throw $e;
		}
	}

	public function testHoursEditableWithinSevenDays(): void
	{
		$entry = new \OCA\CustomerCheck\Db\HoursEntry();
		$entry->setCreatedAt(new \DateTime('2026-07-08 12:00:00'));
		$this->quota->assertEntryEditable($entry, new \DateTime('2026-07-10 12:00:00'));
		$this->addToAssertionCount(1);
	}
}
