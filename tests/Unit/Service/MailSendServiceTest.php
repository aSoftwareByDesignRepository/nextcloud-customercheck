<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
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
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Shared SMTP send — replay before rate-limit (§14.3 / §18.4).
 */
final class MailSendServiceTest extends TestCase
{
	public function testDeniedWithoutCapability(): void
	{
		$svc = $this->service();
		$this->expectException(ValidationException::class);
		$svc->send(1, [
			'mailboxId' => 1,
			'to' => 'ada@example.com',
			'subject' => 'Hi',
			'body' => 'Body',
			'idempotencyKey' => 'denied-key',
		], 'ae', false);
	}

	public function testReplaySkipsRateLimitAndDoesNotResend(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => $t === 'crm_mail_dft');

		$selectQb = new class {
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
				return new class {
					private bool $done = false;
					public function fetch(): array|false
					{
						if ($this->done) {
							return false;
						}
						$this->done = true;
						return [
							'to_addrs' => 'ada@example.com',
							'subject' => 'Hi',
							'updated_at' => '2026-08-01 10:00:00',
						];
					}
					public function closeCursor(): void {}
				};
			}
		};
		$db->method('getQueryBuilder')->willReturn($selectQb);

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with('crm_mail_send/3/key-1', ILockingProvider::LOCK_EXCLUSIVE);
		$locking->expects($this->once())->method('releaseLock');

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');

		$svc = $this->service($db, $rate, $locking, $mailer);
		$result = $svc->send(3, [
			'mailboxId' => 9,
			'to' => 'ada@example.com',
			'subject' => 'Hi',
			'body' => 'Body',
			'idempotencyKey' => 'key-1',
		], 'ae', true);

		$this->assertTrue($result['sent']);
		$this->assertTrue($result['replay']);
		$this->assertSame('ada@example.com', $result['to']);
	}

	public function testEmptyIdempotencyKeyRejectedBeforeRateLimit(): void
	{
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = $this->service(null, $rate);
		try {
			$svc->send(1, [
				'mailboxId' => 1,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'x',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('idempotencyKey', $e->getFields());
		}
	}

	public function testRateLimitCheckedForFreshIdempotentSend(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$selectQb = new class {
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
				return new class {
					public function fetch(): array|false { return false; }
					public function closeCursor(): void {}
				};
			}
		};
		$db->method('getQueryBuilder')->willReturn($selectQb);

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->once())
			->method('allow')
			->with('crm_mail_user:ae', Constants::MAIL_SEND_USER_LIMIT, Constants::MAIL_SEND_USER_WINDOW)
			->willReturn(false);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$svc = $this->service($db, $rate, $locking);
		try {
			$svc->send(3, [
				'mailboxId' => 9,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'x',
				'idempotencyKey' => 'fresh-key',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_RATE_LIMITED, $e->getErrorCode());
		}
	}

	private function service(
		?IDBConnection $db = null,
		?RateLimitService $rate = null,
		?ILockingProvider $locking = null,
		?IMailer $mailer = null,
	): MailSendService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return new MailSendService(
			$db ?? $this->createMock(IDBConnection::class),
			$mailer ?? $this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$rate ?? $this->createMock(RateLimitService::class),
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(MailboxService::class),
			$this->createMock(FilesAccessService::class),
			$locking ?? $this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
		);
	}
}
