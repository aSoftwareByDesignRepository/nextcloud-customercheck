<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\DigestLogMapper;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Service\BriefService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WeeklyDigestService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
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
		array $workspaces = [],
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
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => $s);

		$wsService = $this->createMock(WorkspaceService::class);
		$wsService->method('listAll')->willReturn($workspaces);
		$wsService->method('getSettings')->willReturn(['digestEnabled' => true, 'wipLimit' => 5]);
		$member = new WorkspaceMember();
		$member->setUserId('alex');
		$member->setRole('manager');
		$wsService->method('listMembers')->willReturn($workspaces === [] ? [] : [$member]);

		$brief = $this->createMock(BriefService::class);
		$brief->method('build')->willReturn([
			'wipUsed' => 1,
			'wipLimit' => 5,
			'cash' => ['expectedInThisMonthCents' => 10000],
			'commitments' => [],
			'lastWeek' => [],
		]);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/brief');

		return new WeeklyDigestService(
			$digest,
			$today,
			$money,
			$quota,
			$notifications,
			$wsService,
			$brief,
			$config,
			$time,
			$url,
			$l10n,
		);
	}

	private function sampleWorkspace(): Workspace
	{
		$ws = new Workspace();
		$ws->setId(1);
		$ws->setName('Partner Success');
		$ws->setSlug('partner');
		$ws->setArchivedAt(null);
		return $ws;
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

	public function testEmptyWorkspacesSkipsSend(): void
	{
		$s = $this->make(true, 1, 7, [], true, 0, 0, []);
		$result = $s->run(new \DateTimeImmutable('2026-07-20 07:00:00'));
		$this->assertSame(0, $result['sent']);
		$this->assertSame([], $result['workspaces']);
	}

	public function testUniqueClaimPreventsDoubleSend(): void
	{
		$s = $this->make(true, 1, 7, [], false, 0, 0, [$this->sampleWorkspace()]);
		$result = $s->run(new \DateTimeImmutable('2026-07-20 07:00:00'));
		$this->assertSame(0, $result['sent']);
	}

	public function testSuccessfulClaimSendsPerWorkspace(): void
	{
		$s = $this->make(true, 1, 7, [], true, 2, 1, [$this->sampleWorkspace()]);
		$result = $s->run(new \DateTimeImmutable('2026-07-20 07:00:00'));
		$this->assertSame(1, $result['sent']);
		$this->assertSame([1], $result['workspaces']);
	}

	public function testCollectCountsIncludesUnpaidAndQuota(): void
	{
		$s = $this->make(true, 1, 7, [], true, 3, 2);
		$counts = $s->collectCounts(new \DateTimeImmutable('2026-07-20'), 1);
		$this->assertSame(3, $counts['unpaid']);
		$this->assertSame(2, $counts['quota80']);
	}

	public function testCollectCountsFailsClosedWithoutWorkspaceQuota(): void
	{
		$s = $this->make(true, 1, 7, [], true, 3, 2);
		$counts = $s->collectCounts(new \DateTimeImmutable('2026-07-20'), null);
		$this->assertSame(3, $counts['unpaid']);
		$this->assertSame(0, $counts['quota80']);
	}
}
