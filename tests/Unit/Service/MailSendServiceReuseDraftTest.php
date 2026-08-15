<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;

use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxAuthService;
use Psr\Log\LoggerInterface;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\WorkspaceService;
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
							'kind' => 'team',
							'email_address' => 'shared@example.com',
							'label' => 'Shared',
							'smtp_host' => '',
							'username' => '',
							'secret_enc' => '',
							'signature_html' => '',
							'signature_plain' => '',
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

	private function draftAclLoadQb(string $kind, string $createdBy, ?string $owner = null): object
	{
		return new class ($kind, $createdBy, $owner) {
			public function __construct(
				private string $kind,
				private string $createdBy,
				private ?string $owner,
			) {}
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function leftJoin(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
					public function isNull(...$a) { return 'isnull'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeQuery() {
				return new class ($this->kind, $this->createdBy, $this->owner) {
					public function __construct(
						private string $kind,
						private string $createdBy,
						private ?string $owner,
					) {}
					public function fetch() {
						return [
							'id' => 55,
							'created_by' => $this->createdBy,
							'mailbox_kind' => $this->kind,
							'mailbox_owner' => $this->owner,
						];
					}
					public function closeCursor(): void {}
				};
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
		$acl = $this->draftAclLoadQb('team', 'ae');
		// loadMailbox → findByIdempotency → outboundExists → workspaceName → claim ACL → claim update
		$queue = [$mailbox, $empty, $empty, $empty, $acl, $update];
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
			new HtmlSanitizeService(),
			$this->createMock(MailboxAuthService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(WorkspaceService::class),
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

	public function testReuseDraftRejectsPeerPersonalDraft(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$empty = $this->emptySelectQb();
		$mailbox = $this->mailboxSelectQb();
		$acl = $this->draftAclLoadQb('personal', 'owner', 'owner');
		$update = $this->updateQb(1);
		// loadMailbox → findByIdempotency → outboundExists → workspaceName → claim ACL → claim update
		$queue = [$mailbox, $empty, $empty, $empty, $acl, $update];
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
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailSendService(
			$db,
			$this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$rate,
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(FilesAccessService::class),
			$locking,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
			new HtmlSanitizeService(),
			$this->createMock(MailboxAuthService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(WorkspaceService::class),
		);

		try {
			$svc->send(1, [
				'mailboxId' => 2,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
				'idempotencyKey' => 'hijack',
				'reuseDraftId' => 55,
			], 'peer', true);
			$this->fail('Expected NotFoundException');
		} catch (NotFoundException $e) {
			$this->assertStringContainsString('no longer exists', $e->getMessage());
			$this->assertSame(0, $update->updates, 'ACL must reject before CAS update');
		}
	}

	public function testReuseDraftSuccessLeavesClaimWithoutNewInsert(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$update = $this->updateQb(1);
		$empty = $this->emptySelectQb();
		$mailbox = $this->mailboxSelectQb();
		$acl = $this->draftAclLoadQb('team', 'ae');
		$ws = new class {
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
					public function fetch() { return ['name' => 'WS']; }
					public function closeCursor(): void {}
				};
			}
		};
		$folderQb = new class {
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function orderBy(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeQuery() {
				return new class {
					private int $i = 0;
					public function fetch() {
						if ($this->i++ === 0) {
							return [
								'id' => 55,
								'folder_path' => 'Sent',
								'share_with_workspace' => 1,
							];
						}
						return false;
					}
					public function closeCursor(): void {}
				};
			}
		};
		$insertQb = new class {
			public function insert(...$a) { return $this; }
			public function values(...$a) { return $this; }
			public function createNamedParameter($v) { return $v; }
			public function executeStatement(): int { return 1; }
		};
		// loadMailbox → findByIdempotency → outboundExists → workspaceName → claim ACL → claim update
		// → markSmtpAcked → outboundExists → folder meta → outbound insert
		$queue = [$mailbox, $empty, $empty, $ws, $acl, $update, $update, $empty, $folderQb, $insertQb];
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
			new HtmlSanitizeService(),
			$this->createMock(MailboxAuthService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(WorkspaceService::class),
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
		// claim CAS + markSmtpAcked (same stub reused for both updates)
		$this->assertSame(2, $update->updates);
	}
}
