<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\ReviewService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Mail matched on archived companies must re-enter Review / rematch on active companies.
 */
final class ReviewArchivedCompanyMailTest extends TestCase
{
	public function testReconcileArchivedCompanyMailReopensMatchedRows(): void
	{
		$comp = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('gt')->willReturn('gt');
		$expr->method('isNotNull')->willReturn('isNotNull');

		$selectResult = $this->createMock(IResult::class);
		$selectResult->method('fetch')->willReturnOnConsecutiveCalls(['id' => 77], false);
		$selectResult->method('closeCursor');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('innerJoin')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('orderBy')->willReturnSelf();
		$selectQb->method('setMaxResults')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn('p');
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => in_array($t, ['crm_mail_msg', 'crm_review_item'], true));
		$db->method('getQueryBuilder')->willReturn($selectQb);

		$mailbox = $this->createMock(MailboxService::class);
		$mailbox->expects($this->once())->method('triageReopenMatched')->with(77, 1)->willReturn(true);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-14 10:00:00'));
		$l10n = $this->createMock(IL10N::class);

		$review = $this->getMockBuilder(ReviewService::class)
			->setConstructorArgs([
				$db,
				$time,
				$l10n,
				$mailbox,
				$this->createMock(\OCA\CustomerCheck\Service\CompanyService::class),
				$this->createMock(\OCA\CustomerCheck\Db\CompanyMapper::class),
				$this->createMock(\OCA\CustomerCheck\Db\PersonMapper::class),
				$this->createMock(\OCA\CustomerCheck\Service\WorkspaceService::class),
			])
			->onlyMethods(['returnSharedMailMessagesToReview'])
			->getMock();
		$review->expects($this->once())->method('returnSharedMailMessagesToReview')->with(1, [77]);

		$this->assertSame(1, $review->reconcileArchivedCompanyMail(1));
	}

	public function testReconcileMisfiledTeamForwardsReopensPersonEmailOnTeamDomain(): void
	{
		$comp = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('gt')->willReturn('gt');

		$selectResult = $this->createMock(IResult::class);
		$selectResult->method('fetch')->willReturnOnConsecutiveCalls([
			'id' => 21156,
			'from_addr' => 'alex@software-by-design.de',
			'subject' => 'Fwd: AW: Angebot ArbeitszeitCheck Lizenz',
			'preview' => "Von: Daniel Gattinger <Gattinger@banss.at>\n",
			'forward_from_hints' => null,
		], false);
		$selectResult->method('closeCursor');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('orderBy')->willReturnSelf();
		$selectQb->method('setMaxResults')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn('p');
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => in_array($t, ['crm_mail_msg', 'crm_review_item'], true));
		$db->method('getQueryBuilder')->willReturn($selectQb);

		$mailbox = $this->createMock(MailboxService::class);
		$mailbox->expects($this->once())->method('triageReopenMatched')->with(21156, 1)->willReturn(true);

		$mailboxSync = $this->createMock(\OCA\CustomerCheck\Service\MailboxSyncService::class);
		$mailboxSync->method('matchExcludeEmailsPublic')->willReturn(['support@software-by-design.de']);

		$time = $this->createMock(ITimeFactory::class);
		$l10n = $this->createMock(IL10N::class);

		$review = $this->getMockBuilder(ReviewService::class)
			->setConstructorArgs([
				$db,
				$time,
				$l10n,
				$mailbox,
				$this->createMock(\OCA\CustomerCheck\Service\CompanyService::class),
				$this->createMock(\OCA\CustomerCheck\Db\CompanyMapper::class),
				$this->createMock(\OCA\CustomerCheck\Db\PersonMapper::class),
				$this->createMock(\OCA\CustomerCheck\Service\WorkspaceService::class),
				null,
				null,
				$mailboxSync,
			])
			->onlyMethods(['returnSharedMailMessagesToReview'])
			->getMock();
		$review->expects($this->once())->method('returnSharedMailMessagesToReview')->with(1, [21156]);

		$this->assertSame(1, $review->reconcileMisfiledTeamForwards(1));
	}

	public function testContractPostMailboxSyncOrchestratesRepairAndPromote(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('function postMailboxSync', $src);
		$this->assertStringContainsString('reconcileArchivedCompanyMail', $src);
		$this->assertStringContainsString('reconcileMisfiledTeamForwards', $src);
		$this->assertStringContainsString('backfillForwardHintsFromPreview', $src);
		$this->assertStringContainsString('promoteOpenSharedMail', $src);
		$pos = strpos($src, 'function postMailboxSync');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 1200);
		$this->assertStringContainsString('reconcileKnownSharedMail', $chunk);
		$this->assertStringContainsString('MAIL_SYNC_POST_REPAIR_CAP', $chunk);
	}

	public function testTriageAssignRejectsArchivedCompanies(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString("->andWhere(\$check->expr()->isNull('archived_at'))", $src);
		$this->assertStringContainsString('function triageReopenMatched', $src);
	}
}
