<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailSendService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * §14.4 failed-draft inbox — list excludes claims; body only on get; resend reuses row.
 * Argus: personal failed drafts are owner-scoped (not workspace mail.read).
 */
final class MailDraftServiceTest extends TestCase
{
	private function l10n(): IL10N
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return $l10n;
	}

	/** @return object */
	private function listQb(object $result): object
	{
		return new class ($result) {
			public function __construct(private $result) {}
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function leftJoin(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function orderBy(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
					public function isNull(...$a) { return 'isnull'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeQuery() { return $this->result; }
		};
	}

	/** @return object */
	private function loadQb(object $result): object
	{
		return new class ($result) {
			public function __construct(private $result) {}
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
			public function executeQuery() { return $this->result; }
		};
	}

	public function testListFailedExcludesClaimRowsAndOmitsBody(): void
	{
		$result = new class {
			private int $n = 0;
			public function fetch()
			{
				$this->n++;
				if ($this->n === 1) {
					return [
						'id' => 5,
						'mailbox_id' => 2,
						'company_id' => 9,
						'to_addrs' => 'ada@example.com',
						'subject' => 'Hello',
						'created_by' => 'ae',
						'created_at' => '2026-07-01 10:00:00',
						'updated_at' => '2026-07-01 10:01:00',
						'mailbox_kind' => 'team',
						'mailbox_owner' => null,
					];
				}
				return false;
			}
			public function closeCursor(): void {}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->listQb($result));

		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('Everyone can see shared mail.');

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$mailboxes,
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$out = $svc->listFailed(3, 'Acme', 'peer', 50);
		$this->assertSame('Everyone can see shared mail.', $out['disclosure']);
		$this->assertCount(1, $out['items']);
		$this->assertSame(5, $out['items'][0]['id']);
		$this->assertSame('failed', $out['items'][0]['status']);
		$this->assertArrayNotHasKey('body', $out['items'][0]);
	}

	public function testListFailedHidesPeerPersonalDrafts(): void
	{
		$result = new class {
			private int $n = 0;
			public function fetch()
			{
				$this->n++;
				if ($this->n === 1) {
					return [
						'id' => 9,
						'mailbox_id' => 2,
						'company_id' => null,
						'to_addrs' => 'secret@example.com',
						'subject' => 'Private fail',
						'created_by' => 'owner',
						'created_at' => '2026-07-01 10:00:00',
						'updated_at' => '2026-07-01 10:01:00',
						'mailbox_kind' => 'personal',
						'mailbox_owner' => 'owner',
					];
				}
				return false;
			}
			public function closeCursor(): void {}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->listQb($result));
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('d');

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$mailboxes,
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$out = $svc->listFailed(1, 'Acme', 'peer', 50);
		$this->assertCount(0, $out['items']);

		$result2 = new class {
			private int $n = 0;
			public function fetch()
			{
				$this->n++;
				if ($this->n === 1) {
					return [
						'id' => 9,
						'mailbox_id' => 2,
						'company_id' => null,
						'to_addrs' => 'secret@example.com',
						'subject' => 'Private fail',
						'created_by' => 'owner',
						'created_at' => '2026-07-01 10:00:00',
						'updated_at' => '2026-07-01 10:01:00',
						'mailbox_kind' => 'personal',
						'mailbox_owner' => 'owner',
					];
				}
				return false;
			}
			public function closeCursor(): void {}
		};
		$db2 = $this->createMock(IDBConnection::class);
		$db2->method('tableExists')->willReturn(true);
		$db2->method('getQueryBuilder')->willReturn($this->listQb($result2));
		$svc2 = new MailDraftService(
			$db2,
			$this->createMock(MailSendService::class),
			$mailboxes,
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);
		$ownerOut = $svc2->listFailed(1, 'Acme', 'owner', 50);
		$this->assertCount(1, $ownerOut['items']);
	}

	public function testGetFailedIncludesBody(): void
	{
		$result = new class {
			public function fetch()
			{
				return [
					'id' => 7,
					'mailbox_id' => 1,
					'company_id' => null,
					'to_addrs' => 'b@c.d',
					'subject' => 'Sub',
					'body_text' => 'Body text',
					'created_by' => 'ae',
					'created_at' => null,
					'updated_at' => null,
					'mailbox_kind' => 'team',
					'mailbox_owner' => null,
				];
			}
			public function closeCursor(): void {}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->loadQb($result));

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$out = $svc->getFailed(1, 7, 'ae');
		$this->assertSame('Body text', $out['body']);
		$this->assertSame('b@c.d', $out['to']);
	}

	public function testGetFailedPersonalPeerThrows(): void
	{
		$result = new class {
			public function fetch()
			{
				return [
					'id' => 7,
					'mailbox_id' => 1,
					'company_id' => null,
					'to_addrs' => 'b@c.d',
					'subject' => 'Sub',
					'body_text' => 'Body text',
					'created_by' => 'owner',
					'mailbox_kind' => 'personal',
					'mailbox_owner' => 'owner',
				];
			}
			public function closeCursor(): void {}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->loadQb($result));
		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);
		$this->expectException(NotFoundException::class);
		$svc->getFailed(1, 7, 'peer');
	}

	public function testGetFailedMissingThrows(): void
	{
		$result = new class {
			public function fetch() { return false; }
			public function closeCursor(): void {}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->loadQb($result));

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$this->expectException(NotFoundException::class);
		$svc->getFailed(1, 99, 'ae');
	}

	public function testResendRequiresIdempotencyKey(): void
	{
		$result = new class {
			public function fetch()
			{
				return [
					'id' => 3,
					'mailbox_id' => 1,
					'company_id' => 2,
					'to_addrs' => 'a@b.c',
					'subject' => 'S',
					'body_text' => 'B',
					'mailbox_kind' => 'team',
					'mailbox_owner' => null,
					'created_by' => 'ae',
				];
			}
			public function closeCursor(): void {}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->loadQb($result));

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		try {
			$svc->resendFailed(1, 3, 'ae', true, '');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('idempotencyKey', $e->getFields());
		}
	}

	public function testResendDelegatesWithReuseDraftId(): void
	{
		$result = new class {
			public function fetch()
			{
				return [
					'id' => 11,
					'mailbox_id' => 4,
					'company_id' => 8,
					'to_addrs' => 'x@y.z',
					'subject' => 'Quote',
					'body_text' => 'Please find…',
					'mailbox_kind' => 'team',
					'mailbox_owner' => null,
					'created_by' => 'ae',
				];
			}
			public function closeCursor(): void {}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($this->loadQb($result));

		$mailSend = $this->createMock(MailSendService::class);
		$mailSend->expects($this->once())
			->method('send')
			->with(
				1,
				$this->callback(static function (array $data): bool {
					return ($data['reuseDraftId'] ?? 0) === 11
						&& ($data['mailboxId'] ?? 0) === 4
						&& ($data['idempotencyKey'] ?? '') === 'k1'
						&& ($data['companyId'] ?? 0) === 8;
				}),
				'ae',
				true,
			)
			->willReturn(['sent' => true]);

		$svc = new MailDraftService(
			$db,
			$mailSend,
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$out = $svc->resendFailed(1, 11, 'ae', true, 'k1');
		$this->assertTrue($out['sent']);
	}

	public function testDeleteFailedRequiresCasHit(): void
	{
		$load = new class {
			public function fetch()
			{
				return [
					'id' => 5,
					'mailbox_id' => 1,
					'created_by' => 'ae',
					'mailbox_kind' => 'team',
					'mailbox_owner' => null,
				];
			}
			public function closeCursor(): void {}
		};
		$deleteQb = new class {
			public function delete(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
					public function isNull(...$a) { return 'isnull'; }
				};
			}
			public function createNamedParameter($v) { return $v; }
			public function executeStatement(): int { return 0; }
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->loadQb($load),
			$deleteQb,
		);

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$this->expectException(NotFoundException::class);
		$svc->deleteFailed(1, 5, 'ae');
	}
}
