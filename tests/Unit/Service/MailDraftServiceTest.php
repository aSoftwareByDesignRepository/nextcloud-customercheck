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
 */
final class MailDraftServiceTest extends TestCase
{
	private function l10n(): IL10N
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return $l10n;
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
					];
				}
				return false;
			}
			public function closeCursor(): void {}
		};
		$qb = new class ($result) {
			public function __construct(private $result) {}
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
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
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('Everyone can see shared mail.');

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$mailboxes,
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$out = $svc->listFailed(3, 'Acme', 50);
		$this->assertSame('Everyone can see shared mail.', $out['disclosure']);
		$this->assertCount(1, $out['items']);
		$this->assertSame(5, $out['items'][0]['id']);
		$this->assertSame('failed', $out['items'][0]['status']);
		$this->assertArrayNotHasKey('body', $out['items'][0]);
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
				];
			}
			public function closeCursor(): void {}
		};
		$qb = new class ($result) {
			public function __construct(private $result) {}
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
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
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$out = $svc->getFailed(1, 7);
		$this->assertSame('Body text', $out['body']);
		$this->assertSame('b@c.d', $out['to']);
	}

	public function testGetFailedMissingThrows(): void
	{
		$result = new class {
			public function fetch() { return false; }
			public function closeCursor(): void {}
		};
		$qb = new class ($result) {
			public function __construct(private $result) {}
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
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
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$this->expectException(NotFoundException::class);
		$svc->getFailed(1, 99);
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
				];
			}
			public function closeCursor(): void {}
		};
		$qb = new class ($result) {
			public function __construct(private $result) {}
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
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
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

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
				];
			}
			public function closeCursor(): void {}
		};
		$qb = new class ($result) {
			public function __construct(private $result) {}
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
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
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

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
		$qb = new class {
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
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new MailDraftService(
			$db,
			$this->createMock(MailSendService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(ITimeFactory::class),
			$this->l10n(),
		);

		$this->expectException(NotFoundException::class);
		$svc->deleteFailed(1, 5);
	}
}
