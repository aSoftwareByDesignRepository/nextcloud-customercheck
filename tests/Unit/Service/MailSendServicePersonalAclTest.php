<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxAuthService;
use Psr\Log\LoggerInterface;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Personal vs Team send ACL — NN-01 / MH-09 / MH-10.
 */
final class MailSendServicePersonalAclTest extends TestCase
{
	public function testPersonalSendDeniedForNonOwner(): void
	{
		$svc = $this->buildServiceWithMailbox([
			'id' => 9,
			'workspace_id' => 1,
			'kind' => MailAclLogic::KIND_PERSONAL,
			'owner_user_id' => 'alex',
			'email_address' => 'alex@example.com',
			'label' => 'Alex',
			'smtp_host' => '',
			'secret_enc' => '',
			'signature_html' => '',
			'signature_plain' => '',
		]);

		$this->expectException(ValidationException::class);
		$svc->send(1, [
			'mailboxId' => 9,
			'to' => 'customer@example.com',
			'subject' => 'Hi',
			'body' => 'Hello',
			'idempotencyKey' => 'crm-send-test-1',
		], 'teammate', false);
	}

	public function testTeamSendDeniedWithoutMailSend(): void
	{
		$svc = $this->buildServiceWithMailbox([
			'id' => 3,
			'workspace_id' => 1,
			'kind' => MailAclLogic::KIND_TEAM,
			'owner_user_id' => null,
			'email_address' => 'support@example.com',
			'label' => 'Support',
			'smtp_host' => '',
			'secret_enc' => '',
			'signature_html' => '',
			'signature_plain' => '',
		]);

		$this->expectException(ValidationException::class);
		$svc->send(1, [
			'mailboxId' => 3,
			'to' => 'customer@example.com',
			'subject' => 'Hi',
			'body' => 'Hello',
			'idempotencyKey' => 'crm-send-test-2',
		], 'viewer', false);
	}

	public function testPersonalSendDeniedWhenWorkspacePolicyOff(): void
	{
		$svc = $this->buildServiceWithMailbox([
			'id' => 9,
			'workspace_id' => 1,
			'kind' => MailAclLogic::KIND_PERSONAL,
			'owner_user_id' => 'alex',
			'email_address' => 'alex@example.com',
			'label' => 'Alex',
			'smtp_host' => '',
			'secret_enc' => '',
			'signature_html' => '',
			'signature_plain' => '',
		], ['personalMailEnabled' => false]);

		try {
			$svc->send(1, [
				'mailboxId' => 9,
				'to' => 'customer@example.com',
				'subject' => 'Hi',
				'body' => 'Hello',
				'idempotencyKey' => 'crm-send-test-policy-off',
			], 'alex', true);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('personalMail', $e->getFields());
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $settings
	 */
	private function buildServiceWithMailbox(array $row, array $settings = ['personalMailEnabled' => true]): MailSendService
	{
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->getMockBuilder(\stdClass::class)
			->addMethods(['select', 'from', 'where', 'andWhere', 'setMaxResults', 'executeQuery', 'expr', 'createNamedParameter', 'tableExists'])
			->getMock();
		// Simpler: mock loadMailbox via overriding is hard — use real query builder mock chain.
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn($row);
		$result->method('closeCursor');

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$query = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$query->method('select')->willReturnSelf();
		$query->method('from')->willReturnSelf();
		$query->method('where')->willReturnSelf();
		$query->method('andWhere')->willReturnSelf();
		$query->method('setMaxResults')->willReturnSelf();
		$query->method('expr')->willReturn($expr);
		$query->method('createNamedParameter')->willReturnArgument(0);
		$query->method('executeQuery')->willReturn($result);

		$db->method('getQueryBuilder')->willReturn($query);
		$db->method('tableExists')->willReturn(true);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');

		$rate = $this->createMock(RateLimitService::class);
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('disclosure')->willReturn('Disclosure');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getSettings')->willReturn($settings);

		return new MailSendService(
			$db,
			$this->createMock(IMailer::class),
			$this->createMock(ICrypto::class),
			$rate,
			$this->createMock(EmailTouchService::class),
			$this->createMock(TimelineService::class),
			$mailboxes,
			$this->createMock(FilesAccessService::class),
			$locking,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IURLGenerator::class),
			$l10n,
			new HtmlSanitizeService(),
			$this->createMock(MailboxAuthService::class),
			$this->createMock(LoggerInterface::class),
			$workspaces,
		);
	}
}
