<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailSendService;
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
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('expr')->willReturn($expr);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('andWhere')->willReturnSelf();
		$select->method('setMaxResults')->willReturnSelf();
		$select->method('createNamedParameter')->willReturn('p');
		$select->expects($this->once())->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($select);

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
		$db->expects($this->never())->method('getQueryBuilder');

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->once())
			->method('allow')
			->willReturn(false);

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
		);

		try {
			$svc->send(1, [
				'mailboxId' => 9,
				'to' => 'ada@example.com',
				'subject' => 'Hi',
				'body' => 'Body',
				'idempotencyKey' => 'no-company',
			], 'ae', true);
			$this->fail('Expected rate-limit ValidationException');
		} catch (\OCA\CustomerCheck\Exception\ValidationException $e) {
			$this->assertSame(\OCA\CustomerCheck\Constants::ERR_RATE_LIMITED, $e->getErrorCode());
		}
	}
}
