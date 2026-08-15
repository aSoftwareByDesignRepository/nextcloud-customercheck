<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\HtmlSanitizeService;
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
use OCP\Mail\IMessage;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behavioral FAC coverage for send path (FAC-07/08/10/11/13) — not string contracts.
 */
final class MailSendServiceFacBehavioralTest extends TestCase
{
	public function testFac08ListSendAccountsOmitsTeamWithoutMailSend(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->expects($this->never())->method('listMailboxes');

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		$svc = $this->service($db, $mailboxes);
		$out = $svc->listSendAccounts(1, 'ae', false);
		$this->assertSame([], $out);
	}

	public function testFac08ListSendAccountsIncludesTeamWithMailSend(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('listMailboxes')->willReturn([[
			'id' => 5,
			'label' => 'Support',
			'email_address' => 'support@example.com',
			'signature_html' => '',
			'signature_plain' => 'Team Sig',
		]]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		$svc = $this->service($db, $mailboxes);
		$out = $svc->listSendAccounts(1, 'ae', true);
		$this->assertCount(1, $out);
		$this->assertSame('team', $out[0]['kind']);
		$this->assertSame('support@example.com', $out[0]['emailAddress']);
		$this->assertTrue($out[0]['hasSignature']);
		$this->assertSame('Team Sig', $out[0]['signaturePlain']);
	}

	public function testFac07FromMatchesMailboxEmailAndFac10SignatureToggle(): void
	{
		$captured = [];
		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturnCallback(function ($from) use (&$captured, $message) {
			$captured['from'] = $from;
			return $message;
		});
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('setPlainBody')->willReturnCallback(function ($body) use (&$captured, $message) {
			$captured['body'] = $body;
			return $message;
		});
		$message->method('setHtmlBody')->willReturnCallback(function ($html) use (&$captured, $message) {
			$captured['html'] = $html;
			return $message;
		});

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message);

		$db = $this->mailboxDb([
			'id' => 2,
			'workspace_id' => 1,
			'kind' => 'personal',
			'owner_user_id' => 'ae',
			'email_address' => 'ae@example.com',
			'label' => 'Alex',
			'smtp_host' => '',
			'username' => '',
			'secret_enc' => '',
			'signature_html' => '<p>SIG-HTML</p>',
			'signature_plain' => 'SIG-PLAIN',
		]);

		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('DISCLOSURE');

		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);

		$svc = $this->service($db, $mailboxes, $mailer, $rate);
		$result = $svc->send(1, [
			'mailboxId' => 2,
			'to' => 'ada@example.com',
			'subject' => 'Hi',
			'body' => 'Hello',
			'bodyHtml' => '<p>Hello <strong>bold</strong></p>',
			'includeSignature' => true,
			'idempotencyKey' => 'fac-sig-on',
		], 'ae', true);

		$this->assertSame('ae@example.com', $result['from']);
		$this->assertSame(['ae@example.com' => 'Alex'], $captured['from']);
		$this->assertStringContainsString('SIG-PLAIN', $captured['body']);
		$this->assertStringContainsString('SIG-HTML', $captured['html'] ?? '');
		$this->assertStringContainsString('<strong>bold</strong>', $captured['html'] ?? '');
		$this->assertTrue($result['signatureIncluded']);

		// FAC-10 off: rebuild with includeSignature false
		$captured = [];
		$mailer2 = $this->createMock(IMailer::class);
		$message2 = $this->createMock(IMessage::class);
		$message2->method('setFrom')->willReturnSelf();
		$message2->method('setTo')->willReturnSelf();
		$message2->method('setSubject')->willReturnSelf();
		$message2->method('setPlainBody')->willReturnCallback(function ($body) use (&$captured, $message2) {
			$captured['body'] = $body;
			return $message2;
		});
		$message2->method('setHtmlBody')->willReturnCallback(function ($html) use (&$captured, $message2) {
			$captured['html'] = $html;
			return $message2;
		});
		$mailer2->method('createMessage')->willReturn($message2);
		$mailer2->method('send')->willReturn([]);

		$svc2 = $this->service($db, $mailboxes, $mailer2, $rate);
		$result2 = $svc2->send(1, [
			'mailboxId' => 2,
			'to' => 'ada@example.com',
			'subject' => 'Hi',
			'body' => 'Hello',
			'bodyHtml' => '<p>Hello</p>',
			'includeSignature' => false,
			'idempotencyKey' => 'fac-sig-off',
		], 'ae', true);

