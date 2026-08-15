<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * US-002 AC-3: auth quarantine notifies via recordAuthFailure (OAuth + IMAP + live-probe).
 */
final class MailboxServiceAuthQuarantineNotifyTest extends TestCase
{
	public function testThirdFailureNotifiesPersonalOwnerOnly(): void
	{
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())
			->method('notifyUser')
			->with(
				'owner-uid',
				Constants::NOTIFY_DIGEST,
				'mailbox_error',
				'42',
				['label' => 'me@example.com'],
			);
		$notifications->expects($this->never())->method('notifyWorkspaceUsers');

		$svc = $this->serviceWithNotify($notifications, 3, [
			'id' => 42,
			'workspace_id' => 7,
			'label' => 'me@example.com',
			'kind' => MailAclLogic::KIND_PERSONAL,
			'owner_user_id' => 'owner-uid',
		]);
		$status = $svc->recordAuthFailure(42, 2);
		$this->assertSame('error', $status);
	}

	public function testThirdFailureFansOutForTeamMailbox(): void
	{
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notifyUser');
		$notifications->expects($this->once())
			->method('notifyWorkspaceUsers')
			->with(
				7,
				Constants::NOTIFY_DIGEST,
				'mailbox_error',
				'99',
				['label' => 'Team Inbox'],
			);

		$svc = $this->serviceWithNotify($notifications, 3, [
			'id' => 99,
			'workspace_id' => 7,
			'label' => 'Team Inbox',
			'kind' => MailAclLogic::KIND_TEAM,
			'owner_user_id' => null,
		]);
		$this->assertSame('error', $svc->recordAuthFailure(99, 2));
	}

	public function testSecondFailureDoesNotNotify(): void
	{
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notifyUser');
		$notifications->expects($this->never())->method('notifyWorkspaceUsers');

		$svc = $this->serviceWithNotify($notifications, 2, null);
		$this->assertSame('ok', $svc->recordAuthFailure(42, 1));
	}

	public function testSubsequentQuarantineBumpDoesNotReNotify(): void
	{
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notifyUser');
		$notifications->expects($this->never())->method('notifyWorkspaceUsers');

		$svc = $this->serviceWithNotify($notifications, 4, null);
		// Atomic count already past 3 → already quarantined; no second push.
		$this->assertSame('error', $svc->recordAuthFailure(42, 3));
	}

	public function testAtomicIncrementUsesSqlPlusOne(): void
	{
		$notifications = $this->createMock(NotificationService::class);
		$svc = $this->serviceWithNotify($notifications, 1, null, true);
		$this->assertSame('ok', $svc->recordAuthFailure(7, 0));
	}

	/**
	 * @param array<string, mixed>|null $notifySelectRow
	 */
	private function serviceWithNotify(
		NotificationService $notifications,
		int $authFailAfterIncrement,
		?array $notifySelectRow,
		bool $assertCreateFunction = false,
	): MailboxService {
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$incQb = $this->createMock(IQueryBuilder::class);
		$incQb->method('update')->willReturnSelf();
		$incQb->method('set')->willReturnSelf();
		$incQb->method('where')->willReturnSelf();
		$incQb->method('expr')->willReturn($expr);
		$incQb->method('createNamedParameter')->willReturnArgument(0);
		if ($assertCreateFunction) {
			$incQb->expects($this->once())
				->method('createFunction')
				->with('auth_fail_count + 1')
				->willReturn('auth_fail_count + 1');
		} else {
			$incQb->method('createFunction')->willReturn('auth_fail_count + 1');
		}
		$incQb->method('executeStatement')->willReturn(1);

		$countQb = $this->createMock(IQueryBuilder::class);
		$countQb->method('select')->willReturnSelf();
		$countQb->method('from')->willReturnSelf();
		$countQb->method('where')->willReturnSelf();
		$countQb->method('setMaxResults')->willReturnSelf();
		$countQb->method('expr')->willReturn($expr);
		$countQb->method('createNamedParameter')->willReturnArgument(0);
		$countResult = $this->createMock(IResult::class);
		$countResult->method('fetch')->willReturn(['auth_fail_count' => $authFailAfterIncrement]);
		$countResult->method('closeCursor');
		$countQb->method('executeQuery')->willReturn($countResult);

		$statusQb = $this->createMock(IQueryBuilder::class);
		$statusQb->method('update')->willReturnSelf();
		$statusQb->method('set')->willReturnSelf();
		$statusQb->method('where')->willReturnSelf();
		$statusQb->method('expr')->willReturn($expr);
		$statusQb->method('createNamedParameter')->willReturnArgument(0);
		$statusQb->method('executeStatement')->willReturn(1);

		$notifyQb = $this->createMock(IQueryBuilder::class);
		$notifyQb->method('select')->willReturnSelf();
		$notifyQb->method('from')->willReturnSelf();
		$notifyQb->method('where')->willReturnSelf();
		$notifyQb->method('setMaxResults')->willReturnSelf();
		$notifyQb->method('expr')->willReturn($expr);
		$notifyQb->method('createNamedParameter')->willReturnArgument(0);
		$notifyResult = $this->createMock(IResult::class);
		$notifyResult->method('fetch')->willReturn($notifySelectRow);
		$notifyResult->method('closeCursor');
		$notifyQb->method('executeQuery')->willReturn($notifyResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$call = 0;
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use (&$call, $incQb, $countQb, $statusQb, $notifyQb) {
				$call++;
				return match ($call) {
					1 => $incQb,
					2 => $countQb,
					3 => $statusQb,
					default => $notifyQb,
				};
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-15 12:00:00'));

		return new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$this->createMock(IL10N::class),
			new HtmlSanitizeService(),
			$notifications,
		);
	}
}
