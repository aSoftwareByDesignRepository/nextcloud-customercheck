<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Exception\NotFoundException;
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
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Shared SMTP must not attach email_touch / timeline to a foreign-workspace company.
 */
final class MailSendServiceCompanyAclTest extends TestCase
{
	public function testSendRejectsCompanyOutsideWorkspaceBeforeRateLimit(): void
	{
		$mailboxRow = [
			'id' => 9,
			'workspace_id' => 1,
			'kind' => 'team',
			'owner_user_id' => null,
			'email_address' => 'support@example.com',
			'label' => 'Support',
			'smtp_host' => '',
			'secret_enc' => '',
			'signature_html' => '',
			'signature_plain' => '',
		];

		$mailboxResult = $this->createMock(\OCP\DB\IResult::class);
		$mailboxResult->method('fetch')->willReturn($mailboxRow);
		$mailboxResult->method('closeCursor');

		$companyResult = $this->createMock(\OCP\DB\IResult::class);
		$companyResult->method('fetch')->willReturn(false);
		$companyResult->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturnOnConsecutiveCalls($mailboxResult, $companyResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailSendService(
			$db,
			$this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$rate,
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

		$this->expectException(NotFoundException::class);
		$svc->send(1, [
			'mailboxId' => 9,
			'to' => 'ada@example.com',
			'subject' => 'Hi',
			'body' => 'Body',
			'companyId' => 99,
			'idempotencyKey' => 'foreign-co',
		], 'ae', true);
	}

	public function testSendWithoutCompanyIdSkipsCompanyLookup(): void
	{
		$db = $this->createMock(IDBConnection::class);
		// Empty idempotency is rejected before mailbox load — no getQueryBuilder.
		$db->expects($this->never())->method('getQueryBuilder');

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailSendService(
			$db,
			$this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$rate,
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

		try {
			$svc->send(1, [
				'mailboxId' => 9,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
				'idempotencyKey' => '',
			], 'ae', true);
			$this->fail('Expected ValidationException');
		} catch (\OCA\CustomerCheck\Exception\ValidationException $e) {
			$this->assertArrayHasKey('idempotencyKey', $e->getFields());
		}
	}
}
