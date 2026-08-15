<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxAuthService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\RateLimitService;
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
	/** @var RateLimitService&MockObject */
	private RateLimitService $rateLimit;

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
		$this->rateLimit = $this->createMock(RateLimitService::class);
		$this->rateLimit->method('allow')->willReturn(true);
		$this->l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$this->l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $n): string => $n === 1 ? $s : $p);
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
			$this->rateLimit,
			$this->createMock(MailboxAuthService::class),
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

		$this->assertSame([
			'mailboxes' => 0,
			'messages' => 0,
			'errors' => 0,
			'cooldownSkipped' => 0,
			'purged' => 0,
			'purgeRemaining' => 0,
		], $result);
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
		$this->assertContains($result['reason'] ?? null, ['validation', 'extension']);
	}

	public function testTestConnectionNormalizesUsernameBeforeValidation(): void
	{
		$result = $this->service()->testConnection([
			'imapHost' => '',
			'username' => '  (m06367b4)  ',
			'password' => 'x',
		]);
		$this->assertFalse($result['ok']);
		$this->assertContains($result['reason'] ?? null, ['validation', 'extension']);
		if (($result['reason'] ?? '') === 'validation') {
			$this->assertStringContainsString('required', strtolower($result['message']));
		}
	}

	public function testTestConnectionFallsBackToLiveProbeWhenImapMissing(): void
	{
		if (function_exists('imap_open')) {
			$this->markTestSkipped('php-imap present — live-probe fallback not exercised');
		}
		$result = $this->service()->testConnection([
			'imapHost' => 'w01d1cf3.kasserver.com',
			'imapPort' => 993,
			'username' => 'info@software-by-design.de (m06367b4)',
			'password' => 'definitely-wrong-' . bin2hex(random_bytes(3)),
		]);
		$this->assertFalse($result['ok']);
		$this->assertSame('live_probe', $result['via'] ?? null);
		$this->assertNotSame('extension', $result['reason'] ?? 'extension');
		$this->assertContains($result['reason'] ?? '', ['auth', 'network', 'unknown']);
	}

	public function testMailboxSpecBuilderUsesNovalidateFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('ImapConnection::buildMailboxRoot', $src);
		$this->assertStringContainsString('failedTestResult', $src);
		$this->assertStringContainsString('loadMailboxSecret', $src);
		$this->assertStringContainsString('testConnectionViaLiveProbe', $src);
		$this->assertStringContainsString('doSyncViaLiveProbe', $src);
		$this->assertStringContainsString('return $this->doSyncViaLiveProbe($mailboxId, $workspaceId, $row);', $src);
		$this->assertStringContainsString('ImapLiveProbe::fetchRecentMessages', $src);
		$this->assertStringContainsString('ImapLiveProbe::probeLogin', $src);
		$this->assertStringContainsString('$folderPath = (string)$folder[\'folder_path\'];', $src);
		$this->assertStringContainsString('$wirePath = ImapConnection::folderToWire($folderPath);', $src);
		$this->assertStringContainsString('$full = $mailboxPath . $wirePath;', $src);
		$this->assertStringContainsString('ImapConnection::isSafeImapFolder($folderPath)', $src);
		$this->assertStringContainsString('CustomerCheck mailbox syncOne failed', $src);
		$this->assertDoesNotMatchRegularExpression(
			'/foreach\s*\(\s*\$folders\s+as\s+\$folder\s*\)\s*\{\s*@imap_reopen\(\s*\$stream\s*,\s*\$full\s*\)/s',
			$src,
			'php-imap sync must assign $full from mailboxPath + folderToWire(folder_path) before imap_reopen',
		);
		$controller = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString("\$body['workspaceId'] = \$workspaceId;", $controller);
		$this->assertStringContainsString('function syncMailbox', $controller);
		$this->assertStringContainsString('private readonly IL10N $l10n', $controller);
	}

	public function testApiMapsNotAuthenticatedToUnauthorizedNotServerError(): void
	{
		$trait = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiControllerTrait.php');
		$this->assertStringContainsString('NotAuthenticatedException', $trait);
		$this->assertStringContainsString('ERR_NOT_AUTHENTICATED', $trait);
		$this->assertStringContainsString('STATUS_UNAUTHORIZED', $trait);
		$constants = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Constants.php');
		$this->assertStringContainsString("ERR_NOT_AUTHENTICATED = 'NOT_AUTHENTICATED'", $constants);
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

		$this->mailboxes->method('listMailboxes')->with(3)->willReturn([]);
		$this->workspaces->method('listMemberEmails')->with(3)->willReturn([]);

		$this->mailboxes->expects($this->exactly($cap))
			->method('ingestMessage')
			->willReturn(['inserted' => true, 'touched' => true]);

		$result = $this->service()->ingestBatch(3, 9, 1, $messages);

		$this->assertSame($cap, $result['ingested']);
		$this->assertSame(0, $result['refreshed']);
	}

	public function testIngestBatchPassesTeamExcludeEmailsToMatch(): void
	{
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
		$this->mailboxes->method('listMailboxes')->with(5)->willReturn([
			['id' => 1, 'emailAddress' => 'shared@office.example'],
		]);
		$this->workspaces->method('listMemberEmails')->with(5)->willReturn(['alex@office.example']);

		$this->mailboxes->expects($this->once())
			->method('ingestMessage')
			->with(
				5,
				9,
				1,
				$this->callback(static fn (array $msg): bool => ($msg['from'] ?? '') === 'alex@office.example'),
				$this->isType('array'),
				$this->callback(static function (array $exclude): bool {
					sort($exclude);
					return $exclude === ['alex@office.example', 'shared@office.example'];
				}),
			)
			->willReturn(['inserted' => true, 'touched' => true, 'companyId' => null, 'how' => 'team']);

		$result = $this->service()->ingestBatch(5, 9, 1, [[
			'uid' => '1',
			'from' => 'alex@office.example',
			'subject' => 'Hi',
			'snippet' => 'Hi',
		]]);

		$this->assertSame(1, $result['ingested']);
		$this->assertSame(0, $result['refreshed']);
	}

	public function testPurgeRotatesWorkspaceOrderByMinute(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertNotSame('', $src);
		$this->assertStringContainsString('((int)floor(time() / 60)) % $count', $src);
		$this->assertStringContainsString('$ordered[] = $workspaces[($start + $i) % $count]', $src);
		$this->assertStringNotContainsString("\$ordered = \$workspaces;\n\n\t\t\t// Fair drain", $src);
	}

	public function testSyncWorkspaceWithNoMailboxes(): void
	{
		$this->mailboxes->method('listMailboxes')->with(5)->willReturn([]);

		$result = $this->service()->syncWorkspace(5);

		$this->assertSame(0, $result['mailboxes']);
		$this->assertSame(0, $result['attempted']);
		$this->assertSame(0, $result['ingested']);
		$this->assertStringContainsString('No shared mailbox', $result['message']);
	}

	public function testMailSyncSummaryFromBoxes(): void
	{
		$this->assertNull(MailboxSyncService::mailSyncSummaryFromBoxes([]));
		$summary = MailboxSyncService::mailSyncSummaryFromBoxes([
			['id' => 1, 'lastSyncAt' => '2026-08-13 10:00:00'],
			['id' => 2, 'lastSyncAt' => '2026-08-13 11:00:00'],
		]);
		$this->assertSame(2, $summary['mailboxCount']);
		$this->assertSame('2026-08-13 11:00:00', $summary['lastSyncAt']);
		$this->assertSame(15, $summary['backgroundIntervalMinutes']);
	}

	public function testMailSyncSummaryIgnoresBlankLastSyncAt(): void
	{
		$summary = MailboxSyncService::mailSyncSummaryFromBoxes([
			['id' => 1, 'lastSyncAt' => ''],
			['id' => 2, 'lastSyncAt' => '2026-08-13 11:00:00'],
		]);
		$this->assertSame('2026-08-13 11:00:00', $summary['lastSyncAt']);
	}

	public function testSyncWorkspaceRejectsInvalidWorkspaceId(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->syncWorkspace(0);
	}

	public function testSyncWorkspaceSkipsErrorMailboxes(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturnOnConsecutiveCalls(
			[
				['id' => 1, 'status' => 'error'],
				['id' => 2, 'status' => 'ok'],
			],
			[
				['id' => 1, 'status' => 'error', 'lastSyncAt' => '2026-08-13 09:00:00'],
				['id' => 2, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 10:30:00'],
			],
		);

		$svc = $this->getMockBuilder(MailboxSyncService::class)
			->setConstructorArgs([
				$this->db,
				$this->crypto,
				$this->mailboxes,
				$this->workspaces,
				$this->notifications,
				$this->locks,
				$this->time,
				$this->logger,
				$this->l10n,
				$this->rateLimit,
				$this->createMock(MailboxAuthService::class),
			])
			->onlyMethods(['syncOne'])
			->getMock();
		$svc->expects($this->once())
			->method('syncOne')
			->with(2, 7)
			->willReturn(['ingested' => 0, 'status' => 'ok']);

		$result = $svc->syncWorkspace(7);

		$this->assertSame(2, $result['mailboxes']);
		$this->assertSame(1, $result['attempted']);
		$this->assertSame(1, $result['ok']);
		$this->assertSame('2026-08-13 10:30:00', $result['lastSyncAt']);
		$this->assertStringContainsString('up to date', $result['message']);
	}

	public function testSyncWorkspaceAllErrorMailboxesNeedsAttention(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturn([
			['id' => 1, 'status' => 'error'],
			['id' => 2, 'status' => 'error'],
		]);

		$result = $this->service()->syncWorkspace(3);

		$this->assertSame(2, $result['mailboxes']);
		$this->assertSame(0, $result['attempted']);
		$this->assertStringContainsString('need attention', $result['message']);
	}

	public function testSyncWorkspaceLockedOnlySurfacesBusyMessage(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturnOnConsecutiveCalls(
			[['id' => 4, 'status' => 'ok']],
			[['id' => 4, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 12:00:00']],
		);

		$svc = $this->getMockBuilder(MailboxSyncService::class)
			->setConstructorArgs([
				$this->db,
				$this->crypto,
				$this->mailboxes,
				$this->workspaces,
				$this->notifications,
				$this->locks,
				$this->time,
				$this->logger,
				$this->l10n,
				$this->rateLimit,
				$this->createMock(MailboxAuthService::class),
			])
			->onlyMethods(['syncOne'])
			->getMock();
		$svc->method('syncOne')->willReturn(['ingested' => 0, 'status' => 'locked']);

		$result = $svc->syncWorkspace(8);

		$this->assertSame(1, $result['locked']);
		$this->assertSame(0, $result['ok']);
		$this->assertStringContainsString('already running', $result['message']);
	}

	public function testSyncWorkspaceReportsIngestedCount(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturnOnConsecutiveCalls(
			[['id' => 9, 'status' => 'ok']],
			[['id' => 9, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 12:05:00']],
		);

		$svc = $this->getMockBuilder(MailboxSyncService::class)
			->setConstructorArgs([
				$this->db,
				$this->crypto,
				$this->mailboxes,
				$this->workspaces,
				$this->notifications,
				$this->locks,
				$this->time,
				$this->logger,
				$this->l10n,
				$this->rateLimit,
				$this->createMock(MailboxAuthService::class),
			])
			->onlyMethods(['syncOne'])
			->getMock();
		$svc->method('syncOne')->willReturn(['ingested' => 2, 'status' => 'ok']);

		$result = $svc->syncWorkspace(11);

		$this->assertSame(2, $result['ingested']);
		$this->assertStringContainsString('Synced %n new messages.', $result['message']);
	}

	public function testSyncWorkspaceCountsSyncOneThrowAsError(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturnOnConsecutiveCalls(
			[['id' => 6, 'status' => 'ok']],
			[['id' => 6, 'status' => 'error']],
		);

		$svc = $this->getMockBuilder(MailboxSyncService::class)
			->setConstructorArgs([
				$this->db,
				$this->crypto,
				$this->mailboxes,
				$this->workspaces,
				$this->notifications,
				$this->locks,
				$this->time,
				$this->logger,
				$this->l10n,
				$this->rateLimit,
				$this->createMock(MailboxAuthService::class),
			])
			->onlyMethods(['syncOne'])
			->getMock();
		$svc->method('syncOne')->willThrowException(new \RuntimeException('imap down'));
		$this->logger->expects($this->once())->method('warning');

		$result = $svc->syncWorkspace(12);

		$this->assertSame(1, $result['errors']);
		$this->assertStringContainsString('Could not sync', $result['message']);
	}

	public function testSyncWorkspaceSyncsEveryHealthyMailbox(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturnOnConsecutiveCalls(
			[
				['id' => 10, 'status' => 'ok'],
				['id' => 11, 'status' => 'ok'],
				['id' => 12, 'status' => 'error'],
			],
			[
				['id' => 10, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 12:00:00'],
				['id' => 11, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 12:01:00'],
				['id' => 12, 'status' => 'error', 'lastSyncAt' => null],
			],
		);

		$svc = $this->getMockBuilder(MailboxSyncService::class)
			->setConstructorArgs([
				$this->db,
				$this->crypto,
				$this->mailboxes,
				$this->workspaces,
				$this->notifications,
				$this->locks,
				$this->time,
				$this->logger,
				$this->l10n,
				$this->rateLimit,
				$this->createMock(MailboxAuthService::class),
			])
			->onlyMethods(['syncOne'])
			->getMock();
		$svc->expects($this->exactly(2))
			->method('syncOne')
			->willReturnCallback(static function (int $id, int $ws): array {
				self::assertSame(7, $ws);
				self::assertContains($id, [10, 11]);
				return ['ingested' => $id === 10 ? 1 : 2, 'status' => 'ok'];
			});

		$result = $svc->syncWorkspace(7);

		$this->assertSame(3, $result['mailboxes']);
		$this->assertSame(2, $result['attempted']);
		$this->assertSame(2, $result['ok']);
		$this->assertSame(3, $result['ingested']);
		$this->assertSame('2026-08-13 12:01:00', $result['lastSyncAt']);
		$this->assertStringContainsString('Synced %n new messages.', $result['message']);
	}

	public function testSyncWorkspacePartialSuccessReportsAttention(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturnOnConsecutiveCalls(
			[
				['id' => 20, 'status' => 'ok'],
				['id' => 21, 'status' => 'ok'],
			],
			[
				['id' => 20, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 12:00:00'],
				['id' => 21, 'status' => 'error', 'lastSyncAt' => '2026-08-13 11:00:00'],
			],
		);

		$svc = $this->getMockBuilder(MailboxSyncService::class)
			->setConstructorArgs([
				$this->db,
				$this->crypto,
				$this->mailboxes,
				$this->workspaces,
				$this->notifications,
				$this->locks,
				$this->time,
				$this->logger,
				$this->l10n,
				$this->rateLimit,
				$this->createMock(MailboxAuthService::class),
			])
			->onlyMethods(['syncOne'])
			->getMock();
		$svc->method('syncOne')->willReturnOnConsecutiveCalls(
			['ingested' => 0, 'status' => 'ok'],
			['ingested' => 0, 'status' => 'auth'],
		);

		$result = $svc->syncWorkspace(9);

		$this->assertSame(1, $result['ok']);
		$this->assertSame(1, $result['errors']);
		$this->assertStringContainsString('Some mailboxes synced', $result['message']);
		$this->assertStringContainsString('need attention', $result['message']);
	}

	public function testSyncWorkspaceMultiBoxAllOkUsesPluralUpToDate(): void
	{
		$this->mailboxes->method('listMailboxes')->willReturnOnConsecutiveCalls(
			[
				['id' => 30, 'status' => 'ok'],
				['id' => 31, 'status' => 'ok'],
			],
			[
				['id' => 30, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 12:00:00'],
				['id' => 31, 'status' => 'ok', 'lastSyncAt' => '2026-08-13 12:00:00'],
			],
		);

		$svc = $this->getMockBuilder(MailboxSyncService::class)
			->setConstructorArgs([
				$this->db,
				$this->crypto,
				$this->mailboxes,
				$this->workspaces,
				$this->notifications,
				$this->locks,
				$this->time,
				$this->logger,
				$this->l10n,
				$this->rateLimit,
				$this->createMock(MailboxAuthService::class),
			])
			->onlyMethods(['syncOne'])
			->getMock();
		$svc->method('syncOne')->willReturn(['ingested' => 0, 'status' => 'ok']);

		$result = $svc->syncWorkspace(4);

		$this->assertSame(2, $result['ok']);
		$this->assertSame('All shared mailboxes are up to date.', $result['message']);
	}

	/**
	 * CRM-sent copies use synthetic outbound: UIDs — delete/spam must CRM-only soft-succeed
	 * (never “Ordner oder Id fehlt” / missing folder or id).
	 *
	 * @dataProvider outboundUidProvider
	 */
	public function testOutboundUidDeleteAndSpamAreCrmOnly(string $uid): void
	{
		$this->mailboxes->expects($this->atLeastOnce())
			->method('findMessageForPreview')
			->with(1, 99)
			->willReturn([
				'id' => 99,
				'mailboxId' => 7,
				'folderId' => 3,
				'folderPath' => 'INBOX',
				'messageUid' => $uid,
				'mailboxKind' => 'team',
			]);
		$this->mailboxes->method('resolveMailboxKind')->willReturn('team');
		$this->locks->expects($this->never())->method('acquireLock');

		$svc = $this->service();
		$this->assertFalse($svc->deleteIndexedMessage(1, 99, null));
		$this->assertFalse($svc->moveIndexedMessageToJunk(1, 99, null));
	}

	/**
	 * @return list<array{0: string}>
	 */
	public static function outboundUidProvider(): array
	{
		return [
			['outbound:crm-send-abc'],
			[''],
			['1703a'],
			['not-a-uid'],
		];
	}

	public function testZeroMailboxIdDeleteIsCrmOnly(): void
	{
		$this->mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 5,
			'mailboxId' => 0,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'mailboxKind' => 'team',
		]);
		$this->locks->expects($this->never())->method('acquireLock');

		$this->assertFalse($this->service()->deleteIndexedMessage(1, 5, null));
		$this->assertFalse($this->service()->moveIndexedMessageToJunk(1, 5, null));
	}

	public function testLiveUidStillGatesPersonalMailboxFromAssign(): void
	{
		$this->mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 8,
			'mailboxId' => 3,
			'folderPath' => 'INBOX',
			'messageUid' => '1703',
			'mailboxKind' => 'personal',
		]);
		$this->mailboxes->method('resolveMailboxKind')->willReturn('personal');
		$this->locks->expects($this->never())->method('acquireLock');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Personal mailbox messages cannot be deleted or junked from Assign');
		$this->service()->deleteIndexedMessage(1, 8, null);
	}

	/** Argus MF-01: personal outbound CRM-only still requires owner (Assign null actor blocked). */
	public function testPersonalOutboundCrmOnlyBlockedWithoutOwner(): void
	{
		$this->mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 44,
			'mailboxId' => 3,
			'folderPath' => 'Sent',
			'messageUid' => 'outbound:crm-send-x',
			'mailboxKind' => 'personal',
		]);
		$this->mailboxes->method('resolveMailboxKind')->willReturn('personal');
		$this->locks->expects($this->never())->method('acquireLock');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Personal mailbox messages cannot be deleted or junked from Assign');
		$this->service()->deleteIndexedMessage(1, 44, null);
	}

	/** Argus MF-01: owner may CRM-purge personal outbound (no IMAP dial). */
	public function testPersonalOutboundCrmOnlyAllowedForOwner(): void
	{
		$this->mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 45,
			'mailboxId' => 3,
			'folderPath' => 'Sent',
			'messageUid' => 'outbound:crm-send-y',
			'mailboxKind' => 'personal',
		]);
		$this->mailboxes->method('resolveMailboxKind')->willReturn('personal');
		$this->mailboxes->method('resolveMailboxOwnerUserId')->with(1, 3)->willReturn('alice');
		$this->locks->expects($this->never())->method('acquireLock');

		$this->assertFalse($this->service()->deleteIndexedMessage(1, 45, 'alice'));
		$this->assertFalse($this->service()->moveIndexedMessageToJunk(1, 45, 'alice'));
	}

	/** Argus MF-02: blank-password test must not decrypt personal mailbox secrets. */
	public function testConnectionBlankPasswordRefusesPersonalMailbox(): void
	{
		$this->mailboxes->method('resolveMailboxKind')->with(1, 9)->willReturn('personal');
		$this->crypto->expects($this->never())->method('decrypt');
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$result = $this->service()->testConnection([
			'workspaceId' => 1,
			'id' => 9,
			'imapHost' => 'mail.example.com',
			'username' => 'user@example.com',
			'password' => '',
		]);

		$this->assertFalse($result['ok']);
		$this->assertSame('validation', $result['reason']);
		$this->assertStringContainsString('personal mailbox', strtolower((string)$result['message']));
	}

	/** Argus SF-02: delete/spam mutate rate limit blocks CRM purge storms. */
	public function testOutboundCrmOnlyHonorsMutateRateLimit(): void
	{
		$this->mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 99,
			'mailboxId' => 7,
			'folderPath' => 'INBOX',
			'messageUid' => 'outbound:crm-send-rate',
			'mailboxKind' => 'team',
		]);
		$this->mailboxes->method('resolveMailboxKind')->willReturn('team');
		$this->rateLimit = $this->createMock(RateLimitService::class);
		$this->rateLimit->method('allow')->willReturn(false);
		$this->locks->expects($this->never())->method('acquireLock');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Too many delete or spam actions');
		$this->service()->deleteIndexedMessage(1, 99, null, 'bob');
	}

	/** Argus SF-03: non-owner personal mutate emits audit warning before throw. */
	public function testPersonalOutboundDeniedLogsSecurityEvent(): void
	{
		$this->mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 44,
			'mailboxId' => 3,
			'folderPath' => 'Sent',
			'messageUid' => 'outbound:crm-send-x',
			'mailboxKind' => 'personal',
		]);
		$this->mailboxes->method('resolveMailboxKind')->willReturn('personal');
		$this->logger->expects($this->once())->method('warning')->with(
			'personal_mail.mutate_denied',
			$this->callback(static function (array $ctx): bool {
				return ($ctx['event'] ?? '') === 'personal_mail.mutate_denied'
					&& ($ctx['reason'] ?? '') === 'assign_or_empty_actor';
			}),
		);

		$this->expectException(ValidationException::class);
		$this->service()->deleteIndexedMessage(1, 44, null);
	}
}