<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Resend failed draft reuses the same row (CAS) — no duplicate recovery drafts.
 */
final class MailSendServiceReuseDraftTest extends TestCase
{
	/** Empty fetch for findByIdempotency / workspace name. */
	private function emptySelectQb(): object
	{
		return new class {
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeQuery() {
				return new class {
					public function fetch() { return false; }
					public function closeCursor(): void {}
				};
			}
		};
	}

	private function mailboxSelectQb(): object
	{
		return new class {
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeQuery() {
				return new class {
					public function fetch() {
						return [
							'id' => 2,
							'workspace_id' => 1,
							'email_address' => 'shared@example.com',
							'label' => 'Shared',
							'smtp_host' => '',
							'username' => '',
							'secret_enc' => '',
						];
					}
					public function closeCursor(): void {}
				};
			}
		};
	}

	private function updateQb(int $affected): object
	{
		return new class ($affected) {
			public int $updates = 0;
			public function __construct(private int $affected) {}
			public function update(...$a) { return $this; }
			public function set(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
					public function isNull(...$a) { return 'isnull'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeStatement(): int
			{
				$this->updates++;
				return $this->affected;
			}
		};
	}

	public function testReuseDraftClaimFailsWhenAlreadyClaimed(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$update = $this->updateQb(0);
		$empty = $this->emptySelectQb();
		$mailbox = $this->mailboxSelectQb();
		// findByIdempotency → loadMailbox → workspaceName → claimFailedDraft
		$queue = [$empty, $mailbox, $empty, $update];
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$queue) {
			return array_shift($queue) ?? new class {
				public function __call($n, $a) { return $this; }
			};
		});

		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('d');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailSendService(
			$db,
			$this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$rate,
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$mailboxes,
			$this->createMock(FilesAccessService::class),
			$locking,
			$time,
			$this->createMock(IURLGenerator::class),
			$l10n,
		);

		try {
			$svc->send(1, [
				'mailboxId' => 2,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
				'idempotencyKey' => 'reuse-key',
				'reuseDraftId' => 55,
			], 'ae', true);
			$this->fail('Expected NotFoundException');
		} catch (NotFoundException $e) {
			$this->assertStringContainsString('no longer exists', $e->getMessage());
		}
	}

	public function testReuseDraftSuccessLeavesClaimWithoutNewInsert(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$update = $this->updateQb(1);
		$empty = $this->emptySelectQb();
		$mailbox = $this->mailboxSelectQb();
		$ws = new class {
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeQuery() {
				return new class {
					public function fetch() { return ['name' => 'WS']; }
					public function closeCursor(): void {}
				};
			}
		};
		// findByIdempotency → loadMailbox → workspaceName → claimFailedDraft
		$queue = [$empty, $mailbox, $ws, $update];
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$queue) {
			return array_shift($queue) ?? new class {
				public function __call($n, $a) { return $this; }
			};
		});

		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturnSelf();
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('setPlainBody')->willReturnSelf();
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send');

		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('d');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailSendService(
			$db,
			$mailer,
			$this->createMock(ICrypto::class),
			$rate,
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$mailboxes,
			$this->createMock(FilesAccessService::class),
			$locking,
			$time,
			$this->createMock(IURLGenerator::class),
			$l10n,
		);

		$result = $svc->send(1, [
			'mailboxId' => 2,
			'to' => 'ada@example.com',
			'subject' => 'Hi',
			'body' => 'Body',
			'idempotencyKey' => 'reuse-ok',
			'reuseDraftId' => 42,
		], 'ae', true);

		$this->assertTrue($result['sent']);
		$this->assertSame(1, $update->updates);
	}
}
