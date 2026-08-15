<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behavioral FAC closeout for CUC-PERSONAL-MAIL-1 (1.6.98) — ingest, ACL, serialize, Review wipe.
 */
final class PersonalMailFacCloseoutTest extends TestCase
{
	public function testFac05PersonalSharedIngestSetsCompanyId(): void
	{
		$captured = [];
		$svc = $this->ingestService($captured, share: true);
		$match = $svc->ingestMessage(
			1,
			10,
			20,
			[
				'uid' => '1001',
				'from' => 'ada@acme.test',
				'to' => ['ae@example.com'],
				'subject' => 'Quote',
				'preview' => 'Hello',
				'bodyHtml' => '<p>Hello <script>x()</script></p>',
			],
			[
				['companyId' => 42, 'email' => 'ada@acme.test'],
			],
			[],
		);
		$this->assertSame(42, $match['companyId']);
		$this->assertSame('person_email', $match['how']);
		$this->assertSame(42, $captured['company_id'] ?? null);
		$this->assertSame('matched', $captured['triage_status'] ?? null);
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, $captured['visibility'] ?? null);
		$this->assertSame('ae', $captured['owner_user_id'] ?? null);
		$html = (string)($captured['body_html'] ?? '');
		$this->assertStringContainsString('<p>Hello', $html);
		$this->assertStringNotContainsString('<script', $html);
	}

	public function testFac06AmbiguousPersonalSharedStaysOpenUnmatched(): void
	{
		$captured = [];
		$svc = $this->ingestService($captured, share: true);
		$match = $svc->ingestMessage(
			1,
			10,
			20,
			[
				'uid' => '1002',
				'from' => 'dup@acme.test',
				'to' => ['ae@example.com'],
				'subject' => 'Ambiguous',
				'preview' => 'x',
			],
			[
				['companyId' => 1, 'email' => 'dup@acme.test'],
				['companyId' => 2, 'email' => 'dup@acme.test'],
			],
			[],
		);
		$this->assertNull($match['companyId']);
		$this->assertArrayHasKey('company_id', $captured);
		$this->assertNull($captured['company_id']);
		$this->assertSame('open', $captured['triage_status'] ?? null);
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, $captured['visibility'] ?? null);
		$this->assertTrue(ReviewService::isMailSourceKind('personal_mail_shared_unmatched'));
	}

	public function testFac06PrivatePersonalNeverSharedVisibility(): void
	{
		$captured = [];
		$svc = $this->ingestService($captured, share: false);
		$svc->ingestMessage(
			1,
			10,
			20,
			[
				'uid' => '1003',
				'from' => 'ada@acme.test',
				'to' => ['ae@example.com'],
				'subject' => 'Private',
				'preview' => 'secret',
			],
			[
				['companyId' => 42, 'email' => 'ada@acme.test'],
			],
			[],
		);
		$this->assertSame(MailAclLogic::VISIBILITY_PRIVATE, $captured['visibility'] ?? null);
		$this->assertSame(42, $captured['company_id'] ?? null);
	}

	public function testNn02SerializeAccountNeverLeaksSecrets(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new PersonalMailService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			$this->createMock(WorkspaceService::class),
			$this->createMock(\OCA\CustomerCheck\Service\MailboxSyncService::class),
			new HtmlSanitizeService(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(RateLimitService::class),
		);
		$ref = new \ReflectionClass($svc);
		$method = $ref->getMethod('serializeAccount');
		$method->setAccessible(true);
		$out = $method->invoke($svc, [
			'id' => 9,
			'owner_user_id' => 'ae',
			'auth_kind' => 'imap',
			'label' => 'Alex',
			'email_address' => 'ae@example.com',
			'imap_host' => 'imap.example',
			'imap_port' => 993,
			'imap_enc' => 'ssl',
			'smtp_host' => 'smtp.example',
			'smtp_port' => 465,
			'smtp_enc' => 'ssl',
			'username' => 'ae@example.com',
			'status' => 'ok',
			'auth_fail_count' => 0,
			'secret_enc' => 'CIPHERTEXT-SHOULD-NOT-LEAK',
			'token_enc' => 'TOKEN-SHOULD-NOT-LEAK',
			'refresh_enc' => 'REFRESH-SHOULD-NOT-LEAK',
			'signature_html' => '<p>Sig</p>',
			'signature_plain' => 'Sig',
		]);
		$json = json_encode($out, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('CIPHERTEXT', $json);
		$this->assertStringNotContainsString('TOKEN-SHOULD', $json);
		$this->assertStringNotContainsString('REFRESH-SHOULD', $json);
		$this->assertStringNotContainsString('secret_enc', $json);
		$this->assertStringNotContainsString('token_enc', $json);
		$this->assertStringNotContainsString('refresh_enc', $json);
		$this->assertStringNotContainsString('"password"', $json);
		$this->assertTrue($out['hasSecret']);
		$this->assertTrue($out['hasSignature']);
	}

	public function testFac18AssertCanReadPrivateDeniesTeammate(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$qb = $this->selectQbReturning([
			'id' => 5,
			'visibility' => MailAclLogic::VISIBILITY_PRIVATE,
			'owner_user_id' => 'ae',
			'company_id' => 9,
			'mailbox_kind' => MailAclLogic::KIND_PERSONAL,
		]);
		$db->method('getQueryBuilder')->willReturn($qb);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new PersonalMailService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			$this->createMock(WorkspaceService::class),
			$this->createMock(\OCA\CustomerCheck\Service\MailboxSyncService::class),
			new HtmlSanitizeService(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(RateLimitService::class),
		);
		$svc->assertCanReadMessage(1, 5, 'ae', true, true);
		$this->expectException(NotFoundException::class);
		$svc->assertCanReadMessage(1, 5, 'teammate', true, true);
	}

	public function testReviewPurgeIncludesPersonalUnmatchedNeedle(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString("['shared_mail', 'personal_mail_shared_unmatched']", $src);
		$this->assertStringNotContainsString(
			"source_kind', \$qb->createNamedParameter('shared_mail')",
			$src,
		);
	}

	public function testShareExistingAckRequiredConstant(): void
	{
		$this->assertSame('MAIL_SHARE_EXISTING_ACK_REQUIRED', PersonalMailService::ERR_MAIL_SHARE_EXISTING);
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('shareExistingAck', $src);
		$this->assertStringContainsString('countPrivateMessagesForNewlySharedFolders', $src);
	}

	public function testShareExistingAckRejectsWithoutConfirmation(): void
	{
		$time = $this->createMock(ITimeFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $n): string => $n === 1 ? $s : $p);

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function andX(...$a) { return 'and'; }
			public function in(...$a) { return 'in'; }
		};

		$ownedQb = new class($expr) {
			private $expr;
			public function __construct($expr) { $this->expr = $expr; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				return new class {
					public function fetch() { return ['id' => 10]; }
					public function closeCursor(): void {}
				};
			}
		};

		$fldQb = new class($expr) {
			private $expr;
			public function __construct($expr) { $this->expr = $expr; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				return new class {
					private int $n = 0;
					public function fetch() {
						if ($this->n++ === 0) {
							return ['id' => 3, 'folder_path' => 'INBOX', 'share_with_workspace' => 0];
						}
						return false;
					}
					public function closeCursor(): void {}
				};
			}
		};

		$msgCountQb = new class($expr) {
			private $expr;
			public function __construct($expr) { $this->expr = $expr; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function func() {
				return new class {
					public function count(...$a) { return 'c'; }
				};
			}
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				return new class {
					public function fetch() { return ['c' => 2]; }
					public function closeCursor(): void {}
				};
			}
		};

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls, $ownedQb, $fldQb, $msgCountQb) {
			return match ($calls++) {
				0 => $ownedQb, // requireOwnedPersonal
				1 => $fldQb, // countPrivate… folders
				2 => $msgCountQb, // count private msgs
				default => throw new \RuntimeException('unexpected qb #' . $calls),
			};
		});

		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('getSettings')->willReturn(['personalMailEnabled' => true]);
		$locks = $this->createMock(ILockingProvider::class);

		$svc = new PersonalMailService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$l10n,
			$ws,
			$this->createMock(\OCA\CustomerCheck\Service\MailboxSyncService::class),
			new HtmlSanitizeService(),
			$this->createMock(LoggerInterface::class),
			$locks,
			$this->createMock(RateLimitService::class),
		);

		try {
			$svc->saveFolders(1, 10, 'ae', [
				[
					'path' => 'INBOX',
					'syncEnabled' => true,
					'shareWithWorkspace' => true,
					'shareAck' => true,
					'shareConfirm' => 'SHARE',
				],
			]);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(PersonalMailService::ERR_MAIL_SHARE_EXISTING, $e->getErrorCode());
			$this->assertArrayHasKey('shareExistingAck', $e->getFields());
		}
	}

	public function testFac15Fac17DeleteRemovesMailboxRowContainingSecrets(): void
	{
		$deleted = [];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$expr = new class {
			public function eq(...$a) { return 'eq'; }
			public function andX(...$a) { return 'and'; }
			public function in(...$a) { return 'in'; }
		};

		$checkQb = new class($expr) {
			private $expr;
			public function __construct($expr) { $this->expr = $expr; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				return new class {
					public function fetch() { return ['id' => 7]; }
					public function closeCursor(): void {}
				};
			}
		};

		$listIdsQb = new class($expr) {
			private $expr;
			public function __construct($expr) { $this->expr = $expr; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				return new class {
					private int $n = 0;
					public function fetch() {
						if ($this->n++ === 0) {
							return ['id' => 55];
						}
						return false;
					}
					public function closeCursor(): void {}
				};
			}
		};

		$purgeSelectQb = new class($expr) {
			private $expr;
			public function __construct($expr) { $this->expr = $expr; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				return new class {
					public function fetch() { return false; }
					public function closeCursor(): void {}
				};
			}
		};

		$deleteQb = static function (string $table) use (&$deleted, $expr) {
			return new class($table, $deleted, $expr) {
				private string $table;
				/** @var list<string> */
				private array $deleted;
				private $expr;
				public function __construct(string $table, array &$deleted, $expr)
				{
					$this->table = $table;
					$this->deleted = &$deleted;
					$this->expr = $expr;
				}
				public function delete(string $t) {
					$this->deleted[] = $t;
					return $this;
				}
				public function where(...$a) { return $this; }
				public function andWhere(...$a) { return $this; }
				public function expr() { return $this->expr; }
				public function createNamedParameter(...$a) { return 'p'; }
				public function executeStatement(): int { return 1; }
			};
		};

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls, $checkQb, $listIdsQb, $purgeSelectQb, $deleteQb) {
			return match ($calls++) {
				0 => $checkQb,
				1 => $listIdsQb,
				2 => $purgeSelectQb,
				3 => $deleteQb('crm_mail_msg'),
				4 => $deleteQb('crm_mail_dft'),
				5 => $deleteQb('crm_mail_fld'),
				6 => $deleteQb('crm_mailbox'),
				default => throw new \RuntimeException('unexpected qb #' . $calls),
			};
		});

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			new HtmlSanitizeService(),
		);
		$svc->delete(1, 7);
		$this->assertSame(['crm_mail_msg', 'crm_mail_dft', 'crm_mail_fld', 'crm_mailbox'], $deleted);
	}

	public function testOauthBeginRequiresDsgvoAndCompleteAssertsEmail(): void
	{
		$oauth = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PersonalMailOAuthService.php');
		$this->assertStringContainsString("empty(\$data['dsgvoAck'])", $oauth);
		$this->assertStringContainsString('assertEmailAddressAvailable', $oauth);
		$this->assertStringContainsString('withEmailConnectLock', $oauth);
		$pm = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('withEmailConnectLock', $pm);
		$this->assertStringContainsString('crm_pm_email_', $pm);
		$this->assertSame(204800, \OCA\CustomerCheck\Constants::MAIL_BODY_PLAIN_MAX_BYTES);
		$this->assertSame(524288, \OCA\CustomerCheck\Constants::MAIL_BODY_HTML_MAX_BYTES);
	}

	public function testPersonalIngestIgnoresForwardHints(): void
	{
		$captured = [];
		$svc = $this->ingestService($captured, share: true);
		$svc->ingestMessage(
			1,
			10,
			20,
			[
				'uid' => 'fwd-1',
				'from' => 'internal@team.test',
				'to' => ['ae@example.com'],
				'subject' => 'Fwd: Quote',
				'preview' => 'see below',
				'originalFromHints' => ['ada@acme.test'],
			],
			[
				['companyId' => 42, 'email' => 'ada@acme.test'],
			],
			[],
		);
		// Without forward hints, team envelope From does not match person email → unmatched open.
		$this->assertNull($captured['company_id'] ?? null);
		$this->assertSame('open', $captured['triage_status'] ?? null);
	}

	public function testNormativeMailErrorCodesExist(): void
	{
		$this->assertSame('MAIL_BODY_TOO_LARGE', \OCA\CustomerCheck\Constants::ERR_MAIL_BODY_TOO_LARGE);
		$this->assertSame('MAIL_ATTACH_LIMIT', \OCA\CustomerCheck\Constants::ERR_MAIL_ATTACH_LIMIT);
		$this->assertSame('MAIL_ATTACH_FORBIDDEN', \OCA\CustomerCheck\Constants::ERR_MAIL_ATTACH_FORBIDDEN);
		$this->assertSame('MAIL_SEND_FORBIDDEN', \OCA\CustomerCheck\Constants::ERR_MAIL_SEND_FORBIDDEN);
		$this->assertSame(
			\OCA\CustomerCheck\Constants::ERR_MAIL_SEND_FORBIDDEN,
			\OCA\CustomerCheck\Constants::ERR_MAIL_SEND_DENIED,
		);
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
			public function eq(...$a) { return 'eq'; }
			public function andX(...$a) { return 'and'; }
		};

		$metaMailbox = new class($expr) {
			private $expr;
			public function __construct($expr) { $this->expr = $expr; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				return new class {
					public function fetch() {
						return ['kind' => 'personal', 'owner_user_id' => 'ae'];
					}
					public function closeCursor(): void {}
				};
			}
		};
		$metaFolder = new class($expr, $share) {
			private $expr;
			private bool $share;
			public function __construct($expr, bool $share) { $this->expr = $expr; $this->share = $share; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() { return $this->expr; }
			public function createNamedParameter(...$a) { return 'p'; }
			public function executeQuery() {
				$share = $this->share ? 1 : 0;
				return new class($share) {
					private int $share;
					public function __construct(int $share) { $this->share = $share; }
					public function fetch() {
						return ['share_with_workspace' => $this->share];
					}
					public function closeCursor(): void {}
				};
			}
		};
		$insertQb = new class($captured) {
			/** @var array<string, mixed> */
			private array $cap;
			public function __construct(array &$cap) { $this->cap = &$cap; }
			public function insert(...$a) { return $this; }
			public function values(array $values) {
				foreach ($values as $k => $v) {
					// createNamedParameter returns the raw value in our stub below.
					$this->cap[$k] = $v;
				}
				return $this;
			}
			public function createNamedParameter($v, ...$a) { return $v; }
			public function executeStatement(): int { return 1; }
		};

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('lastInsertId')->willReturn(55);
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

	/** @param array<string, mixed>|false $row */
	private function selectQbReturning(array|false $row): object
	{
		return new class($row) {
			private $row;
			public function __construct($row) { $this->row = $row; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function leftJoin(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
				};
			}
			public function createNamedParameter(...$a) { return '?'; }
			public function executeQuery() {
				$row = $this->row;
				return new class($row) {
					private $row;
					private bool $done = false;
					public function __construct($row) { $this->row = $row; }
					public function fetch() {
						if ($this->done) {
							return false;
						}
						$this->done = true;
						return $this->row;
					}
					public function closeCursor(): void {}
				};
			}
		};
	}
}
