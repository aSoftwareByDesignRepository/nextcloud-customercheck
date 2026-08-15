<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
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
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed SMTP + claim-before-send idempotency (§14.3).
 */
final class MailSendServiceSmtpGuardTest extends TestCase
{
	public function testConfiguredSmtpWithoutSecretFailsClosedWithoutIMailer(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		$mailboxQb = $this->selectQbReturning([
			'id' => 9,
			'workspace_id' => 1,
			'kind' => 'team',
			'email_address' => 'shared@example.com',
			'label' => 'Shared',
			'smtp_host' => 'smtp.example.com',
			'smtp_port' => 587,
			'smtp_enc' => 'tls',
			'username' => 'shared',
			'secret_enc' => '',
			'signature_html' => '',
			'signature_plain' => '',
		]);
		$db->method('getQueryBuilder')->willReturn($mailboxQb);

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');
		$mailer->expects($this->never())->method('send');

		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);

		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('disclosure');

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
			$this->createMock(ILockingProvider::class),
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
				'mailboxId' => 9,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
				'idempotencyKey' => 'smtp-missing-secret',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('smtp', $e->getFields());
			$this->assertStringContainsString('password is missing', $e->getMessage());
		}
	}

	public function testDecryptFailureFailsClosedWithoutIMailer(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => $t === 'crm_mail_dft');

		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls) {
			$calls++;
			// 1) loadMailbox (before lock)
			if ($calls === 1) {
				return $this->selectQbReturning([
					'id' => 9,
					'workspace_id' => 1,
					'kind' => 'team',
					'email_address' => 'shared@example.com',
					'label' => 'Shared',
					'smtp_host' => 'smtp.example.com',
					'smtp_port' => 587,
					'smtp_enc' => 'tls',
					'username' => 'shared',
					'secret_enc' => 'cipher',
					'signature_html' => '',
					'signature_plain' => '',
				]);
			}
			// 2) findByIdempotency → miss
			if ($calls === 2) {
				return $this->selectQbReturning(false);
			}
			// 3) claim insert
			if ($calls === 3) {
				return $this->insertQb();
			}
			// 4) delete claim after SMTP fail-closed
			if ($calls === 4) {
				return $this->deleteQb();
			}
			// 5) recovery draft insert
			return $this->insertQb();
		});
		$db->method('lastInsertId')->willReturn(55);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new \RuntimeException('bad key'));

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');

		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('disclosure');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01 12:00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);

		$svc = new MailSendService(
			$db,
			$mailer,
			$crypto,
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
				'mailboxId' => 9,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
				'idempotencyKey' => 'smtp-decrypt-fail',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('smtp', $e->getFields());
			$this->assertStringContainsString('decrypted', $e->getMessage());
		}
	}

	public function testEmptyIdempotencyKeyRejectedBeforeSmtp(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailSendService(
			$db,
			$mailer,
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
				'mailboxId' => 9,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('idempotencyKey', $e->getFields());
		}
	}

	public function testIdempotentClaimHappensBeforeDispatch(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$sequence = [];
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$sequence) {
			$sequence[] = 'qb';
			// 1) loadMailbox
			// 2) findByIdempotency → miss
			// 3) outboundExists → miss
			// 4) workspaceName
			// 5) claim insert
			// 6) delete claim after SMTP fail-closed
			// 7) recovery draft insert
			static $n = 0;
			$n++;
			if ($n === 1) {
				return $this->selectQbReturning([
					'id' => 9,
					'workspace_id' => 3,
					'kind' => 'team',
					'email_address' => 'shared@example.com',
					'label' => 'Shared',
					'smtp_host' => 'smtp.example.com',
					'secret_enc' => '',
					'username' => 'u',
					'signature_html' => '',
					'signature_plain' => '',
				]);
			}
			if ($n === 2 || $n === 3) {
				return $this->selectQbReturning(false);
			}
			if ($n === 4) {
				return $this->selectQbReturning(['name' => 'Book']);
			}
			if ($n === 5) {
				return $this->insertQb();
			}
			if ($n === 6) {
				return $this->deleteQb();
			}
			return $this->insertQb();
		});
		$db->method('lastInsertId')->willReturn(11);

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->exactly(2))->method('allow')->willReturn(true);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');

		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('disclosure');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01 12:00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);

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

		try {
			$svc->send(3, [
				'mailboxId' => 9,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
				'idempotencyKey' => 'claim-key',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('smtp', $e->getFields());
		}
		$this->assertSame(7, count($sequence));
	}

	/** @param array<string, mixed>|false $row */
	private function selectQbReturning(array|false $row): object
	{
		return new class ($row) {
			public function __construct(private array|false $row) {}
			public function select(...$a): self { return $this; }
			public function from(...$a): self { return $this; }
			public function where(...$a): self { return $this; }
			public function andWhere(...$a): self { return $this; }
			public function setMaxResults(...$a): self { return $this; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
				};
			}
			public function createNamedParameter(...$a): string { return '?'; }
			public function executeQuery(): object
			{
				$row = $this->row;
				return new class ($row) {
					public function __construct(private array|false $row) {}
					public function fetch(): array|false { return $this->row; }
					public function closeCursor(): void {}
				};
			}
		};
	}

	private function insertQb(): object
	{
		return new class {
			public function insert(...$a): self { return $this; }
			public function values(...$a): self { return $this; }
			public function createNamedParameter(...$a): string { return '?'; }
			public function executeStatement(): int { return 1; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
				};
			}
		};
	}

	private function deleteQb(): object
	{
		return new class {
			public function delete(...$a): self { return $this; }
			public function where(...$a): self { return $this; }
			public function createNamedParameter(...$a): string { return '?'; }
			public function executeStatement(): int { return 1; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
				};
			}
		};
	}
}
