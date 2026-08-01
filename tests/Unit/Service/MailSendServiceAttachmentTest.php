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
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Shared-mailbox Files attachments — actor ACL before SMTP / IMailer.
 */
final class MailSendServiceAttachmentTest extends TestCase
{
	public function testAttachmentDeniedBeforeRateLimit(): void
	{
		$files = $this->createMock(FilesAccessService::class);
		$files->expects($this->once())
			->method('loadMailAttachments')
			->with('ae', [1])
			->willThrowException(new ValidationException(
				Constants::ERR_ATTACHMENT_TOO_LARGE,
				'too large',
			));

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailSendService(
			$this->createMock(IDBConnection::class),
			$this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$rate,
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(MailboxService::class),
			$files,
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
		);

		try {
			$svc->send(1, [
				'mailboxId' => 2,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'x',
				'attachmentFileIds' => [1],
				'idempotencyKey' => 'attach-too-big',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_ATTACHMENT_TOO_LARGE, $e->getErrorCode());
		}
	}

	public function testIMailerPathAttachesResolvedFiles(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$mailboxQb = new class {
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
		$db->method('getQueryBuilder')->willReturn($mailboxQb);

		$files = $this->createMock(FilesAccessService::class);
		$files->method('loadMailAttachments')->willReturn([[
			'fileId' => 9,
			'filename' => 'q.pdf',
			'mime' => 'application/pdf',
			'data' => '%PDF',
			'size' => 4,
		]]);

		$attachment = $this->createMock(IAttachment::class);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('attach')->with($attachment)->willReturnSelf();
		$message->method('setFrom')->willReturnSelf();
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('setPlainBody')->willReturnSelf();

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())
			->method('createAttachment')
			->with('%PDF', 'q.pdf', 'application/pdf')
			->willReturn($attachment);
		$mailer->expects($this->once())->method('send')->with($message);

		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);

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
			$files,
			$this->createMock(ILockingProvider::class),
			$time,
			$this->createMock(IURLGenerator::class),
			$l10n,
		);

		$result = $svc->send(1, [
			'mailboxId' => 2,
			'to' => 'ada@example.com',
			'subject' => 'Hi',
			'body' => 'Body',
			'attachmentFileIds' => [9],
			'idempotencyKey' => 'attach-ok',
		], 'ae', true);

		$this->assertTrue($result['sent']);
		$this->assertSame(1, $result['attachmentCount']);
	}
}
