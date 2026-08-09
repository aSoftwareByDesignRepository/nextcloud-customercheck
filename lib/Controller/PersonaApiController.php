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
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\PrepareMeetingService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\PulseService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
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
		private readonly MailDraftService $mailDrafts,
		private readonly PrepareMeetingService $prepare,
		private readonly AccountPlanService $plans,
		private readonly EscalationService $escalations,
		private readonly CirclesRollCallService $circles,
		private readonly CompanyService $companies,
		private readonly \OCA\CustomerCheck\Service\OneOnOneService $oneOnOne,
		private readonly DirectoryOptionsService $directory,
		private readonly SettingsService $settings,
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
			if (!Constants::MULTI_WORKSPACE_ENABLED && $this->workspaceService->listAll() !== []) {
				throw ValidationException::field(
					Constants::ERR_WS_LIMIT,
					'workspace',
					$this->l10n->t('CustomerCheck uses a single organisation. Additional workspaces cannot be created.'),
				);
			}
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
			$raw = [];
			$uids = [];
			foreach ($this->workspaceService->listMembers($workspaceId) as $m) {
				$memberUid = (string)$m->getUserId();
				$uids[] = $memberUid;
				$raw[] = [
					'userId' => $memberUid,
					'role' => (string)$m->getRole(),
					'canMailSend' => method_exists($m, 'canMailSend') ? $m->canMailSend() : ((int)$m->getCanMailSend() === 1),
				];
			}
			$labels = [];
			foreach ($this->directory->resolveUsers($uids) as $row) {
				$labels[(string)$row['id']] = (string)$row['displayName'];
			}
			$items = [];
			foreach ($raw as $row) {
				$memberUid = (string)$row['userId'];
				$items[] = $row + [
					'displayName' => $labels[$memberUid] ?? $memberUid,
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

	/**
	 * One-shot import members from a Nextcloud group (product §6 optional — not live sync).
	 */
	#[NoAdminRequired]
	public function importWorkspaceMembersFromGroup(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$body = $this->requestBody();

			return $this->ok($this->workspaceService->importMembersFromGroup(
				$workspaceId,
				trim((string)($body['groupId'] ?? '')),
				(string)($body['role'] ?? 'member'),
				!array_key_exists('canMailSend', $body) || !empty($body['canMailSend']),
				$uid,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * One-shot import members from a Circles circle (product §6 optional — not live sync).
	 */
	#[NoAdminRequired]
	public function importWorkspaceMembersFromCircle(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$body = $this->requestBody();

			return $this->ok($this->workspaceService->importMembersFromCircle(
				$workspaceId,
				trim((string)($body['circleId'] ?? '')),
				(string)($body['role'] ?? 'member'),
				!array_key_exists('canMailSend', $body) || !empty($body['canMailSend']),
				$uid,
			));
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
			$data = $this->workspaceService->getSettings($workspaceId);
			// Managers see/rotate the per-book ICS URL; viewers only see policy fields.
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'members');
				$data['icsFeedUrl'] = $this->settings->workspaceIcsFeedUrl($workspaceId);
				$data['icsFeedEditable'] = true;
			} catch (\Throwable) {
				$data['icsFeedUrl'] = null;
				$data['icsFeedEditable'] = false;
			}

			return $this->ok($data);
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
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');
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
				return $this->fail('WS_ROLE_DENIED', 'Only app administrators can open the weekly promise check.', [], 403);
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
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');
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
			if (!Constants::MULTI_WORKSPACE_ENABLED) {
				throw ValidationException::field(
					Constants::ERR_WS_LIMIT,
					'workspaceId',
					$this->l10n->t('CustomerCheck uses a single organisation. Companies cannot be moved between workspaces.'),
				);
			}
			$uid = $this->access->currentUserId();
			$body = $this->requestBody();
			$toWs = (int)($body['workspaceId'] ?? 0);
			$confirm = trim((string)($body['confirmSlug'] ?? ''));
			$updatedAt = isset($body['updatedAt']) ? trim((string)$body['updatedAt']) : null;
			if ($updatedAt === '') {
				$updatedAt = null;
			}
			$company = $this->companies->get($companyId);
			$fromWs = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertUserCanAccessCompany($uid, $fromWs, 'move');
			$this->workspaces->assertCapability($uid, $toWs, 'move');
			$result = $this->companies->moveToWorkspace($companyId, $toWs, $confirm, $uid, $updatedAt);
			$updated = $result['company'];
			return $this->ok([
				'id' => (int)$updated->getId(),
				'workspaceId' => (int)$updated->getWorkspaceId(),
				'fromWorkspaceId' => $result['fromWorkspaceId'],
				'toWorkspaceId' => $result['toWorkspaceId'],
				'mailUnmatched' => $result['mailUnmatched'],
				'draftsDetached' => $result['draftsDetached'],
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
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');

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
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');
			$body = $this->requestBody();
			$evidence = trim((string)($body['evidence'] ?? ''));
			$this->promises->complete($id, $wsId, $evidence, $uid, $companyId);

			return $this->ok(['id' => $id, 'status' => 'done']);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function cancelPromise(int $companyId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');
			$this->promises->cancel($id, $wsId, $uid, $companyId);

			return $this->ok(['id' => $id, 'status' => 'cancelled']);
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
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'read');

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
	public function listMailDrafts(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$canSend = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'mail.send', false);
			if ($ctx['member'] !== null && method_exists($ctx['member'], 'canMailSend')) {
				$canSend = $canSend && ($ctx['isAppAdmin'] || $ctx['member']->canMailSend());
			}
			$result = $this->mailDrafts->listFailed(
				$workspaceId,
				(string)$ctx['workspace']->getName(),
			);
			$result['canMailSend'] = $canSend;

			return $this->ok($result);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getMailDraft(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');

			return $this->ok($this->mailDrafts->getFailed($workspaceId, $id));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function sendMailDraft(int $workspaceId, int $id): JSONResponse
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
			$body = $this->requestBody();
			$key = trim((string)($body['idempotencyKey'] ?? ''));

			return $this->ok($this->mailDrafts->resendFailed($workspaceId, $id, $uid, $canSend, $key));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteMailDraft(int $workspaceId, int $id): JSONResponse
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
			if (!$canSend) {
				throw ValidationException::field(
					Constants::ERR_MAIL_SEND_DENIED,
					'mail.send',
					'You do not have permission to send from the shared mailbox.',
				);
			}
			$this->mailDrafts->deleteFailed($workspaceId, $id);

			return $this->ok(['deleted' => true]);
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
