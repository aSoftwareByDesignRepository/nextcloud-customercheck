<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Support\SharedMailboxSmtpClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * CRM outbound ↔ IMAP Sent dedupe via RFC Message-ID.
 */
final class MailMessageIdDedupeTest extends TestCase
{
	public function testIngestSkipsSecondRowWhenMessageIdExists(): void
	{
		$insertCalls = 0;
		$updates = [];
		$svc = $this->service($insertCalls, $updates, existing: [
			'id' => 88,
			'message_uid' => 'outbound:idem-1',
			'body_text' => 'Hello outbound',
			'body_html' => null,
			'preview' => 'Hello',
			'folder_id' => 55,
		]);

		$match = $svc->ingestMessage(
			1,
			10,
			55,
			[
				'uid' => '901',
				'messageId' => '<abc@example.com>',
				'from' => 'ae@example.com',
				'to' => ['ada@acme.test'],
				'subject' => 'Hi',
				'bodyText' => 'Hello outbound',
				'direction' => 'inbound',
			],
			[],
			[],
		);

		$this->assertFalse($match['inserted']);
		$this->assertSame(0, $insertCalls);
		$this->assertSame('901', $updates['message_uid'] ?? null);
		$this->assertSame(MailAclLogic::VISIBILITY_PRIVATE, MailAclLogic::visibilityForIngest('personal', false));
	}

	public function testNormalizeAndSmtpMessageIdHelpers(): void
	{
		$this->assertSame('<x@y.z>', SharedMailboxSmtpClient::normalizeMessageId('x@y.z'));
		$id = SharedMailboxSmtpClient::generateMessageId('ae@Example.COM');
		$this->assertMatchesRegularExpression('/^<[a-f0-9]+\.[a-f0-9]+@example\.com>$/i', $id);
		$payload = SharedMailboxSmtpClient::buildDataPayload([
			'from' => 'ae@example.com',
			'to' => 'x@y.z',
			'subject' => 'S',
			'body' => 'B',
			'messageId' => $id,
		]);
		$this->assertStringContainsString('Message-ID: ' . $id, $payload);
	}

	/**
	 * @param array<string, mixed>|null $existing
	 * @param array<string, mixed> $updates
	 */
	private function service(int &$insertCalls, array &$updates, ?array $existing): MailboxService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-15 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
			public function andX(...$a)
			{
				return 'and';
			}
		};

		$metaMailbox = new class($expr) {
			private $expr;
			public function __construct($expr)
			{
				$this->expr = $expr;
			}
			public function select(...$a)
			{
				return $this;
			}
			public function from(...$a)
			{
				return $this;
			}
			public function where(...$a)
			{
				return $this;
			}
			public function andWhere(...$a)
			{
				return $this;
			}
			public function setMaxResults(...$a)
			{
				return $this;
			}
			public function expr()
			{
				return $this->expr;
			}
			public function createNamedParameter(...$a)
			{
				return 'p';
			}
			public function executeQuery()
			{
				return new class {
					public function fetch()
					{
						return ['kind' => 'personal', 'owner_user_id' => 'ae'];
					}
					public function closeCursor(): void
					{
					}
				};
			}
		};
		$metaFolder = new class($expr) {
			private $expr;
			public function __construct($expr)
			{
				$this->expr = $expr;
			}
			public function select(...$a)
			{
				return $this;
			}
			public function from(...$a)
			{
				return $this;
			}
			public function where(...$a)
			{
				return $this;
			}
			public function andWhere(...$a)
			{
				return $this;
			}
			public function setMaxResults(...$a)
			{
				return $this;
			}
			public function expr()
			{
				return $this->expr;
			}
			public function createNamedParameter(...$a)
			{
				return 'p';
			}
			public function executeQuery()
			{
				return new class {
					public function fetch()
					{
						return ['share_with_workspace' => 0];
					}
					public function closeCursor(): void
					{
					}
				};
			}
		};
		$findMid = new class($expr, $existing) {
			private $expr;
			private $existing;
			public function __construct($expr, $existing)
			{
				$this->expr = $expr;
				$this->existing = $existing;
			}
			public function select(...$a)
			{
				return $this;
			}
			public function from(...$a)
			{
				return $this;
			}
			public function where(...$a)
			{
				return $this;
			}
			public function andWhere(...$a)
			{
				return $this;
			}
			public function setMaxResults(...$a)
			{
				return $this;
			}
			public function expr()
			{
				return $this->expr;
			}
			public function createNamedParameter(...$a)
			{
				return 'p';
			}
			public function executeQuery()
			{
				$row = $this->existing;
				return new class($row) {
					private $row;
					private bool $done = false;
					public function __construct($row)
					{
						$this->row = $row;
					}
					public function fetch()
					{
						if ($this->done) {
							return false;
						}
						$this->done = true;
						return $this->row;
					}
					public function closeCursor(): void
					{
					}
				};
			}
		};
		$updateQb = new class($updates, $expr) {
			/** @var array<string, mixed> */
			private array $cap;
			private $expr;
			public function __construct(array &$cap, $expr)
			{
				$this->cap = &$cap;
				$this->expr = $expr;
			}
			public function update(...$a)
			{
				return $this;
			}
			public function set(string $col, $val)
			{
				$this->cap[$col] = $val;
				return $this;
			}
			public function where(...$a)
			{
				return $this;
			}
			public function andWhere(...$a)
			{
				return $this;
			}
			public function expr()
			{
				return $this->expr;
			}
			public function createNamedParameter($v, ...$a)
			{
				return $v;
			}
			public function executeStatement(): int
			{
				return 1;
			}
		};
		$insertQb = new class($insertCalls) {
			private int $calls;
			public function __construct(int &$calls)
			{
				$this->calls = &$calls;
			}
			public function insert(...$a)
			{
				return $this;
			}
			public function values(...$a)
			{
				return $this;
			}
			public function createNamedParameter(...$a)
			{
				return 'p';
			}
			public function executeStatement(): int
			{
				$this->calls++;
				return 1;
			}
		};

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$calls, $metaMailbox, $metaFolder, $findMid, $updateQb, $insertQb) {
				return match ($calls++) {
					0 => $metaMailbox,
					1 => $metaFolder,
					2 => $findMid,
					3 => $updateQb,
					default => $insertQb,
				};
			},
		);

		return new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$l10n,
			new HtmlSanitizeService(),
		);
	}
}
