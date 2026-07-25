<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\DigestLogMapper;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WeeklyDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class WeeklyDigestServiceTest extends TestCase
{
	private function make(
		bool $enabled = true,
		int $weekday = 1,
		int $hour = 7,
		array $todayItems = [],
		bool $claimSucceeds = true,
		int $unpaid = 0,
		int $quota80 = 0,
	): WeeklyDigestService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') use ($enabled, $weekday, $hour) {
				return match ($key) {
					Constants::CONFIG_DIGEST_ENABLED => $enabled ? '1' : '0',
					Constants::CONFIG_DIGEST_WEEKDAY => (string)$weekday,
					Constants::CONFIG_DIGEST_HOUR => (string)$hour,
					default => $default,
				};
			}
		);
		$today = $this->createMock(TodayService::class);
		$today->method('build')->willReturn(['items' => $todayItems]);

		$money = $this->createMock(MoneyService::class);
		$money->method('overview')->willReturn([
			'items' => array_fill(0, $unpaid, ['invoiceStatus' => 'sent']),
			'meta' => ['total' => $unpaid],
		]);

		$quota = $this->createMock(QuotaService::class);
		$quotaRows = [];
		for ($i = 0; $i < $quota80; $i++) {
			$quotaRows[] = ['alert' => true, 'percent' => 85.0];
		}
		$quota->method('partnerQuotaOverview')->willReturn($quotaRows);

		$digest = $this->createMock(DigestLogMapper::class);
		if ($claimSucceeds) {
			$digest->method('insert')->willReturnArgument(0);
		} else {
			$ex = $this->createMock(DbException::class);
			$ex->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
			$digest->method('insert')->willThrowException($ex);
		}

		$notifications = $this->createMock(NotificationService::class);
		$time = $this->createMock(ITimeFactory::class);
		$l10n = $this->createMock(IL10N::class);

		return new WeeklyDigestService(
			$digest,
			$today,
			$money,
			$quota,
			$notifications,
			$config,
			$time,
			$l10n,
		);
	}

	public function testShouldRunOnlyOnConfiguredWeekdayAndHour(): void
	{
		$s = $this->make(true, 1, 7);
		$mon7 = new \DateTimeImmutable('2026-07-20 07:15:00'); // Monday
		$mon8 = new \DateTimeImmutable('2026-07-20 08:00:00');
		$tue7 = new \DateTimeImmutable('2026-07-21 07:00:00');
		$this->assertTrue($s->shouldRunNow($mon7));
		$this->assertFalse($s->shouldRunNow($mon8));
		$this->assertFalse($s->shouldRunNow($tue7));
	}

	public function testDisabledNeverRuns(): void
	{
		$s = $this->make(false, 1, 7);
		$this->assertFalse($s->shouldRunNow(new \DateTimeImmutable('2026-07-20 07:00:00')));
	}

	public function testEmptyCountsSkipsSend(): void
	{
		$s = $this->make(true, 1, 7, []);
		$result = $s->run(new \DateTimeImmutable('2026-07-20 07:00:00'));
		$this->assertFalse($result['sent']);
		$this->assertSame(0, array_sum($result['counts']));
	}

	public function testUniqueClaimPreventsDoubleSend(): void
	{
		$items = [['kind' => 'agreement_expiry', 'daysUntil' => 7]];
		$s = $this->make(true, 1, 7, $items, false);
		$result = $s->run(new \DateTimeImmutable('2026-07-20 07:00:00'));
		$this->assertFalse($result['sent']);
		$this->assertGreaterThan(0, $result['counts']['renewals']);
	}

	public function testSuccessfulClaimSends(): void
	{
		$items = [
			['kind' => 'agreement_expiry', 'daysUntil' => 14],
			['kind' => 'activity_overdue'],
		];
		$s = $this->make(true, 1, 7, $items, true, 2, 1);
		$result = $s->run(new \DateTimeImmutable('2026-07-20 07:00:00'));
		$this->assertTrue($result['sent']);
		$this->assertSame(1, $result['counts']['renewals']);
		$this->assertSame(1, $result['counts']['overdue']);
		$this->assertSame(2, $result['counts']['unpaid']);
		$this->assertSame(1, $result['counts']['quota80']);
	}

	public function testCollectCountsIncludesUnpaidAndQuota(): void
	{
		$s = $this->make(true, 1, 7, [], true, 3, 2);
		$counts = $s->collectCounts(new \DateTimeImmutable('2026-07-20'));
		$this->assertSame(3, $counts['unpaid']);
		$this->assertSame(2, $counts['quota80']);
	}
}
