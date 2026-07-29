<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\CoachingService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\OooCoverService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Team coaching board — empty membership heal, labels, summary (product §13).
 */
final class CoachingBoardTest extends TestCase
{
	public function testViewerRoleRejected(): void
	{
		$svc = $this->service();
		$this->expectException(ValidationException::class);
		$svc->board(1, 'viewer-user', 'viewer', false);
	}

	public function testAppAdminWithoutMembershipIsHealedAsManager(): void
	{
		$ws = $this->createMock(WorkspaceService::class);
		$healed = $this->member('admin', 'manager');
		$calls = 0;
		$ws->method('listMembers')->willReturnCallback(static function () use (&$calls, $healed): array {
			$calls++;
			return $calls === 1 ? [] : [$healed];
		});
		$ws->expects($this->once())
			->method('addMember')
			->with(1, 'admin', 'manager', true, 'admin')
			->willReturn($healed);

		$svc = $this->service($ws, $this->userManagerWith('admin', 'Admin User'));
		$board = $svc->board(1, 'admin', 'manager', true);

		$this->assertFalse($board['selfOnly']);
		$this->assertCount(1, $board['rows']);
		$this->assertSame('admin', $board['rows'][0]['userId']);
		$this->assertSame('Admin User', $board['rows'][0]['displayName']);
		$this->assertSame('Manager', $board['rows'][0]['roleLabel']);
		$this->assertSame(1, $board['summary']['people']);
		$this->assertSame(0, $board['summary']['stuck']);
		$this->assertArrayHasKey('forecastDisclaimer', $board['rows'][0]);
	}

	public function testNonAdminMissingMembershipIsNotAutoAdded(): void
	{
		$ws = $this->createMock(WorkspaceService::class);
		$other = $this->member('sam', 'member');
		$ws->method('listMembers')->willReturn([$other]);
		$ws->expects($this->never())->method('addMember');

		$svc = $this->service($ws, $this->userManagerWith('sam', 'Sam'));
		$board = $svc->board(1, 'alex', 'member', false);

		$this->assertTrue($board['selfOnly']);
		$this->assertSame([], $board['rows']);
		$this->assertSame(0, $board['summary']['people']);
	}

	public function testMemberSeesOnlySelfRowWithDisplayName(): void
	{
		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('listMembers')->willReturn([
			$this->member('alex', 'member'),
			$this->member('sam', 'manager'),
		]);
		$ws->expects($this->never())->method('addMember');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(function (string $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getDisplayName')->willReturn($uid === 'alex' ? 'Alex Coach' : 'Sam Boss');
			return $user;
		});

		$svc = $this->service($ws, $userManager);
		$board = $svc->board(1, 'alex', 'member', false);

		$this->assertTrue($board['selfOnly']);
		$this->assertCount(1, $board['rows']);
		$this->assertSame('alex', $board['rows'][0]['userId']);
		$this->assertSame('Alex Coach', $board['rows'][0]['displayName']);
		$this->assertSame('Member', $board['rows'][0]['roleLabel']);
		$this->assertSame([], $board['escalations']);
	}

	public function testManagerSeesAllRowsAndStuckSummary(): void
	{
		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('listMembers')->willReturn([
			$this->member('alex', 'member'),
			$this->member('sam', 'manager'),
		]);

		$chase = $this->createMock(ChaseService::class);
		$chase->method('myChase')->willReturnCallback(static function (string $uid): array {
			if ($uid === 'alex') {
				return [
					['quoteAgeDays' => 20, 'forecastBucket' => 'commit', 'valueCents' => 50000],
					['quoteAgeDays' => 2, 'forecastBucket' => 'upside', 'valueCents' => 1000],
				];
			}
			return [];
		});

		$promises = $this->createMock(PromiseService::class);
		$promises->method('dueSoon')->willReturn([
			['ownerUserId' => 'alex'],
			['ownerUserId' => 'alex'],
			['ownerUserId' => 'sam'],
		]);

		$coverage = $this->createMock(CoverageService::class);
		$coverage->method('myAccounts')->willReturnCallback(static function (string $uid): array {
			return $uid === 'alex' ? [['gap' => true], ['gap' => false]] : [];
		});

		$plans = $this->createMock(AccountPlanService::class);
		$plans->method('listForWorkspace')->willReturn([
			['ownerUserId' => 'alex', 'health' => 'stalled'],
		]);

		$escalations = $this->createMock(EscalationService::class);
		$escalations->method('listOpen')->willReturn([
			['subjectUserId' => 'alex', 'reason' => 'Need help'],
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		$ooo = $this->createMock(OooCoverService::class);
		$ooo->method('listActive')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		$svc = new CoachingService(
			$db,
			$ws,
			$chase,
			$promises,
			$coverage,
			$escalations,
			$plans,
			$ooo,
			$time,
			$l10n,
			$userManager,
		);

		$board = $svc->board(1, 'sam', 'manager', false);
		$this->assertFalse($board['selfOnly']);
		$this->assertCount(2, $board['rows']);
		$this->assertSame(2, $board['summary']['people']);
		$this->assertGreaterThanOrEqual(1, $board['summary']['stuck']);
		$alex = null;
		foreach ($board['rows'] as $row) {
			if ($row['userId'] === 'alex') {
				$alex = $row;
			}
		}
		$this->assertNotNull($alex);
		$this->assertTrue($alex['stuck']);
		$this->assertSame(2, $alex['chase']);
		$this->assertSame(1, $alex['quote14']);
		$this->assertSame(1, $alex['coverageGaps']);
		$this->assertSame(2, $alex['overdueWork']);
		$this->assertSame(50000, $alex['commitCents']);
		$this->assertCount(1, $board['escalations']);
	}

	private function member(string $userId, string $role): WorkspaceMember
	{
		$m = new WorkspaceMember();
		$m->setUserId($userId);
		$m->setRole($role);
		$m->setWorkspaceId(1);
		return $m;
	}

	private function userManagerWith(string $uid, string $displayName): IUserManager
	{
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturnCallback(static function (string $id) use ($uid, $user) {
			return $id === $uid ? $user : null;
		});
		return $um;
	}

	private function service(?WorkspaceService $ws = null, ?IUserManager $um = null): CoachingService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));

		$chase = $this->createMock(ChaseService::class);
		$chase->method('myChase')->willReturn([]);
		$promises = $this->createMock(PromiseService::class);
		$promises->method('dueSoon')->willReturn([]);
		$coverage = $this->createMock(CoverageService::class);
		$coverage->method('myAccounts')->willReturn([]);
		$plans = $this->createMock(AccountPlanService::class);
		$plans->method('listForWorkspace')->willReturn([]);
		$escalations = $this->createMock(EscalationService::class);
		$escalations->method('listOpen')->willReturn([]);
		$ooo = $this->createMock(OooCoverService::class);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$ooo->method('listActive')->willReturn([]);

		return new CoachingService(
			$db,
			$ws ?? $this->createMock(WorkspaceService::class),
			$chase,
			$promises,
			$coverage,
			$escalations,
			$plans,
			$ooo,
			$time,
			$l10n,
			$um ?? $this->createMock(IUserManager::class),
		);
	}
}
