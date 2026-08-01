<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Shared-mailbox sync edges without live IMAP — lock race, cap, connection gate.
 */
final class MailboxSyncServiceTest extends TestCase
{
	/** @var IDBConnection&MockObject */
	private IDBConnection $db;
	/** @var ICrypto&MockObject */
	private ICrypto $crypto;
	/** @var MailboxService&MockObject */
	private MailboxService $mailboxes;
	/** @var WorkspaceService&MockObject */
	private WorkspaceService $workspaces;
	/** @var NotificationService&MockObject */
	private NotificationService $notifications;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locks;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	/** @var IL10N&MockObject */
	private IL10N $l10n;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->mailboxes = $this->createMock(MailboxService::class);
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-07-31 12:00:00'));
	}

	private function service(): MailboxSyncService
	{
		return new MailboxSyncService(
			$this->db,
			$this->crypto,
			$this->mailboxes,
			$this->workspaces,
			$this->notifications,
			$this->locks,
			$this->time,
			$this->logger,
			$this->l10n,
		);
	}

	public function testSyncOneReturnsLockedWhenExclusiveLockFails(): void
	{
		$this->locks->expects($this->once())
			->method('acquireLock')
			->with('crm_mbox_42', ILockingProvider::LOCK_EXCLUSIVE)
			->willThrowException(new \RuntimeException('busy'));
		$this->mailboxes->expects($this->never())->method('ingestMessage');

		$result = $this->service()->syncOne(42, 7);

		$this->assertSame(0, $result['ingested']);
		$this->assertSame('locked', $result['status']);
	}

	public function testSyncAllReturnsZerosWhenMailboxTableMissing(): void
	{
		$this->db->method('tableExists')->with('crm_mailbox')->willReturn(false);
		$this->locks->expects($this->once())
			->method('acquireLock')
			->with('crm_mail_purge_all', ILockingProvider::LOCK_EXCLUSIVE);
		$this->locks->expects($this->once())
			->method('releaseLock')
			->with('crm_mail_purge_all', ILockingProvider::LOCK_EXCLUSIVE);
		$this->workspaces->method('listAll')->willReturn([]);

		$result = $this->service()->syncAll();

		$this->assertSame(['mailboxes' => 0, 'messages' => 0, 'errors' => 0, 'purged' => 0, 'purgeRemaining' => 0], $result);
	}

	public function testSyncAllPurgesExpiredMailAfterSync(): void
	{
		$this->db->method('tableExists')->with('crm_mailbox')->willReturn(false);
		$ws = new \OCA\CustomerCheck\Db\Workspace();
		$ws->setId(3);
		$this->workspaces->method('listAll')->willReturn([$ws]);
		$this->workspaces->method('getSettings')->with(3)->willReturn([
			'wipLimit' => 5,
			'leadSlaHours' => 24,
			'stuckChaseDays' => 3,
			'mailRetentionDays' => 90,
			'digestEnabled' => true,
		]);
		$this->locks->method('acquireLock');
		$this->locks->method('releaseLock');
		$this->mailboxes->expects($this->exactly(2))
			->method('purgeExpired')
			->with(3, 90)
			->willReturnOnConsecutiveCalls(12, 0);
		$this->mailboxes->expects($this->once())
			->method('countExpired')
			->with(3, 90)
			->willReturn(0);

		$result = $this->service()->syncAll();

		$this->assertSame(12, $result['purged']);
		$this->assertSame(0, $result['purgeRemaining']);
	}

	public function testPurgeReturnsZeroWhenLockBusy(): void
	{
		$this->locks->expects($this->once())
			->method('acquireLock')
			->with('crm_mail_purge_all', ILockingProvider::LOCK_EXCLUSIVE)
			->willThrowException(new \RuntimeException('busy'));
		$this->mailboxes->expects($this->never())->method('purgeExpired');

		$this->assertSame(
			['purged' => 0, 'remaining' => -1],
			$this->service()->purgeExpiredAcrossWorkspaces(),
		);
	}

	public function testPurgeReportsRemainingWhenBacklogExceedsBudgetRounds(): void
	{
		$ws = new \OCA\CustomerCheck\Db\Workspace();
		$ws->setId(3);
		$this->workspaces->method('listAll')->willReturn([$ws]);
		$this->workspaces->method('getSettings')->with(3)->willReturn([
			'wipLimit' => 5,
			'leadSlaHours' => 24,
			'stuckChaseDays' => 3,
			'mailRetentionDays' => 90,
			'digestEnabled' => true,
		]);
		$this->locks->method('acquireLock');
		$this->locks->method('releaseLock');
		// Always return a full batch so the round loop keeps going until the ceiling,
		// then countExpired reports leftover rows for the next cron tick.
		$this->mailboxes->method('purgeExpired')->willReturn(Constants::MAIL_PURGE_BATCH);
		$this->mailboxes->method('countExpired')->willReturn(250);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->service()->purgeExpiredAcrossWorkspaces();

		$this->assertSame(Constants::MAIL_PURGE_BATCH * Constants::MAIL_PURGE_MAX_ROUNDS, $result['purged']);
		$this->assertSame(250, $result['remaining']);
	}

	public function testPurgeFairRoundRobinVisitsEveryWorkspaceEachWave(): void
	{
		$wsA = new \OCA\CustomerCheck\Db\Workspace();
		$wsA->setId(1);
		$wsB = new \OCA\CustomerCheck\Db\Workspace();
		$wsB->setId(2);
		$this->workspaces->method('listAll')->willReturn([$wsA, $wsB]);
		$this->workspaces->method('getSettings')->willReturn([
			'wipLimit' => 5,
			'leadSlaHours' => 24,
			'stuckChaseDays' => 3,
			'mailRetentionDays' => 90,
			'digestEnabled' => true,
		]);
		$this->locks->method('acquireLock');
		$this->locks->method('releaseLock');
		$seen = [];
		$this->mailboxes->method('purgeExpired')->willReturnCallback(function (int $wsId) use (&$seen): int {
			$seen[] = $wsId;
			// First wave: both have work; second wave: quiet → stop.
			return count($seen) <= 2 ? 10 : 0;
		});
		$this->mailboxes->method('countExpired')->willReturn(0);

		$result = $this->service()->purgeExpiredAcrossWorkspaces();

		$this->assertSame(20, $result['purged']);
		$this->assertContains(1, $seen);
		$this->assertContains(2, $seen);
		// First two calls are one per workspace (order may rotate).
		$this->assertCount(4, $seen);
	}

	public function testTestConnectionRejectsEmptyCredentials(): void
	{
		$result = $this->service()->testConnection([
			'imapHost' => '',
			'username' => '',
			'password' => '',
		]);

		$this->assertFalse($result['ok']);
		$this->assertNotSame('', $result['message']);
	}

	public function testIngestBatchCapsAtMailboxSyncCap(): void
	{
		$cap = Constants::MAILBOX_SYNC_CAP;
		$messages = [];
		for ($i = 0; $i < $cap + 25; $i++) {
			$messages[] = [
				'uid' => 'uid-' . $i,
				'from' => 'ada@acme.example',
				'subject' => 'S' . $i,
				'snippet' => 'n',
			];
		}

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNotNull')->willReturn('nn');

		$selectQb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$selectResult = $this->createMock(\OCP\DB\IResult::class);
		$selectResult->method('fetch')->willReturn(false);
		$selectResult->method('closeCursor');
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('innerJoin')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('createNamedParameter')->willReturnArgument(0);
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$updateQb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$updateQb->method('update')->willReturnSelf();
		$updateQb->method('set')->willReturnSelf();
		$updateQb->method('where')->willReturnSelf();
		$updateQb->method('expr')->willReturn($expr);
		$updateQb->method('createNamedParameter')->willReturnArgument(0);
		$updateQb->method('executeStatement')->willReturn(1);

		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($selectQb, $updateQb);

		$this->mailboxes->expects($this->exactly($cap))
			->method('ingestMessage')
			->willReturn(['companyId' => null, 'how' => 'unmatched']);

		$result = $this->service()->ingestBatch(3, 9, 1, $messages);

		$this->assertSame($cap, $result['ingested']);
	}

	public function testPurgeRotatesWorkspaceOrderByMinute(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertNotSame('', $src);
		$this->assertStringContainsString('((int)floor(time() / 60)) % $count', $src);
		$this->assertStringContainsString('$ordered[] = $workspaces[($start + $i) % $count]', $src);
		$this->assertStringNotContainsString("\$ordered = \$workspaces;\n\n\t\t\t// Fair drain", $src);
	}
}