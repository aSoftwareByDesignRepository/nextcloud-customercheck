<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;

use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Shared mailbox remove — workspace-scoped delete + open Review cleanup.
 */
final class MailboxServiceDeleteTest extends TestCase
{
	public function testDeleteRejectsInvalidIds(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m): string => $m);
		$svc = new MailboxService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			new HtmlSanitizeService(),
		);
		$this->expectException(ValidationException::class);
		$svc->delete(0, 1);
	}

	public function testDeleteThrowsWhenMailboxMissing(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m): string => $m);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$checkQb = $this->createMock(IQueryBuilder::class);
		$checkQb->method('expr')->willReturn($expr);
		$checkQb->method('select')->willReturnSelf();
		$checkQb->method('from')->willReturnSelf();
		$checkQb->method('where')->willReturnSelf();
		$checkQb->method('andWhere')->willReturnSelf();
		$checkQb->method('setMaxResults')->willReturnSelf();
		$checkQb->method('createNamedParameter')->willReturnArgument(0);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('closeCursor');
		$checkQb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('crm_mailbox')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($checkQb);

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			new HtmlSanitizeService(),
		);
		$this->expectException(NotFoundException::class);
		$svc->delete(7, 99);
	}

	public function testDeleteSourceContainsReviewPurgeAndChildTables(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('$this->purgeOpenSharedMailAssignForMessages($workspaceId, $mailMsgIds);', $src);
		$this->assertMatchesRegularExpression('/private function purgeOpenSharedMailAssignForMessages\(int \$workspaceId, array \$mailMsgIds\): void/', $src);
		$this->assertStringContainsString('listMessageIdsForMailbox', $src);
		$this->assertStringContainsString('personal_mail_shared_unmatched', $src);
		$this->assertStringContainsString("['shared_mail', 'personal_mail_shared_unmatched']", $src);
		$this->assertStringContainsString("['open', 'follow_up', 'snoozed']", $src);
		$this->assertStringContainsString("->delete('crm_review_item')", $src);
		$this->assertStringContainsString("->delete('crm_mail_msg')", $src);
		$this->assertStringContainsString("->delete('crm_mail_dft')", $src);
		$this->assertStringContainsString("->delete('crm_mail_fld')", $src);
		$this->assertStringContainsString("->delete('crm_mailbox')", $src);
		$mailMsgId = "\$ref['mailMsgId']";
		$this->assertStringContainsString($mailMsgId, $src);
	}

	public function testSyncServiceDeleteUsesSameLockAsSync(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('function deleteMailbox(int $workspaceId, int $mailboxId, bool $allowPersonal = false)', $src);
		$this->assertStringContainsString("\$lockKey = 'crm_mbox_' . \$mailboxId;", $src);
		$this->assertStringContainsString('$this->mailboxes->delete($workspaceId, $mailboxId, $allowPersonal)', $src);
		$this->assertStringContainsString('assertRemoteMutateAllowed', $src);
		$this->assertStringContainsString('This mailbox is updating', $src);
		$controller = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('$this->mailboxSync->deleteMailbox($workspaceId, $id)', $controller);
		$personal = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('deleteMailbox($workspaceId, $mailboxId, true)', $personal);
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertStringContainsString("/mailboxes/{id}', 'verb' => 'DELETE'", $routes);
		$this->assertStringContainsString("/mailboxes/{id}/remove', 'verb' => 'POST'", $routes);
		$this->assertStringContainsString("'postfix' => 'Remove'", $routes);
	}
}
