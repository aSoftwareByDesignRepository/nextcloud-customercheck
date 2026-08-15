<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxAuthService;
use OCA\CustomerCheck\Service\MailboxService;
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
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * MH-07 — personal CRM outbound visibility follows Sent folder share flag.
 */
final class MailSendOutboundVisibilityTest extends TestCase
{
	public function testSharedSentFolderIndexesPersonalOutboundAsShared(): void
	{
		$captured = $this->indexOutbound(share: true, kind: 'personal');
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, $captured['visibility'] ?? null);
		$this->assertSame('ae', $captured['owner_user_id'] ?? null);
		$this->assertSame(55, $captured['folder_id'] ?? null);
	}

	public function testPrivateSentFolderIndexesPersonalOutboundAsPrivate(): void
	{
		$captured = $this->indexOutbound(share: false, kind: 'personal');
		$this->assertSame(MailAclLogic::VISIBILITY_PRIVATE, $captured['visibility'] ?? null);
	}

	public function testTeamOutboundAlwaysShared(): void
	{
		$captured = $this->indexOutbound(share: false, kind: 'team');
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, $captured['visibility'] ?? null);
		$this->assertArrayHasKey('owner_user_id', $captured);
		$this->assertNull($captured['owner_user_id']);
	}

	public function testResolveOutboundFolderMetaPrefersSentAndShareFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('resolveOutboundFolderMeta', $src);
		$this->assertStringContainsString('share_with_workspace', $src);
		$this->assertStringContainsString(
			"\$visibility = \$folderMeta['shareWithWorkspace']\n\t\t\t\t\t? MailAclLogic::VISIBILITY_SHARED\n\t\t\t\t\t: MailAclLogic::VISIBILITY_PRIVATE;",
			$src,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function indexOutbound(bool $share, string $kind): array
	{
		$captured = [];
		$expr = new class {
			public function eq(...$a)
			{
				return 'eq';
			}
		};

		$folderQb = new class($expr, $share) {
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
			public function orderBy(...$a)
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
					private int $i = 0;
					public function __construct(int $share)
					{
						$this->share = $share;
					}
					public function fetch()
					{
						if ($this->i === 0) {
							$this->i++;
							return [
								'id' => 40,
								'folder_path' => 'INBOX',
								'share_with_workspace' => 0,
							];
						}
						if ($this->i === 1) {
							$this->i++;
							return [
								'id' => 55,
								'folder_path' => 'Sent',
								'share_with_workspace' => $this->share,
							];
						}
						return false;
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

		$outboundMissQb = new class {
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
				return new class {
					public function eq(...$a)
					{
						return 'eq';
					}
				};
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
						return false;
					}
					public function closeCursor(): void
					{
					}
				};
			}
		};

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls, $outboundMissQb, $folderQb, $insertQb) {
			// Zeus: outboundExistsForIdempotency → resolveOutboundFolderMeta → insert
			return match ($calls++) {
				0 => $outboundMissQb,
				1 => $folderQb,
				2 => $insertQb,
				default => throw new \RuntimeException('unexpected qb #' . $calls),
			};
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new MailSendService(
			$db,
			$this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(FilesAccessService::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
			new HtmlSanitizeService(),
			$this->createMock(MailboxAuthService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(WorkspaceService::class),
		);

		$ref = new \ReflectionClass($svc);
		$method = $ref->getMethod('indexOutboundMessage');
		$method->setAccessible(true);
		$method->invoke(
			$svc,
			1,
			2,
			[
				'kind' => $kind,
				'owner_user_id' => 'ae',
			],
			'ae@example.com',
			'ada@example.com',
			[],
			'Hello',
			'Body plain',
			'<p>Body</p>',
			[],
			false,
			42,
			'idem-vis-1',
			'ae',
			'2026-08-15 12:00:00',
		);

		return $captured;
	}
}
