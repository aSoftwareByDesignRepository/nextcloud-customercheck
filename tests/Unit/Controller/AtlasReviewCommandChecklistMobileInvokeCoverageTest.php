<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Controller\CommandApiController;
use OCA\CustomerCheck\Controller\DealChecklistApiController;
use OCA\CustomerCheck\Controller\MobileController;
use OCA\CustomerCheck\Controller\ReviewApiController;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealChecklistItem;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CallbackService;
use OCA\CustomerCheck\Service\CompanyCommandService;
use OCA\CustomerCheck\Service\CompanyDocumentsService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CompanyTasksService;
use OCA\CustomerCheck\Service\DealChecklistService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\EditConflictService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MobileApiService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\PlatformLinksService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\ValueMapService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 invoke coverage — Review / Command / DealChecklist / Mobile (remaining).
 */
final class AtlasReviewCommandChecklistMobileInvokeCoverageTest extends TestCase
{
	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function wsCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);
		$ws->setName('Book');

		return [
			'workspace' => $ws,
			'role' => 'manager',
			'isAppAdmin' => true,
			'member' => null,
			'needsPicker' => false,
		];
	}

	private function company(int $id = 3, int $ws = 7): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setWorkspaceId($ws);
		$c->setSlug('acme');
		$c->setName('Acme');
		$c->setUpdatedAt(new \DateTime('2026-01-01T12:00:00+00:00'));

		return $c;
	}

	private function deal(int $id = 9, int $companyId = 3): Deal
	{
		$d = new Deal();
		$d->setId($id);
		$d->setCompanyId($companyId);
		$d->setTitle('T');
		$d->setStage('lead');
		$d->setValueCents(0);
		$d->setCreatedAt(new \DateTime('2026-01-01'));
		$d->setUpdatedAt(new \DateTime('2026-01-01'));

		return $d;
	}

	private function person(int $id = 5, int $companyId = 3): Person
	{
		$p = new Person();
		$p->setId($id);
		$p->setCompanyId($companyId);
		$p->setName('Pat');
		$p->setEmail('pat@example.com');
		$p->setIsPrimary(0);

		return $p;
	}

	private function activity(int $id = 11, int $companyId = 3): Activity
	{
		$a = new Activity();
		$a->setId($id);
		$a->setCompanyId($companyId);
		$a->setType('todo');
		$a->setTitle('Call');
		$a->setOrigin('manual');
		$a->setUpdatedAt(new \DateTime('2026-01-01'));

		return $a;
	}

	private function checklistItem(int $id = 15, int $companyId = 3): DealChecklistItem
	{
		$item = new DealChecklistItem();
		$item->setId($id);
		$item->setWorkspaceId(7);
		$item->setDealId(9);
		$item->setCompanyId($companyId);
		$item->setTitle('Step');
		$item->setStatus(DealChecklistItem::STATUS_OPEN);
		$item->setSortOrder(1);
		$item->setUpdatedAt(new \DateTime('2026-01-01'));

		return $item;
	}

	private function accessOk(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireCanUseApp')->willReturn('alice');
		$access->method('currentUserId')->willReturn('alice');
		$access->method('isAppAdmin')->willReturn(true);

		return $access;
	}

	private function workspacesOk(): WorkspaceAccessService
	{
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->wsCtx());
		$workspaces->method('assertCapability');
		$workspaces->method('assertCompanyInWorkspace');
		$workspaces->method('listForMobile')->willReturn([['id' => 7, 'name' => 'Book']]);

		return $workspaces;
	}

	/** @param array<string, mixed> $params */
	private function requestOk(array $params = []): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => array_key_exists($k, $params) ? $params[$k] : $d,
		);
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, 'Authorization') === 0) {
					return 'Basic dGVzdDp0ZXN0';
				}

				return '';
			},
		);
		$request->method('passesCSRFCheck')->willReturn(true);
		$request->method('getUploadedFile')->willReturn(null);

		return $request;
	}

	public function testReviewApiAllActionsAndAuthzNegative(): void
	{
		$review = $this->createMock(ReviewService::class);
		$review->method('listItems')->willReturn([]);
		$review->method('countOpen')->willReturn(0);
		$review->method('assign')->willReturn(['id' => 1]);
		$review->method('snooze')->willReturn(['id' => 1]);
		$review->method('noRelation')->willReturn(['id' => 1]);
		$review->method('discard')->willReturn(['id' => 1]);
		$review->method('discardBulk')->willReturn(['count' => 0]);
		$review->method('markSpam')->willReturn(['id' => 1]);
		$review->method('markSpamBulk')->willReturn(['count' => 0]);
		$review->method('followUp')->willReturn(['id' => 1]);
		$review->method('reopen')->willReturn(['id' => 1]);
		$review->method('enqueueSourceItem')->willReturn(['id' => 1]);
		$review->method('applyCountryMapping')->willReturn(['ok' => true]);

		$conflicts = $this->createMock(EditConflictService::class);
		$conflicts->method('listOpen')->willReturn([]);
		$conflicts->method('countOpen')->willReturn(0);
		$conflicts->method('get')->willReturn(['id' => 2, 'actorA' => 'alice', 'actorB' => 'bob']);
		$conflicts->method('actorMayResolve')->willReturn(true);
		$conflicts->method('resolve')->willReturn([
			'entityType' => 'company',
			'entityId' => 3,
			'appliedPatch' => [],
		]);

		$valueMaps = $this->createMock(ValueMapService::class);
		$valueMaps->method('listForWorkspace')->willReturn([]);
		$valueMaps->method('apply')->willReturn(['canonical' => 'DE']);
		$valueMaps->method('delete');

		$workspaceService = $this->createMock(WorkspaceService::class);
		$workspaceService->method('getReviewSources')->willReturn([]);
		$workspaceService->method('updateReviewSources')->willReturn([]);

		$mailbox = $this->createMock(MailboxService::class);
		$mailbox->method('listMailboxes')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('inTransaction')->willReturn(false);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($this->company());
		$deals = $this->createMock(DealService::class);
		$persons = $this->createMock(PersonService::class);
		$agreements = $this->createMock(AgreementService::class);
		$timeline = $this->createMock(TimelineService::class);

		$ctrl = new ReviewApiController(
			'customercheck',
			$this->requestOk(),
			$this->accessOk(),
			$this->workspacesOk(),
			$workspaceService,
			$review,
			$conflicts,
			$valueMaps,
			$companies,
			$deals,
			$persons,
			$agreements,
			$mailbox,
			$db,
			$timeline,
		);

		self::assertSame(200, $ctrl->listItems(7)->getStatus());
		self::assertSame(200, $ctrl->assign(7, 1)->getStatus());
		self::assertSame(200, $ctrl->snooze(7, 1)->getStatus());
		self::assertSame(200, $ctrl->noRelation(7, 1)->getStatus());
		self::assertSame(200, $ctrl->discard(7, 1)->getStatus());
		self::assertSame(200, $ctrl->discardBulk(7)->getStatus());
		self::assertSame(200, $ctrl->markSpam(7, 1)->getStatus());
		self::assertSame(200, $ctrl->markSpamBulk(7)->getStatus());
		self::assertSame(200, $ctrl->followUp(7, 1)->getStatus());
		self::assertSame(200, $ctrl->reopen(7, 1)->getStatus());
		self::assertSame(200, $ctrl->enqueue(7)->getStatus());
		self::assertSame(200, $ctrl->applyCountry(7, 1)->getStatus());
		self::assertSame(200, $ctrl->listConflicts(7)->getStatus());
		self::assertSame(200, $ctrl->resolveConflict(7, 2)->getStatus());
		self::assertSame(200, $ctrl->listValueMaps(7)->getStatus());
		self::assertSame(200, $ctrl->applyValueMap(7)->getStatus());
		self::assertSame(200, $ctrl->deleteValueMap(7, 3)->getStatus());
		self::assertSame(200, $ctrl->updateReviewSources(7)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('currentUserId')->willThrowException(new AccessDeniedException('no'));
		$deny = new ReviewApiController(
			'customercheck',
			$this->requestOk(),
			$denyAccess,
			$this->workspacesOk(),
			$workspaceService,
			$review,
			$conflicts,
			$valueMaps,
			$companies,
			$deals,
			$persons,
			$agreements,
			$mailbox,
			$db,
			$timeline,
		);
				foreach ([
			static fn () => $deny->listItems(7),
			static fn () => $deny->assign(7, 1),
			static fn () => $deny->snooze(7, 1),
			static fn () => $deny->noRelation(7, 1),
			static fn () => $deny->discard(7, 1),
			static fn () => $deny->discardBulk(7),
			static fn () => $deny->markSpam(7, 1),
			static fn () => $deny->markSpamBulk(7),
			static fn () => $deny->followUp(7, 1),
			static fn () => $deny->reopen(7, 1),
			static fn () => $deny->enqueue(7),
			static fn () => $deny->applyCountry(7, 1),
			static fn () => $deny->listConflicts(7),
			static fn () => $deny->resolveConflict(7, 2),
			static fn () => $deny->listValueMaps(7),
			static fn () => $deny->applyValueMap(7),
			static fn () => $deny->deleteValueMap(7, 3),
			static fn () => $deny->updateReviewSources(7),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'authz idx ' . $i);
		}
	}

	public function testCommandApiAllActionsAndAuthzNegative(): void
	{
		$company = $this->company();
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$command = $this->createMock(CompanyCommandService::class);
		$command->method('build')->willReturn(['companyId' => 3]);
		$command->method('communication')->willReturn(['items' => []]);
		$command->method('peopleRoster')->willReturn([]);

		$activity = $this->activity();
		$callbacks = $this->createMock(CallbackService::class);
		$callbacks->method('schedule')->willReturn($activity);

		$documents = $this->createMock(CompanyDocumentsService::class);
		$documents->method('linkFromPicker')->willReturn($company);
		$documents->method('createFolder')->willReturn([
			'fileId' => 99,
			'path' => '/Acme',
			'company' => $company,
		]);
		$documents->method('unlinkFolder')->willReturn($company);
		$documents->method('listChildren')->willReturn(['items' => [], 'path' => null, 'broken' => false]);
		$documents->method('searchFolders')->willReturn([]);

		$tasks = $this->createMock(CompanyTasksService::class);
		$tasks->method('list')->willReturn([]);
		$tasks->method('create')->willReturn(['id' => 1]);

		$platform = $this->createMock(PlatformLinksService::class);
		$platform->method('forUser')->willReturn([]);

		$ctrl = new CommandApiController(
			'customercheck',
			$this->requestOk(),
			$this->accessOk(),
			$this->workspacesOk(),
			$command,
			$documents,
			$tasks,
			$callbacks,
			$platform,
			$companies,
		);

		self::assertSame(200, $ctrl->command(3)->getStatus());
		self::assertSame(200, $ctrl->communication(3)->getStatus());
		self::assertSame(200, $ctrl->people(3)->getStatus());
		self::assertSame(201, $ctrl->scheduleCallback(3)->getStatus());
		self::assertSame(200, $ctrl->putFilesFolder(3)->getStatus());
		self::assertSame(201, $ctrl->createFilesFolder(3)->getStatus());
		self::assertSame(200, $ctrl->deleteFilesFolder(3)->getStatus());
		self::assertSame(200, $ctrl->filesChildren(3)->getStatus());
		self::assertSame(200, $ctrl->searchFolders()->getStatus());
		self::assertSame(200, $ctrl->tasks(3)->getStatus());
		self::assertSame(201, $ctrl->createTask(3)->getStatus());
		self::assertSame(200, $ctrl->platformLinks(3)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new CommandApiController(
			'customercheck',
			$this->requestOk(),
			$denyAccess,
			$this->workspacesOk(),
			$command,
			$documents,
			$tasks,
			$callbacks,
			$platform,
			$companies,
		);
				foreach ([
			static fn () => $deny->command(3),
			static fn () => $deny->communication(3),
			static fn () => $deny->people(3),
			static fn () => $deny->scheduleCallback(3),
			static fn () => $deny->putFilesFolder(3),
			static fn () => $deny->createFilesFolder(3),
			static fn () => $deny->deleteFilesFolder(3),
			static fn () => $deny->filesChildren(3),
			static fn () => $deny->searchFolders(),
			static fn () => $deny->tasks(3),
			static fn () => $deny->createTask(3),
			static fn () => $deny->platformLinks(3),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'authz idx ' . $i);
		}
	}

	public function testDealChecklistApiRemainingActions(): void
	{
		$deal = $this->deal();
		$item = $this->checklistItem();
		$company = $this->company();

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$deals = $this->createMock(DealService::class);
		$deals->method('get')->willReturn($deal);

		$checklists = $this->createMock(DealChecklistService::class);
		$checklists->method('listTemplates')->willReturn([]);
		$checklists->method('listTemplatesForManage')->willReturn([]);
		$checklists->method('createTemplate')->willReturn(['id' => 1]);
		$checklists->method('cloneTemplate')->willReturn(['id' => 2]);
		$checklists->method('updateTemplate')->willReturn(['id' => 1]);
		$checklists->method('deleteTemplate');
		$checklists->method('listForDeal')->willReturn(['items' => []]);
		$checklists->method('addItem')->willReturn(['id' => 15]);
		$checklists->method('applyTemplate')->willReturn(['items' => [], 'dealId' => 9]);
		$checklists->method('requireItemForGate')->willReturn($item);
		$checklists->method('requireItemForFileRow')->willReturn($item);
		$checklists->method('updateItem')->willReturn(['id' => 15]);
		$checklists->method('completeItem')->willReturn(['id' => 15]);
		$checklists->method('linkFile')->willReturn(['id' => 15]);
		$checklists->method('unlinkFile');

		$ctrl = new DealChecklistApiController(
			'customercheck',
			$this->requestOk(),
			$this->accessOk(),
			$this->workspacesOk(),
			$companies,
			$deals,
			$checklists,
		);

		self::assertSame(200, $ctrl->templates()->getStatus());
		self::assertSame(200, $ctrl->templatesManage()->getStatus());
		self::assertSame(201, $ctrl->createTemplate()->getStatus());
		self::assertSame(201, $ctrl->cloneTemplate(1)->getStatus());
		self::assertSame(200, $ctrl->updateTemplate(1)->getStatus());
		self::assertSame(200, $ctrl->deleteTemplate(1)->getStatus());
		self::assertSame(200, $ctrl->index(9)->getStatus());
		self::assertSame(201, $ctrl->create(9)->getStatus());
		self::assertSame(201, $ctrl->fromTemplate(9)->getStatus());
		self::assertSame(200, $ctrl->update(15)->getStatus());
		self::assertSame(200, $ctrl->complete(15)->getStatus());
		self::assertSame(201, $ctrl->linkFile(15)->getStatus());
		self::assertSame(200, $ctrl->unlinkFile(88)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new DealChecklistApiController(
			'customercheck',
			$this->requestOk(),
			$denyAccess,
			$this->workspacesOk(),
			$companies,
			$deals,
			$checklists,
		);
		foreach ([
			static fn () => $deny->templates(),
			static fn () => $deny->templatesManage(),
			static fn () => $deny->createTemplate(),
			static fn () => $deny->cloneTemplate(1),
			static fn () => $deny->updateTemplate(1),
			static fn () => $deny->deleteTemplate(1),
			static fn () => $deny->index(9),
			static fn () => $deny->create(9),
			static fn () => $deny->fromTemplate(9),
			static fn () => $deny->update(15),
			static fn () => $deny->complete(15),
			static fn () => $deny->linkFile(15),
			static fn () => $deny->unlinkFile(88),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'deal checklist authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'deal checklist authz idx ' . $i);
		}
	}

	public function testMobileRemainingRouteActions(): void
	{
		$company = $this->company();
		$person = $this->person();
		$deal = $this->deal();
		$activity = $this->activity();
		$item = $this->checklistItem();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$gate = $this->createMock(MobileGateService::class);
		$gate->method('assertGatePassed');
		$gate->method('bootstrapPayload')->willReturn(['ok' => true]);

		$mobile = $this->createMock(MobileApiService::class);
		$mobile->method('companyRoadDetail')->willReturn(['id' => 3]);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$companies->method('create')->willReturn($company);
		$companies->method('update')->willReturn($company);

		$persons = $this->createMock(PersonService::class);
		$persons->method('get')->willReturn($person);
		$persons->method('create')->willReturn($person);
		$persons->method('update')->willReturn($person);

		$activities = $this->createMock(ActivityService::class);
		$activities->method('get')->willReturn($activity);
		$activities->method('requireMobileAccess');
		$activities->method('update')->willReturn($activity);
		$activities->method('setDone')->willReturn($activity);

		$deals = $this->createMock(DealService::class);
		$deals->method('get')->willReturn($deal);
		$deals->method('create')->willReturn($deal);
		$deals->method('isStale')->willReturn(false);
		$deals->method('staleDealDays')->willReturn(14);
		$deals->method('changeStage')->willReturn(['deal' => $deal, 'sequencesFired' => []]);

		$agreements = $this->createMock(AgreementService::class);

		$idempotency = $this->createMock(MobileIdempotencyService::class);
		$idempotency->method('once')->willReturnCallback(
			static function ($uid, $key, $factory) {
				return $factory();
			},
		);
		$idempotency->method('createActivity')->willReturn($activity);

		$push = $this->createMock(MobilePushService::class);
		$push->method('register');
		$push->method('unregister');

		$documents = $this->createMock(CompanyDocumentsService::class);
		$documents->method('listChildren')->willReturn([
			'items' => [
				['fileId' => 10, 'name' => 'a.pdf', 'size' => 1, 'mtime' => 1, 'isFolder' => false],
			],
			'path' => '/Acme',
			'broken' => false,
		]);

		$member = new WorkspaceMember();
		$member->setId(1);
		$member->setWorkspaceId(7);
		$member->setUserId('alice');
		$member->setRole('manager');

		$workspaceService = $this->createMock(WorkspaceService::class);
		$workspaceService->method('listMembers')->willReturn([$member]);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		$checklists = $this->createMock(DealChecklistService::class);
		$checklists->method('listTemplates')->willReturn([]);
		$checklists->method('listForDeal')->willReturn(['items' => [['id' => 15, 'title' => 'Step', 'files' => []]]]);
		$checklists->method('applyTemplate')->willReturn(['items' => [], 'dealId' => 9, 'mode' => 'append', 'inserted' => 0]);
		$checklists->method('flushPendingAssignNotifies');
		$checklists->method('requireItemForGate')->willReturn($item);
		$checklists->method('updateItem')->willReturn(['id' => 15]);
		$checklists->method('completeItem')->willReturn(['id' => 15]);
		$checklists->method('linkFile')->willReturn(['id' => 15]);

		$ctrl = new MobileController(
			Application::APP_ID,
			$this->requestOk([
				'companyId' => 3,
				'name' => 'Acme',
				'title' => 'Deal',
				'stage' => 'won',
				'token' => 'push-tok',
				'platform' => 'android',
				'fileId' => 10,
				'templateId' => 1,
			]),
			$userSession,
			$this->accessOk(),
			$this->workspacesOk(),
			$gate,
			$mobile,
			$companies,
			$persons,
			$activities,
			$deals,
			$agreements,
			$idempotency,
			$push,
			$documents,
			$workspaceService,
			$userManager,
			$appManager,
			$checklists,
		);

		// Remaining beyond Integration MobileGate (activities/agreements/bootstrap/companies/companyDetail/deals/today).
		self::assertTrue($ctrl->workspaces()->getData()['success']);
		self::assertSame(201, $ctrl->createCompany(7)->getStatus());
		self::assertSame(200, $ctrl->updateCompany(7, 3)->getStatus());
		self::assertSame(201, $ctrl->createPerson(7, 3)->getStatus());
		self::assertSame(200, $ctrl->updatePerson(7, 5)->getStatus());
		self::assertSame(201, $ctrl->createDeal(7, 3)->getStatus());
		self::assertSame(200, $ctrl->companyFiles(7, 3)->getStatus());
		self::assertSame(200, $ctrl->members(7)->getStatus());
		self::assertSame(200, $ctrl->checklistTemplates(7)->getStatus());
		self::assertSame(200, $ctrl->dealChecklist(7, 9)->getStatus());
		self::assertSame(201, $ctrl->applyChecklistTemplate(7, 9)->getStatus());
		self::assertSame(200, $ctrl->updateChecklistItem(7, 15)->getStatus());
		self::assertSame(201, $ctrl->linkChecklistFile(7, 15)->getStatus());
		$upload = $ctrl->uploadChecklistFile(7, 15);
		self::assertInstanceOf(JSONResponse::class, $upload);
		self::assertGreaterThanOrEqual(400, $upload->getStatus());
		self::assertSame(201, $ctrl->createActivity(7)->getStatus());
		self::assertSame(200, $ctrl->registerPush()->getStatus());
		self::assertSame(200, $ctrl->unregisterPush()->getStatus());
		self::assertSame(200, $ctrl->updateActivity(7, 11)->getStatus());
		self::assertSame(200, $ctrl->doneActivity(7, 11)->getStatus());
		self::assertSame(200, $ctrl->doneChecklistItem(7, 15)->getStatus());
		self::assertSame(200, $ctrl->dealStage(7, 9)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$denyAccess->method('currentUserId')->willThrowException(new AccessDeniedException('no'));
		$deny = new MobileController(
			Application::APP_ID,
			$this->requestOk([
				'companyId' => 3,
				'name' => 'Acme',
				'title' => 'Deal',
				'stage' => 'won',
				'token' => 'push-tok',
				'platform' => 'android',
				'fileId' => 10,
				'templateId' => 1,
			]),
			$userSession,
			$denyAccess,
			$this->workspacesOk(),
			$gate,
			$mobile,
			$companies,
			$persons,
			$activities,
			$deals,
			$agreements,
			$idempotency,
			$push,
			$documents,
			$workspaceService,
			$userManager,
			$appManager,
			$checklists,
		);
		foreach ([
			static fn () => $deny->workspaces(),
			static fn () => $deny->today(7),
			static fn () => $deny->companies(7),
			static fn () => $deny->createCompany(7),
			static fn () => $deny->companyDetail(7, 3),
			static fn () => $deny->updateCompany(7, 3),
			static fn () => $deny->createPerson(7, 3),
			static fn () => $deny->updatePerson(7, 5),
			static fn () => $deny->createDeal(7, 3),
			static fn () => $deny->companyFiles(7, 3),
			static fn () => $deny->members(7),
			static fn () => $deny->checklistTemplates(7),
			static fn () => $deny->dealChecklist(7, 9),
			static fn () => $deny->applyChecklistTemplate(7, 9),
			static fn () => $deny->updateChecklistItem(7, 15),
			static fn () => $deny->linkChecklistFile(7, 15),
			static fn () => $deny->uploadChecklistFile(7, 15),
			static fn () => $deny->activities(7),
			static fn () => $deny->createActivity(7),
			static fn () => $deny->updateActivity(7, 11),
			static fn () => $deny->doneActivity(7, 11),
			static fn () => $deny->doneChecklistItem(7, 15),
			static fn () => $deny->deals(7, 3),
			static fn () => $deny->dealStage(7, 9),
			static fn () => $deny->agreements(7),
			static fn () => $deny->registerPush(),
			static fn () => $deny->unregisterPush(),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'mobile remaining authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'mobile remaining authz idx ' . $i);
		}
	}
}
