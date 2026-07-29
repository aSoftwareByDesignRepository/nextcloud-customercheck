<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\BriefService;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\CirclesRollCallService;
use OCA\CustomerCheck\Service\CoachingService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\PrepareMeetingService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\PulseService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PersonaApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly WorkspaceAccessService $workspaces,
		private readonly WorkspaceService $workspaceService,
		private readonly PulseService $pulse,
		private readonly BriefService $brief,
		private readonly ChaseService $chase,
		private readonly CoverageService $coverage,
		private readonly CoachingService $coaching,
		private readonly PromiseService $promises,
		private readonly MailboxService $mailbox,
		private readonly MailboxSyncService $mailboxSync,
		private readonly MailSendService $mailSend,
		private readonly PrepareMeetingService $prepare,
		private readonly AccountPlanService $plans,
		private readonly EscalationService $escalations,
		private readonly CirclesRollCallService $circles,
		private readonly CompanyService $companies,
		private readonly \OCA\CustomerCheck\Service\OneOnOneService $oneOnOne,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function workspaces(): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$list = [];
			foreach ($this->workspaceService->listForUser($uid) as $ws) {
				$list[] = [
					'id' => (int)$ws->getId(),
					'slug' => (string)$ws->getSlug(),
					'name' => (string)$ws->getName(),
					'isDefault' => $ws->isDefaultWorkspace(),
					'archived' => $ws->isArchived(),
				];
			}
			$ctx = $this->workspaces->resolveForUser($uid);
			return $this->ok([
				'workspaces' => $list,
				'current' => [
					'id' => (int)$ctx['workspace']->getId(),
					'name' => (string)$ctx['workspace']->getName(),
					'role' => $ctx['role'],
					'needsPicker' => $ctx['needsPicker'],
				],
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createWorkspace(): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$body = $this->requestBody();
			$ws = $this->workspaceService->create(
				(string)($body['name'] ?? ''),
				(string)($body['slug'] ?? ''),
				$uid,
			);

			return $this->ok([
				'id' => (int)$ws->getId(),
				'slug' => (string)$ws->getSlug(),
				'name' => (string)$ws->getName(),
				'isDefault' => $ws->isDefaultWorkspace(),
				'archived' => $ws->isArchived(),
			], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function workspaceMembers(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$items = [];
			foreach ($this->workspaceService->listMembers($workspaceId) as $m) {
				$items[] = [
					'userId' => (string)$m->getUserId(),
					'role' => (string)$m->getRole(),
					'canMailSend' => method_exists($m, 'canMailSend') ? $m->canMailSend() : ((int)$m->getCanMailSend() === 1),
				];
			}

			return $this->ok(['items' => $items]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function addWorkspaceMember(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$body = $this->requestBody();
			$m = $this->workspaceService->addMember(
				$workspaceId,
				trim((string)($body['userId'] ?? '')),
				(string)($body['role'] ?? 'member'),
				!array_key_exists('canMailSend', $body) || !empty($body['canMailSend']),
				$uid,
			);

			return $this->ok([
				'userId' => (string)$m->getUserId(),
				'role' => (string)$m->getRole(),
				'canMailSend' => method_exists($m, 'canMailSend') ? $m->canMailSend() : ((int)$m->getCanMailSend() === 1),
			], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function removeWorkspaceMember(int $workspaceId, string $userId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$this->workspaceService->removeMember($workspaceId, $userId);

			return $this->ok(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function workspaceSettings(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'read');

			return $this->ok($this->workspaceService->getSettings($workspaceId));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateWorkspaceSettings(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');

			return $this->ok($this->workspaceService->updateSettings($workspaceId, $this->requestBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function pulse(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'read');
			return $this->ok($this->pulse->build($workspaceId));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function brief(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'read');
			return $this->ok($this->brief->build($workspaceId));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function confirmCommitment(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'brief.confirm');
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$body = $this->requestBody();
			// Members may only confirm commitments they own (§3.2 limited).
			if (!$ctx['isAppAdmin'] && ($ctx['role'] ?? '') === 'member') {
				$body['ownerUserId'] = $uid;
			}
			return $this->ok($this->brief->confirm($workspaceId, $body, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function verifyCommitment(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'brief.confirm');
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$body = $this->requestBody();
			$role = $ctx['isAppAdmin'] ? 'manager' : (string)($ctx['role'] ?? 'viewer');
			$this->brief->verify(
				$id,
				$workspaceId,
				(string)($body['action'] ?? ''),
				$uid,
				isset($body['note']) ? (string)$body['note'] : null,
				isset($body['evidence']) ? (string)$body['evidence'] : null,
				$role,
			);
			return $this->ok(['ok' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function chase(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'read');
			return $this->ok(['items' => $this->chase->myChase($uid, $workspaceId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function myAccounts(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'read');
			return $this->ok(['items' => $this->coverage->myAccounts($uid, $workspaceId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function team(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			return $this->ok($this->coaching->board($workspaceId, $uid, $ctx['role'], $ctx['isAppAdmin']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function setOoo(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'ooo');
			return $this->ok($this->coaching->setOoo($workspaceId, $this->requestBody(), $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function bulkReassign(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'bulk');
			return $this->ok($this->coaching->bulkReassign($workspaceId, $this->requestBody(), $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function escalate(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'team.coach');
			return $this->ok($this->escalations->create($workspaceId, $this->requestBody(), $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function strategy(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'read');
			return $this->ok(['items' => $this->plans->listForWorkspace($workspaceId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function upsertPlan(int $companyId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertCapability($uid, $wsId, 'write');
			return $this->ok($this->plans->upsert($companyId, $this->requestBody(), $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function accountPlan(int $companyId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'read');
			$plan = $this->plans->forCompany($companyId);

			return $this->ok($plan ?? [
				'companyId' => $companyId,
				'goal' => null,
				'priority' => 'p2',
				'ownerUserId' => null,
				'horizon' => null,
				'nextReviewOn' => null,
				'status' => null,
				'mapSteps' => [],
				'milestones' => [],
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function circlesRollCall(): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			if (!$this->access->isAppAdmin($uid)) {
				return $this->fail('WS_ROLE_DENIED', 'Only app administrators can open Circles roll-call.', [], 403);
			}
			return $this->ok(['items' => $this->circles->build()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function addMapStep(int $companyId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertCapability($uid, $wsId, 'write');
			$plan = $this->plans->forCompany($companyId);
			if ($plan === null) {
				$plan = $this->plans->upsert($companyId, [
					'goal' => '',
					'priority' => 'p2',
					'status' => 'active',
				], $uid);
			}
			return $this->ok($this->plans->addMapStep((int)$plan['id'], $wsId, $this->requestBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function oneOnOnePrep(int $workspaceId, string $reportUserId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'team.coach');
			return $this->ok($this->oneOnOne->prep($workspaceId, $uid, $reportUserId));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function oneOnOneSave(int $workspaceId, string $reportUserId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'team.coach');
			return $this->ok($this->oneOnOne->save($workspaceId, $uid, $reportUserId, $this->requestBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function moveCompany(int $companyId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$body = $this->requestBody();
			$toWs = (int)($body['workspaceId'] ?? 0);
			$confirm = trim((string)($body['confirmSlug'] ?? ''));
			$company = $this->companies->get($companyId);
			$fromWs = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertCapability($uid, $fromWs, 'move');
			$this->workspaces->assertCapability($uid, $toWs, 'move');
			if ($confirm !== (string)$company->getSlug()) {
				return $this->fail('VALIDATION', 'Type the company slug to confirm the move. Files ACL is not auto-moved.', [], 422);
			}
			$updated = $this->companies->update($companyId, ['workspaceId' => $toWs], $uid, null);
			return $this->ok([
				'id' => (int)$updated->getId(),
				'workspaceId' => (int)$updated->getWorkspaceId(),
				'filesAclWarning' => true,
				'message' => 'Company moved. Files folder ACL was not changed — review sharing manually.',
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function promises(int $companyId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'read');

			return $this->ok(['items' => $this->promises->listForCompany($companyId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createPromise(int $companyId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertCapability($uid, $wsId, 'write');

			return $this->ok($this->promises->create($companyId, $this->requestBody(), $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function completePromise(int $companyId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertCapability($uid, $wsId, 'write');
			$body = $this->requestBody();
			$evidence = trim((string)($body['evidence'] ?? ''));
			$this->promises->complete($id, $wsId, $evidence, $uid);

			return $this->ok(['id' => $id, 'status' => 'done']);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function prepareMeeting(int $companyId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertCapability($uid, $wsId, 'read');

			return $this->ok($this->prepare->build($companyId, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function mailboxes(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$boxes = $this->mailbox->listMailboxes($workspaceId);
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$disclosure = $boxes === [] ? null : $this->mailbox->disclosure(
				(string)$boxes[0]['label'],
				(string)$ctx['workspace']->getName(),
			);
			return $this->ok(['items' => $boxes, 'disclosure' => $disclosure]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveMailbox(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mailbox');
			return $this->ok($this->mailbox->save($workspaceId, $this->requestBody(), $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function testMailbox(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mailbox');
			return $this->ok($this->mailboxSync->testConnection($this->requestBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function sendMail(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.send');
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$canSend = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'mail.send', false);
			if ($ctx['member'] !== null && method_exists($ctx['member'], 'canMailSend')) {
				$canSend = $canSend && ($ctx['isAppAdmin'] || $ctx['member']->canMailSend());
			}
			return $this->ok($this->mailSend->send($workspaceId, $this->requestBody(), $uid, $canSend));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function unmatchedMail(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			return $this->ok([
				'items' => $this->mailbox->unmatched($workspaceId),
				'disclosure' => $this->mailbox->disclosure('shared', (string)$ctx['workspace']->getName()),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function triageMail(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$body = $this->requestBody();
			$this->mailbox->triageAssign($id, $workspaceId, (int)($body['companyId'] ?? 0));
			return $this->ok(['ok' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** @return array<string, mixed> */
	private function requestBody(): array
	{
		$raw = file_get_contents('php://input') ?: '';
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}
}