		$this->assertStringNotContainsString('SIG-PLAIN', $captured['body']);
		$this->assertStringNotContainsString('SIG-HTML', $captured['html'] ?? '');
		$this->assertFalse($result2['signatureIncluded']);
	}

	public function testFac11FromSwapUsesOnlySelectedMailboxSignature(): void
	{
		$bodies = [];
		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturnSelf();
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('setPlainBody')->willReturnCallback(function ($body) use (&$bodies, $message) {
			$bodies[] = $body;
			return $message;
		});
		$message->method('setHtmlBody')->willReturnSelf();

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn([]);

		$personal = [
			'id' => 2,
			'workspace_id' => 1,
			'kind' => 'personal',
			'owner_user_id' => 'ae',
			'email_address' => 'ae@example.com',
			'label' => 'Alex',
			'smtp_host' => '',
			'username' => '',
			'secret_enc' => '',
			'signature_html' => '',
			'signature_plain' => 'PERSONAL-ONLY-SIG',
		];
		$team = [
			'id' => 3,
			'workspace_id' => 1,
			'kind' => 'team',
			'owner_user_id' => null,
			'email_address' => 'support@example.com',
			'label' => 'Support',
			'smtp_host' => '',
			'username' => '',
			'secret_enc' => '',
			'signature_html' => '',
			'signature_plain' => 'TEAM-ONLY-SIG',
		];

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$rows = [$personal, $team];
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls, $rows) {
			$row = $rows[$calls] ?? $rows[count($rows) - 1];
			$calls++;
			return $this->selectQbReturning($row);
		});

		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('d');
		$rate = $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);

		$svc = $this->service($db, $mailboxes, $mailer, $rate);
		$svc->send(1, [
			'mailboxId' => 2,
			'to' => 'ada@example.com',
			'subject' => 'P',
			'body' => 'Body',
			'includeSignature' => true,
			'idempotencyKey' => 'swap-personal',
		], 'ae', true);
		$svc->send(1, [
			'mailboxId' => 3,
			'to' => 'ada@example.com',
			'subject' => 'T',
			'body' => 'Body',
			'includeSignature' => true,
			'idempotencyKey' => 'swap-team',
		], 'ae', true);

		$this->assertCount(2, $bodies);
		$this->assertStringContainsString('PERSONAL-ONLY-SIG', $bodies[0]);
		$this->assertStringNotContainsString('TEAM-ONLY-SIG', $bodies[0]);
		$this->assertStringContainsString('TEAM-ONLY-SIG', $bodies[1]);
		$this->assertStringNotContainsString('PERSONAL-ONLY-SIG', $bodies[1]);
	}

	public function testFac13AttachmentRejectRetainsDraftId(): void
	{
		$files = $this->createMock(FilesAccessService::class);
		$files->method('loadMailAttachments')->willThrowException(new ValidationException(
			Constants::ERR_ATTACHMENT_DENIED,
			'bad mime',
			['attachment' => 'bad mime'],
		));

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');

		$draftInserted = false;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			static fn (string $t): bool => $t === 'crm_mailbox' || $t === 'crm_mail_dft',
		);
		$db->method('lastInsertId')->willReturn(77);
		$qbCalls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$qbCalls, &$draftInserted) {
			$qbCalls++;
			if ($qbCalls === 1) {
				return $this->selectQbReturning([
					'id' => 2,
					'workspace_id' => 1,
					'kind' => 'team',
					'email_address' => 'shared@example.com',
					'label' => 'Shared',
					'smtp_host' => '',
					'secret_enc' => '',
					'signature_html' => '',
					'signature_plain' => '',
				]);
			}
			if ($qbCalls === 2) {
				// findByIdempotency — no prior claim
				return $this->selectQbReturning(false);
			}
			// saveDraft insert
			return new class($draftInserted) {
				private bool $flag;
				public function __construct(bool &$flag) { $this->flag = &$flag; }
				public function insert(...$a) { return $this; }
				public function values(...$a) { return $this; }
				public function executeStatement(): int { $this->flag = true; return 1; }
				public function createNamedParameter(...$a) { return '?'; }
			};
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $s, array $a = []): string {
			return $a === [] ? $s : str_replace('%s', (string)($a[0] ?? ''), $s);
		});

		$svc = new MailSendService(
			$db,
			$mailer,
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
				'body' => 'Keep me',
				'bodyHtml' => '<p>Keep me</p>',
				'attachmentFileIds' => [99],
				'idempotencyKey' => 'fac13-attach',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_ATTACHMENT_DENIED, $e->getErrorCode());
			$this->assertSame('77', $e->getFields()['draftId'] ?? null);
			$this->assertTrue($draftInserted);
			$this->assertStringContainsString('77', $e->getMessage());
		}
	}

	/**
	 * @param array<string, mixed>|false $row
	 */
	private function selectQbReturning(array|false $row): object
	{
		return new class($row) {
			/** @var array<string, mixed>|false */
			private $row;
			public function __construct($row) { $this->row = $row; }
			public function select(...$a) { return $this; }
			public function from(...$a) { return $this; }
			public function where(...$a) { return $this; }
			public function andWhere(...$a) { return $this; }
			public function setMaxResults(...$a) { return $this; }
			public function orderBy(...$a) { return $this; }
			public function expr() {
				return new class {
					public function eq(...$a) { return 'eq'; }
					public function isNull(...$a) { return 'null'; }
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

	/** @param array<string, mixed> $row */
	private function mailboxDb(array $row): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$db->method('getQueryBuilder')->willReturn($this->selectQbReturning($row));

		return $db;
	}

	private function service(
		IDBConnection $db,
		MailboxService $mailboxes,
		?IMailer $mailer = null,
		?RateLimitService $rate = null,
	): MailSendService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$rate ??= $this->createMock(RateLimitService::class);
		$rate->method('allow')->willReturn(true);
		$mailer ??= $this->createMock(IMailer::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-15T12:00:00+00:00'));
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getSettings')->willReturn(['personalMailEnabled' => true]);

		return new MailSendService(
			$db,
			$mailer,
			$this->createMock(ICrypto::class),
			$rate,
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$mailboxes,
			$this->createMock(FilesAccessService::class),
			$this->createMock(ILockingProvider::class),
			$time,
			$this->createMock(IURLGenerator::class),
			$l10n,
			new HtmlSanitizeService(),
			$this->createMock(MailboxAuthService::class),
			$this->createMock(LoggerInterface::class),
			$workspaces,
		);
	}
}
