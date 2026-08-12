<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\ChecklistTemplate;
use OCA\CustomerCheck\Db\ChecklistTemplateItem;
use OCA\CustomerCheck\Db\ChecklistTemplateItemMapper;
use OCA\CustomerCheck\Db\ChecklistTemplateMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealChecklistFileMapper;
use OCA\CustomerCheck\Db\DealChecklistItem;
use OCA\CustomerCheck\Db\DealChecklistItemMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealChecklistService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class DealChecklistServiceTest extends TestCase
{
	public function testAddItemRequiresTitle(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('Order');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$svc = $this->service($dealMapper, $companies);

		try {
			$svc->addItem(5, ['title' => '  '], 'alex');
			$this->fail('empty title must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('title', $e->getFields());
		}
	}

	public function testCompleteRequiresEvidenceWhenNoFiles(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setWorkspaceId(3);
		$item->setDealId(5);
		$item->setCompanyId(9);
		$item->setTitle('Invoice');
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('lockRow')->with(11)->willReturn(true);
		$items->method('findById')->willReturn($item);
		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findByItem')->willReturn([]);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$companies,
			$items,
			$files,
			$db,
		);

		try {
			$svc->completeItem(11, ['evidenceNote' => ''], 'alex', '2026-08-11T10:00:00+00:00');
			$this->fail('missing evidence must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('evidenceNote', $e->getFields());
		}
	}

	public function testCompleteSucceedsWithNoteUnderRowLock(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setWorkspaceId(3);
		$item->setDealId(5);
		$item->setCompanyId(9);
		$item->setSortOrder(1);
		$item->setTitle('Invoice');
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('lockRow')->with(11)->willReturn(true);
		$items->method('findById')->willReturn($item);
		$items->expects($this->once())->method('update')->willReturnCallback(static function (DealChecklistItem $row): DealChecklistItem {
			return $row;
		});
		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findByItem')->willReturn([]);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('withdraw')->with('deal_checklist_item', '11');

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$companies,
			$items,
			$files,
			$db,
			null,
			null,
			$notifications,
		);

		$out = $svc->completeItem(11, ['evidenceNote' => 'Paid'], 'alex', '2026-08-11T10:00:00+00:00');
		$this->assertSame('done', $out['status']);
		$this->assertSame('Paid', $out['evidenceNote']);
	}

	public function testCompleteHealsStaleWorkspaceStamp(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setWorkspaceId(99); // stale denorm
		$item->setDealId(5);
		$item->setCompanyId(9);
		$item->setSortOrder(1);
		$item->setTitle('Invoice');
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('lockRow')->with(11)->willReturn(true);
		$items->method('findById')->willReturn($item);
		$items->expects($this->once())->method('update')->willReturnCallback(static function (DealChecklistItem $row): DealChecklistItem {
			self::assertSame(3, (int)$row->getWorkspaceId());

			return $row;
		});
		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findByItem')->willReturn([]);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('beginTransaction');
		$db->method('commit');

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('withdraw');

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$companies,
			$items,
			$files,
			$db,
			null,
			null,
			$notifications,
		);
		$out = $svc->completeItem(11, ['evidenceNote' => 'Paid'], 'alex', '2026-08-11T10:00:00+00:00');
		$this->assertSame('done', $out['status']);
	}

	public function testApplyTemplateDefersNotifyWhenNestedInOuterTransaction(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('License');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);
		$dealMapper->method('lockRow')->with(5)->willReturn(true);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$tmpl = new ChecklistTemplate();
		$tmpl->setId(1);
		$tmpl->setWorkspaceId(0);
		$tmpl->setIsEnabled(1);
		$templates = $this->createMock(ChecklistTemplateMapper::class);
		$templates->method('findById')->willReturn($tmpl);

		$step = new ChecklistTemplateItem();
		$step->setId(1);
		$step->setSortOrder(1);
		$step->setTitle('Send invoice');
		$templateItems = $this->createMock(ChecklistTemplateItemMapper::class);
		$templateItems->method('findByTemplate')->willReturn([$step]);

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('findByDeal')->willReturn([]);
		$items->method('countOpenByDeal')->willReturn(0);
		$items->method('maxSortOrder')->willReturn(0);
		$items->method('insert')->willReturnCallback(static function (DealChecklistItem $row): DealChecklistItem {
			$row->setId(77);

			return $row;
		});
		$items->method('findById')->willReturnCallback(static function (int $id): DealChecklistItem {
			$row = new DealChecklistItem();
			$row->setId($id);
			$row->setAssigneeUserId('ops');
			$row->setTitle('Send invoice');
			$row->setCompanyId(9);
			$row->setDealId(5);
			$row->setWorkspaceId(3);
			$row->setStatus(DealChecklistItem::STATUS_OPEN);

			return $row;
		});

		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('inTransaction')->willReturn(true);
		$db->expects($this->never())->method('beginTransaction');
		$db->expects($this->never())->method('commit');

		$notifyCount = 0;
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notifyUser')->willReturnCallback(static function () use (&$notifyCount): bool {
			$notifyCount++;

			return true;
		});

		$members = $this->createMock(WorkspaceMemberMapper::class);
		$members->method('findMembershipOrNull')->with(3, 'ops')->willReturn(
			$this->createMock(\OCA\CustomerCheck\Db\WorkspaceMember::class),
		);

		$svc = $this->service(
			$dealMapper,
			$companies,
			$items,
			$files,
			$db,
			$templates,
			$templateItems,
			$notifications,
			$members,
		);

		$out = $svc->applyTemplate(5, [
			'templateId' => 1,
			'mode' => 'append',
			'assigneeUserId' => 'ops',
		], 'alex');
		$this->assertSame([77], $out['_pendingAssignItemIds'] ?? null);
		$this->assertSame(0, $notifyCount, 'must not push before outer TX commits');

		$svc->flushPendingAssignNotifies($out);
		$this->assertSame(1, $notifyCount);
		$this->assertArrayNotHasKey('_pendingAssignItemIds', $out);
	}

	public function testLinkFileRejectsZeroFileId(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setCompanyId(9);
		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->expects($this->never())->method('findById');

		$companies = $this->createMock(CompanyService::class);
		$companies->expects($this->never())->method('get');

		$filesAccess = $this->createMock(FilesAccessService::class);
		$filesAccess->expects($this->never())->method('assertFileUnderFolder');
		$filesAccess->expects($this->never())->method('resolveReadableFile');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-11 12:00:00'));

		$svc = new DealChecklistService(
			$this->createMock(IDBConnection::class),
			$this->createMock(DealMapper::class),
			$companies,
			$items,
			$this->createMock(DealChecklistFileMapper::class),
			$this->createMock(ChecklistTemplateMapper::class),
			$this->createMock(ChecklistTemplateItemMapper::class),
			$this->createMock(WorkspaceMemberMapper::class),
			$filesAccess,
			$this->createMock(NotificationService::class),
			$time,
			$l10n,
		);

		try {
			$svc->linkFile(11, 0, 'alex');
			$this->fail('fileId 0 must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('fileId', $e->getFields());
			$this->assertStringContainsString('Pick a file to attach', (string)($e->getFields()['fileId'] ?? ''));
		}
	}

	public function testLinkFileRethrowsWhenInsertFailsWithoutExistingRow(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setCompanyId(9);
		$item->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('lockRow')->with(11)->willReturn(true);
		$items->method('findById')->willReturn($item);
		$items->expects($this->never())->method('update');

		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('insert')->willThrowException(new \RuntimeException('disk full'));
		$files->method('findByItem')->with(11)->willReturn([]);

		$company = new Company();
		$company->setId(9);
		$company->setFilesFolderId(77);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(9)->willReturn($company);

		$node = $this->createMock(\OCP\Files\File::class);
		$node->method('getName')->willReturn('scan.jpg');
		$filesAccess = $this->createMock(FilesAccessService::class);
		$filesAccess->method('assertFileUnderFolder')->with('alex', 55, 77)->willReturn($node);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-11 12:00:00'));

		$svc = new DealChecklistService(
			$db,
			$this->createMock(DealMapper::class),
			$companies,
			$items,
			$files,
			$this->createMock(ChecklistTemplateMapper::class),
			$this->createMock(ChecklistTemplateItemMapper::class),
			$this->createMock(WorkspaceMemberMapper::class),
			$filesAccess,
			$this->createMock(NotificationService::class),
			$time,
			$l10n,
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('disk full');
		$svc->linkFile(11, 55, 'alex');
	}

	public function testCompleteAlreadyDoneIsIdempotent(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setDealId(5);
		$item->setCompanyId(9);
		$item->setWorkspaceId(3);
		$item->setTitle('Invoice');
		$item->setStatus(DealChecklistItem::STATUS_DONE);
		$item->setEvidenceNote('Done');
		$item->setUpdatedAt(new \DateTime('2026-08-11 11:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('lockRow')->with(11)->willReturn(true);
		$items->method('findById')->willReturn($item);
		$items->expects($this->never())->method('update');

		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('withdraw');

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			$items,
			$files,
			$db,
			null,
			null,
			$notifications,
		);
		$out = $svc->completeItem(11, ['evidenceNote' => 'retry'], 'alex', '2026-08-11 10:00:00');
		$this->assertSame('done', $out['status']);
		$this->assertSame('Done', $out['evidenceNote']);
	}

	public function testLinkFileRequiresCompanyFolder(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setCompanyId(9);
		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('findById')->willReturn($item);

		$company = new Company();
		$company->setId(9);
		$company->setFilesFolderId(null);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(9)->willReturn($company);

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$companies,
			$items,
		);

		try {
			$svc->linkFile(11, 55, 'alex');
			$this->fail('missing company folder must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('fileId', $e->getFields());
		}
	}

	public function testListTemplatesReturnsEmptyWhenTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			null,
			null,
			$db,
		);
		$this->assertSame([], $svc->listTemplates(3));
	}

	public function testApplyTemplateReplaceDeletesOpenOnlyNotDone(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('Consulting');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);
		$dealMapper->method('lockRow')->with(5)->willReturn(true);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$tmpl = new ChecklistTemplate();
		$tmpl->setId(2);
		$tmpl->setWorkspaceId(0);
		$tmpl->setIsEnabled(1);
		$tmpl->setIsSystem(1);
		$tmpl->setTmplKey('consulting_order');
		$tmpl->setName('Consulting order');
		$templates = $this->createMock(ChecklistTemplateMapper::class);
		$templates->method('findById')->willReturn($tmpl);

		$step = new ChecklistTemplateItem();
		$step->setId(20);
		$step->setTemplateId(2);
		$step->setSortOrder(1);
		$step->setTitle('Kickoff call');
		$step->setTitleDe('Kickoff');
		$templateItems = $this->createMock(ChecklistTemplateItemMapper::class);
		$templateItems->method('findByTemplate')->willReturn([$step]);

		$done = new DealChecklistItem();
		$done->setId(100);
		$done->setStatus(DealChecklistItem::STATUS_DONE);
		$done->setTitle('Old done');
		$open = new DealChecklistItem();
		$open->setId(101);
		$open->setStatus(DealChecklistItem::STATUS_OPEN);
		$open->setTitle('Old open');

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('findByDeal')->willReturn([$done, $open]);
		$items->expects($this->once())->method('lockRow')->with(101)->willReturn(true);
		$items->method('findById')->with(101)->willReturn($open);
		$items->method('countOpenByDeal')->willReturn(0);
		$items->expects($this->once())->method('deleteOpenByDeal')->with(5)->willReturn(1);
		$items->expects($this->never())->method('deleteByDeal');
		$items->method('maxSortOrder')->willReturn(5);
		$items->method('insert')->willReturnCallback(static function (DealChecklistItem $row): DealChecklistItem {
			$row->setId(200);

			return $row;
		});

		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->expects($this->once())->method('deleteByItemIds')->with([101]);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$svc = $this->service(
			$dealMapper,
			$companies,
			$items,
			$files,
			$db,
			$templates,
			$templateItems,
		);

		$out = $svc->applyTemplate(5, ['templateId' => 2, 'mode' => 'replace'], 'alex');
		$this->assertSame('replace', $out['mode']);
		$this->assertSame(1, $out['inserted'] ?? null);
	}

	public function testSoftCapIgnoresDoneHistory(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('Order');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);
		$dealMapper->expects($this->once())->method('lockRow')->with(5)->willReturn(true);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$stored = new DealChecklistItem();
		$stored->setId(99);
		$stored->setWorkspaceId(3);
		$stored->setDealId(5);
		$stored->setCompanyId(9);
		$stored->setTitle('Ship');
		$stored->setStatus(DealChecklistItem::STATUS_OPEN);
		$stored->setCreatedBy('alex');
		$stored->setCreatedAt(new \DateTime('2026-08-11 10:00:00'));
		$stored->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		// 100 done + 0 open must still allow a new open item.
		$items->method('countByDeal')->willReturn(100);
		$items->method('countOpenByDeal')->with(5)->willReturn(0);
		$items->method('maxSortOrder')->willReturn(100);
		$items->expects($this->once())->method('insert')->willReturn($stored);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('beginTransaction');
		$db->method('commit');

		$svc = $this->service($dealMapper, $companies, $items, null, $db);
		$out = $svc->addItem(5, ['title' => 'Ship'], 'alex');
		$this->assertSame(99, $out['id']);
	}

	public function testApplyTemplateAppendAllDuplicatesReturnsInsertedZero(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('License');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);
		$dealMapper->method('lockRow')->with(5)->willReturn(true);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$tmpl = new ChecklistTemplate();
		$tmpl->setId(1);
		$tmpl->setWorkspaceId(0);
		$tmpl->setIsEnabled(1);
		$templates = $this->createMock(ChecklistTemplateMapper::class);
		$templates->method('findById')->willReturn($tmpl);

		$step = new ChecklistTemplateItem();
		$step->setId(1);
		$step->setSortOrder(1);
		$step->setTitle('Send invoice');
		$templateItems = $this->createMock(ChecklistTemplateItemMapper::class);
		$templateItems->method('findByTemplate')->willReturn([$step]);

		$existing = new DealChecklistItem();
		$existing->setId(50);
		$existing->setStatus(DealChecklistItem::STATUS_OPEN);
		$existing->setTitle('Send invoice');

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('findByDeal')->willReturn([$existing]);
		$items->expects($this->never())->method('insert');

		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$svc = $this->service(
			$dealMapper,
			$companies,
			$items,
			$files,
			$db,
			$templates,
			$templateItems,
		);
		$out = $svc->applyTemplate(5, ['templateId' => 1, 'mode' => 'append'], 'alex');
		$this->assertSame(0, $out['inserted'] ?? -1);
	}

	public function testApplyTemplateAppendSkipsDuplicateOpenTitles(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('License');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);
		$dealMapper->method('lockRow')->with(5)->willReturn(true);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$tmpl = new ChecklistTemplate();
		$tmpl->setId(1);
		$tmpl->setWorkspaceId(0);
		$tmpl->setIsEnabled(1);
		$templates = $this->createMock(ChecklistTemplateMapper::class);
		$templates->method('findById')->willReturn($tmpl);

		$stepA = new ChecklistTemplateItem();
		$stepA->setId(1);
		$stepA->setSortOrder(1);
		$stepA->setTitle('Send invoice');
		$stepB = new ChecklistTemplateItem();
		$stepB->setId(2);
		$stepB->setSortOrder(2);
		$stepB->setTitle('Activate license');
		$templateItems = $this->createMock(ChecklistTemplateItemMapper::class);
		$templateItems->method('findByTemplate')->willReturn([$stepA, $stepB]);

		$existing = new DealChecklistItem();
		$existing->setId(50);
		$existing->setStatus(DealChecklistItem::STATUS_OPEN);
		$existing->setTitle('Send invoice');

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('findByDeal')->willReturn([$existing]);
		$items->method('countOpenByDeal')->willReturn(1);
		$items->method('maxSortOrder')->willReturn(1);
		$inserted = 0;
		$items->expects($this->once())->method('insert')->willReturnCallback(static function (DealChecklistItem $row) use (&$inserted): DealChecklistItem {
			$inserted++;
			$row->setId(60 + $inserted);

			return $row;
		});

		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$svc = $this->service(
			$dealMapper,
			$companies,
			$items,
			$files,
			$db,
			$templates,
			$templateItems,
		);

		$svc->applyTemplate(5, ['templateId' => 1, 'mode' => 'append'], 'alex');
		$this->assertSame(1, $inserted, 'duplicate open title must be skipped; only new step inserted');
	}

	public function testCreateTemplateRequiresNameAndSteps(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			null,
			null,
			$db,
		);
		try {
			$svc->createTemplate(3, ['name' => '', 'steps' => ['A']], 'alex');
			$this->fail('empty name must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('name', $e->getFields());
		}
		try {
			$svc->createTemplate(3, ['name' => 'Onboarding', 'steps' => []], 'alex');
			$this->fail('empty steps must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('steps', $e->getFields());
		}
	}

	public function testCreateTemplateRejectsMoreThanEightSteps(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			null,
			null,
			$db,
		);
		try {
			$svc->createTemplate(3, [
				'name' => 'Too many',
				'steps' => ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
			], 'alex');
			$this->fail('9 steps must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('steps', $e->getFields());
		}
	}

	public function testUpdateItemHealsStaleWorkspaceAndUsesCompanyForAssignee(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setWorkspaceId(1); // stale after move
		$item->setDealId(5);
		$item->setCompanyId(9);
		$item->setTitle('Invoice');
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(9)->willReturn($company);

		$members = $this->createMock(WorkspaceMemberMapper::class);
		$members->method('findMembershipOrNull')->with(3, 'bob')->willReturn(
			$this->createMock(\OCA\CustomerCheck\Db\WorkspaceMember::class),
		);

		$updated = null;
		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->expects($this->once())->method('lockRow')->with(11)->willReturn(true);
		$items->method('findById')->willReturn($item);
		$items->method('update')->willReturnCallback(static function (DealChecklistItem $row) use (&$updated): DealChecklistItem {
			$updated = $row;

			return $row;
		});
		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->method('findGroupedByItemIds')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$dealMapper = $this->createMock(DealMapper::class);
		$deal = new Deal();
		$deal->setId(5);
		$deal->setTitle('Order');
		$dealMapper->method('findById')->willReturn($deal);

		$svc = $this->service($dealMapper, $companies, $items, $files, $db, null, null, null, $members);
		$out = $svc->updateItem(
			11,
			['assigneeUserId' => 'bob', 'updatedAt' => '2026-08-11T10:00:00+00:00'],
			'alex',
			'2026-08-11T10:00:00+00:00',
		);
		$this->assertNotNull($updated);
		$this->assertSame(3, (int)$updated->getWorkspaceId());
		$this->assertSame('bob', $updated->getAssigneeUserId());
		$this->assertSame('bob', $out['assigneeUserId']);
	}

	public function testUpdateItemLocksRowAndRejectsClosed(): void
	{
		$item = new DealChecklistItem();
		$item->setId(11);
		$item->setWorkspaceId(3);
		$item->setDealId(5);
		$item->setCompanyId(9);
		$item->setTitle('Invoice');
		$item->setStatus(DealChecklistItem::STATUS_DONE);
		$item->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->expects($this->once())->method('lockRow')->with(11)->willReturn(true);
		$items->method('findById')->willReturn($item);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			$items,
			null,
			$db,
		);

		try {
			$svc->updateItem(11, ['title' => 'X', 'updatedAt' => '2026-08-11T10:00:00+00:00'], 'alex', '2026-08-11T10:00:00+00:00');
			$this->fail('closed item must reject update');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('status', $e->getFields());
		}
	}

	public function testAddItemEnforcesSoftCap(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('Order');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);
		$dealMapper->expects($this->once())->method('lockRow')->with(5)->willReturn(true);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('countOpenByDeal')->with(5)->willReturn(DealChecklistService::MAX_ITEMS_PER_DEAL);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$svc = $this->service($dealMapper, $companies, $items, null, $db);

		try {
			$svc->addItem(5, ['title' => 'One more'], 'alex');
			$this->fail('soft cap must reject');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('dealId', $e->getFields());
		}
	}

	public function testAddItemLocksDealBeforeInsert(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(9);
		$deal->setTitle('Order');
		$dealMapper = $this->createMock(DealMapper::class);
		$dealMapper->method('findById')->willReturn($deal);
		$dealMapper->expects($this->once())->method('lockRow')->with(5)->willReturn(true);

		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$company->setName('ACME');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$stored = new DealChecklistItem();
		$stored->setId(99);
		$stored->setWorkspaceId(3);
		$stored->setDealId(5);
		$stored->setCompanyId(9);
		$stored->setTitle('Ship');
		$stored->setStatus(DealChecklistItem::STATUS_OPEN);
		$stored->setCreatedBy('alex');
		$stored->setCreatedAt(new \DateTime('2026-08-11 10:00:00'));
		$stored->setUpdatedAt(new \DateTime('2026-08-11 10:00:00'));

		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->method('countOpenByDeal')->willReturn(0);
		$items->method('maxSortOrder')->willReturn(0);
		$items->expects($this->once())->method('insert')->willReturn($stored);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$svc = $this->service($dealMapper, $companies, $items, null, $db);
		$out = $svc->addItem(5, ['title' => 'Ship'], 'alex');
		$this->assertSame(99, $out['id']);
		$this->assertSame('Ship', $out['title']);
	}

	public function testUpdateTemplateRejectsSystemTemplates(): void
	{
		$tmpl = new ChecklistTemplate();
		$tmpl->setId(1);
		$tmpl->setWorkspaceId(0);
		$tmpl->setIsSystem(1);
		$tmpl->setName('License order');
		$templates = $this->createMock(ChecklistTemplateMapper::class);
		$templates->method('findById')->willReturn($tmpl);

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			null,
			null,
			null,
			$templates,
		);
		try {
			$svc->updateTemplate(1, 3, ['name' => 'Hack'], 'alex');
			$this->fail('system template edit must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('templateId', $e->getFields());
		}
	}

	public function testCreateTemplatePersistsWorkspaceCopy(): void
	{
		$templates = $this->createMock(ChecklistTemplateMapper::class);
		$templates->method('findByWorkspaceAndKey')->willReturn(null);
		$templates->expects($this->once())->method('insert')->willReturnCallback(static function (ChecklistTemplate $t): ChecklistTemplate {
			$t->setId(99);

			return $t;
		});
		$templateItems = $this->createMock(ChecklistTemplateItemMapper::class);
		$templateItems->expects($this->once())->method('deleteByTemplate')->with(99);
		$templateItems->expects($this->exactly(2))->method('insert');
		$templateItems->method('findByTemplate')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			null,
			null,
			$db,
			$templates,
			$templateItems,
		);
		$out = $svc->createTemplate(3, [
			'name' => 'Service handoff',
			'steps' => ["Kickoff\n", 'Send proposal'],
		], 'alex');
		$this->assertSame(99, $out['id']);
		$this->assertFalse($out['isSystem']);
		$this->assertTrue($out['canEdit']);
	}

	public function testEraseCompanyWithdrawsAssignNotifications(): void
	{
		$itemIds = [11, 12];
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 11],
			['id' => 12],
			false,
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$qb = $this->getMockBuilder(\OCP\DB\QueryBuilder\IQueryBuilder::class)->getMock();
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$files = $this->createMock(DealChecklistFileMapper::class);
		$files->expects($this->once())->method('deleteByItemIds')->with($itemIds);
		$items = $this->createMock(DealChecklistItemMapper::class);
		$items->expects($this->once())->method('deleteByCompany')->with(9);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->exactly(2))->method('withdraw')
			->withConsecutive(
				['deal_checklist_item', '11'],
				['deal_checklist_item', '12'],
			);

		$svc = $this->service(
			$this->createMock(DealMapper::class),
			$this->createMock(CompanyService::class),
			$items,
			$files,
			$db,
			null,
			null,
			$notifications,
		);
		$svc->eraseCompany(9);
	}

	private function service(
		DealMapper $dealMapper,
		CompanyService $companies,
		?DealChecklistItemMapper $items = null,
		?DealChecklistFileMapper $files = null,
		?IDBConnection $db = null,
		?ChecklistTemplateMapper $templates = null,
		?ChecklistTemplateItemMapper $templateItems = null,
		?NotificationService $notifications = null,
		?WorkspaceMemberMapper $members = null,
	): DealChecklistService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		$l10n->method('getLanguageCode')->willReturn('en');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-11 12:00:00'));

		$db ??= $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		return new DealChecklistService(
			$db,
			$dealMapper,
			$companies,
			$items ?? $this->createMock(DealChecklistItemMapper::class),
			$files ?? $this->createMock(DealChecklistFileMapper::class),
			$templates ?? $this->createMock(ChecklistTemplateMapper::class),
			$templateItems ?? $this->createMock(ChecklistTemplateItemMapper::class),
			$members ?? $this->createMock(WorkspaceMemberMapper::class),
			$this->createMock(FilesAccessService::class),
			$notifications ?? $this->createMock(NotificationService::class),
			$time,
			$l10n,
		);
	}
}
