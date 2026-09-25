<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\PersonaApiController;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\BriefService;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\CoachingService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\OneOnOneService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\PromisesRollCallService;
use OCA\CustomerCheck\Service\PulseService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 invoke coverage — PersonaApiController workspace / coaching / promises surface.
 */
final class AtlasPersonaWorkspaceInvokeCoverageTest extends TestCase
{
	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function wsCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);
		$ws->setSlug('book');
		$ws->setName('Book');
		$ws->setIsDefault(1);

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

		return $c;
	}

	private function member(): WorkspaceMember
	{
		$m = new WorkspaceMember();
		$m->setId(1);
		$m->setWorkspaceId(7);
		$m->setUserId('bob');
		$m->setRole('member');
		$m->setCanMailSend(1);

		return $m;
	}

	private function accessOk(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$access->method('isAppAdmin')->willReturn(true);

		return $access;
	}

	private function workspacesOk(): WorkspaceAccessService
	{
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->wsCtx());
		$workspaces->method('assertCapability');
		$workspaces->method('assertUserCanAccessCompany')->willReturnCallback(
			fn (string $uid, int $wsId, string $cap = 'read') => [
				'workspaceId' => $wsId,
				'role' => 'manager',
				'isAppAdmin' => true,
			],
		);

		return $workspaces;
	}

	private function requestOk(): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => $d);
		$request->method('getHeader')->willReturn('');

		return $request;
	}

	private function l10nOk(): IL10N
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $c) => $c === 1 ? $s : $p);

		return $l10n;
	}

	/**
	 * @return array{
	 *   0: PersonaApiController,
	 *   workspaceService: WorkspaceService,
	 *   companies: CompanyService,
	 *   plans: AccountPlanService,
	 *   promises: PromiseService,
	 * }
	 */
	private function buildController(
		?AccessControlService $access = null,
		?WorkspaceAccessService $workspaces = null,
	): array {
		$wsEntity = $this->wsCtx()['workspace'];
		$workspaceService = $this->createMock(WorkspaceService::class);
		$workspaceService->method('listForUser')->willReturn([$wsEntity]);
		$workspaceService->method('listAll')->willReturn([]);
		$workspaceService->method('create')->willReturn($wsEntity);
		$workspaceService->method('listMembers')->willReturn([$this->member()]);
		$workspaceService->method('addMember')->willReturn($this->member());
		$workspaceService->method('removeMember');
		$workspaceService->method('importMembersFromGroup')->willReturn(['imported' => 1]);
		$workspaceService->method('importMembersFromCircle')->willReturn(['imported' => 1]);
		$workspaceService->method('getSettings')->willReturn(['personalMailEnabled' => true]);
		$workspaceService->method('updateSettings')->willReturn(['ok' => true]);
		$workspaceService->method('getReviewSources')->willReturn([]);

		$directory = $this->createMock(DirectoryOptionsService::class);
		$directory->method('resolveUsers')->willReturn([['id' => 'bob', 'displayName' => 'Bob']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('workspaceIcsFeedUrl')->willReturn('https://example.test/ics');

		$pulse = $this->createMock(PulseService::class);
		$pulse->method('build')->willReturn(['pulse' => true]);
		$brief = $this->createMock(BriefService::class);
		$brief->method('build')->willReturn(['brief' => true]);
		$brief->method('confirm')->willReturn(['ok' => true]);
		$brief->method('verify');
		$chase = $this->createMock(ChaseService::class);
		$chase->method('myChase')->willReturn([]);
		$coverage = $this->createMock(CoverageService::class);
		$coverage->method('myAccounts')->willReturn([]);
		$coaching = $this->createMock(CoachingService::class);
		$coaching->method('board')->willReturn(['team' => []]);
		$coaching->method('setOoo')->willReturn(['ok' => true]);
		$coaching->method('bulkReassign')->willReturn(['ok' => true]);
		$escalations = $this->createMock(EscalationService::class);
		$escalations->method('create')->willReturn(['id' => 1]);
		$plans = $this->createMock(AccountPlanService::class);
		$plans->method('listForWorkspace')->willReturn([]);
		$plans->method('upsert')->willReturn(['id' => 2, 'companyId' => 3]);
		$plans->method('forCompany')->willReturn(['id' => 2, 'companyId' => 3, 'mapSteps' => []]);
		$plans->method('addMapStep')->willReturn(['id' => 9]);
		$plans->method('setMapStepStatus')->willReturn(['id' => 9, 'status' => 'done']);
		$promisesRollCall = $this->createMock(PromisesRollCallService::class);
		$promisesRollCall->method('build')->willReturn([]);
		$promises = $this->createMock(PromiseService::class);
		$promises->method('listForCompany')->willReturn([]);
		$promises->method('create')->willReturn(['id' => 4]);
		$promises->method('complete');
		$promises->method('cancel');
		$oneOnOne = $this->createMock(OneOnOneService::class);
		$oneOnOne->method('prep')->willReturn(['prep' => true]);
		$oneOnOne->method('save')->willReturn(['saved' => true]);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($this->company());
		$companies->method('moveToWorkspace')->willReturn([
			'company' => $this->company(),
			'fromWorkspaceId' => 7,
			'toWorkspaceId' => 8,
			'mailUnmatched' => 0,
			'draftsDetached' => 0,
		]);

		$ctrl = new PersonaApiController(
			'customercheck',
			$this->requestOk(),
			$access ?? $this->accessOk(),
			$workspaces ?? $this->workspacesOk(),
			$workspaceService,
			$pulse,
			$brief,
			$chase,
			$coverage,
			$coaching,
			$promises,
			$this->createMock(MailboxService::class),
			$this->createMock(MailboxSyncService::class),
			$this->createMock(MailSendService::class),
			$this->createMock(MailDraftService::class),
			$plans,
			$escalations,
			$promisesRollCall,
			$companies,
			$oneOnOne,
			$directory,
			$settings,
			$this->createMock(ReviewService::class),
			$this->createMock(PersonalMailService::class),
			$this->createMock(PersonalMailOAuthService::class),
			$this->createMock(RateLimitService::class),
			$this->l10nOk(),
		);

		return [
			$ctrl,
			'workspaceService' => $workspaceService,
			'companies' => $companies,
			'plans' => $plans,
			'promises' => $promises,
		];
	}

	public function testPersonaWorkspaceSurfaceAndAuthzNegative(): void
	{
		[$ctrl] = $this->buildController();

		self::assertSame(200, $ctrl->workspaces()->getStatus());
		self::assertSame(201, $ctrl->createWorkspace()->getStatus());
		self::assertSame(200, $ctrl->workspaceMembers(7)->getStatus());
		self::assertSame(201, $ctrl->addWorkspaceMember(7)->getStatus());
		self::assertSame(200, $ctrl->removeWorkspaceMember(7, 'bob')->getStatus());
		self::assertSame(200, $ctrl->importWorkspaceMembersFromGroup(7)->getStatus());
		self::assertSame(200, $ctrl->importWorkspaceMembersFromCircle(7)->getStatus());
		self::assertSame(200, $ctrl->workspaceSettings(7)->getStatus());
		self::assertSame(200, $ctrl->updateWorkspaceSettings(7)->getStatus());
		self::assertSame(200, $ctrl->pulse(7)->getStatus());
		self::assertSame(200, $ctrl->brief(7)->getStatus());
		self::assertSame(200, $ctrl->confirmCommitment(7)->getStatus());
		self::assertSame(200, $ctrl->verifyCommitment(7, 1)->getStatus());
		self::assertSame(200, $ctrl->chase(7)->getStatus());
		self::assertSame(200, $ctrl->myAccounts(7)->getStatus());
		self::assertSame(200, $ctrl->team(7)->getStatus());
		self::assertSame(200, $ctrl->setOoo(7)->getStatus());
		self::assertSame(200, $ctrl->bulkReassign(7)->getStatus());
		self::assertSame(200, $ctrl->escalate(7)->getStatus());
		self::assertSame(200, $ctrl->strategy(7)->getStatus());
		self::assertSame(200, $ctrl->upsertPlan(3)->getStatus());
		self::assertSame(200, $ctrl->accountPlan(3)->getStatus());
		self::assertSame(200, $ctrl->promisesRollCall()->getStatus());
		self::assertSame(200, $ctrl->addMapStep(3)->getStatus());
		self::assertSame(200, $ctrl->completeMapStep(3, 9)->getStatus());
		self::assertSame(200, $ctrl->oneOnOnePrep(7, 'bob')->getStatus());
		self::assertSame(200, $ctrl->oneOnOneSave(7, 'bob')->getStatus());
		// MULTI_WORKSPACE_ENABLED=false → validation deny; method still invoked.
		$moved = $ctrl->moveCompany(3);
		self::assertInstanceOf(JSONResponse::class, $moved);
		self::assertGreaterThanOrEqual(400, $moved->getStatus());
		self::assertSame(200, $ctrl->promises(3)->getStatus());
		self::assertSame(200, $ctrl->createPromise(3)->getStatus());
		self::assertSame(200, $ctrl->completePromise(3, 4)->getStatus());
		self::assertSame(200, $ctrl->cancelPromise(3, 4)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('currentUserId')->willThrowException(new AccessDeniedException('no'));
		[$deny] = $this->buildController($denyAccess);
				foreach ([
			static fn () => $deny->workspaces(),
			static fn () => $deny->createWorkspace(),
			static fn () => $deny->workspaceMembers(7),
			static fn () => $deny->addWorkspaceMember(7),
			static fn () => $deny->removeWorkspaceMember(7, 'bob'),
			static fn () => $deny->importWorkspaceMembersFromGroup(7),
			static fn () => $deny->importWorkspaceMembersFromCircle(7),
			static fn () => $deny->workspaceSettings(7),
			static fn () => $deny->updateWorkspaceSettings(7),
			static fn () => $deny->pulse(7),
			static fn () => $deny->brief(7),
			static fn () => $deny->confirmCommitment(7),
			static fn () => $deny->verifyCommitment(7, 1),
			static fn () => $deny->chase(7),
			static fn () => $deny->myAccounts(7),
			static fn () => $deny->team(7),
			static fn () => $deny->setOoo(7),
			static fn () => $deny->bulkReassign(7),
			static fn () => $deny->escalate(7),
			static fn () => $deny->strategy(7),
			static fn () => $deny->upsertPlan(3),
			static fn () => $deny->accountPlan(3),
			static fn () => $deny->promisesRollCall(),
			static fn () => $deny->addMapStep(3),
			static fn () => $deny->completeMapStep(3, 9),
			static fn () => $deny->oneOnOnePrep(7, 'bob'),
			static fn () => $deny->oneOnOneSave(7, 'bob'),
			static fn () => $deny->moveCompany(3),
			static fn () => $deny->promises(3),
			static fn () => $deny->createPromise(3),
			static fn () => $deny->completePromise(3, 4),
			static fn () => $deny->cancelPromise(3, 4),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'authz idx ' . $i);
		}
	}
}
