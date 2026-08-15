<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * MH-05 / Q7 — sync ingest must persist capped full body, not headers-only.
 */
final class PersonalMailMh05BodyPersistTest extends TestCase
{
	public function testIngestPersistsBodyTextAndSanitizedHtml(): void
	{
		$captured = [];
		$svc = $this->ingestService($captured, share: true);
		$longPlain = str_repeat('Body line. ', 40);
		$svc->ingestMessage(
			1,
			10,
			20,
			[
				'uid' => 'body-1',
				'from' => 'ada@acme.test',
				'to' => ['ae@example.com'],
				'subject' => 'Full body',
				'bodyText' => $longPlain,
				'bodyHtml' => '<p>Hello <script>evil()</script> world</p>',
			],
			[['companyId' => 42, 'email' => 'ada@acme.test']],
			[],
		);

		$this->assertSame($longPlain, $captured['body_text'] ?? null);
		$this->assertNotSame('', (string)($captured['body_text'] ?? ''));
		$html = (string)($captured['body_html'] ?? '');
		$this->assertStringContainsString('<p>Hello', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, $captured['visibility'] ?? null);
	}

	public function testIngestCapsBodyTextAtPlainMax(): void
	{
		$captured = [];
		$svc = $this->ingestService($captured, share: false);
		$oversized = str_repeat('A', Constants::MAIL_BODY_PLAIN_MAX_BYTES + 5000);
		$svc->ingestMessage(
			1,
			10,
			20,
			[
				'uid' => 'body-cap',
				'from' => 'ada@acme.test',
				'to' => ['ae@example.com'],
				'subject' => 'Huge',
				'bodyText' => $oversized,
			],
			[],
			[],
		);
		$stored = (string)($captured['body_text'] ?? '');
		$this->assertLessThanOrEqual(Constants::MAIL_BODY_PLAIN_MAX_BYTES, mb_strlen($stored));
		$this->assertSame(Constants::MAIL_BODY_PLAIN_MAX_BYTES, mb_strlen($stored));
	}

	public function testSyncPathAssignsBodyTextNeedleAndFetchCap(): void
	{
		$sync = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('MAIL_SYNC_BODY_FETCH_CAP', $sync);
		$this->assertStringContainsString("\$msg['bodyText'] = \$plainPart;", $sync);
		$this->assertStringContainsString("\$msg['bodyHtml'] = \$htmlPart;", $sync);
		$this->assertStringContainsString('peekBodiesForUids', $sync);
		$this->assertStringContainsString("\$msg['bodyText'] = \$bodies['plain'];", $sync);
		$this->assertStringContainsString('fetchPhpImapBodiesCapped', $sync);
		$this->assertStringContainsString('MAIL_BODY_PLAIN_MAX_BYTES', $sync);
		$this->assertSame(40, Constants::MAIL_SYNC_BODY_FETCH_CAP);

		$probe = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Support/ImapLiveProbe.php');
		$this->assertStringContainsString(
			'min(Constants::MAIL_BODY_PLAIN_MAX_BYTES, $maxBytes)',
			$probe,
		);
		// Must not hard-cap peeks at the 8 KiB forward-probe size anymore.
		$this->assertStringNotContainsString(
			'min(Constants::MAIL_FORWARD_BODY_PEEK_BYTES, $maxBytes)',
			$probe,
		);
	}

	public function testCacheMessagePreviewBackfillsEmptyBodyText(): void
	{
		$updated = [];
		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};
		$selectQb = new class($expr) {
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
						return ['body_text' => ''];
					}
					public function closeCursor(): void
					{
					}
				};
			}
		};
		$updateQb = new class($updated, $expr) {
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

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls, $updateQb, $selectQb) {
			return match ($calls++) {
				0 => $updateQb,
				1 => $selectQb,
				default => throw new \RuntimeException('unexpected qb'),
			};
		});

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
			new HtmlSanitizeService(),
		);
		$svc->cacheMessagePreview(1, 99, "Hello preview body\nsecond line");
		$this->assertArrayHasKey('preview', $updated);
		$this->assertArrayHasKey('body_text', $updated);
		$this->assertStringContainsString('Hello preview body', (string)$updated['body_text']);
	}

	/**
	 * @param array<string, mixed> $captured
	 */
	private function ingestService(array &$captured, bool $share): MailboxService
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
		$metaFolder = new class($expr, $share) {
			private $expr;
			private bool $share;
			public function __construct($expr, bool $share)
			{
				$this->expr = $expr;
				$this->share = $share;
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
				$share = $this->share ? 1 : 0;
				return new class($share) {
					private int $share;
					public function __construct(int $share)
					{
						$this->share = $share;
					}
					public function fetch()
					{
						return ['share_with_workspace' => $this->share];
					}
					public function closeCursor(): void
					{
					}
				};
			}
		};
		$insertQb = new class($captured) {
			/** @var array<string, mixed> */
			private array $cap;
			public function __construct(array &$cap)
			{
				$this->cap = &$cap;
			}
			public function insert(...$a)
			{
				return $this;
			}
			public function values(array $values)
			{
				foreach ($values as $k => $v) {
					$this->cap[$k] = $v;
				}
				return $this;
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

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls, $metaMailbox, $metaFolder, $insertQb) {
			return match ($calls++) {
				0 => $metaMailbox,
				1 => $metaFolder,
				2 => $insertQb,
				default => throw new \RuntimeException('unexpected qb #' . $calls),
			};
		});

		return new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$l10n,
			new HtmlSanitizeService(),
		);
	}
}
