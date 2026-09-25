<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\BriefService;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\PromisesRollCallService;
use OCA\CustomerCheck\Service\CoachingService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\PulseService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
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
		private readonly AccountPlanService $plans,
		private readonly EscalationService $escalations,
		private readonly PromisesRollCallService $promisesRollCall,
		private readonly CompanyService $companies,
		private readonly \OCA\CustomerCheck\Service\OneOnOneService $oneOnOne,
		private readonly DirectoryOptionsService $directory,
		private readonly SettingsService $settings,
		private readonly ReviewService $review,
		private readonly PersonalMailService $personalMail,
		private readonly PersonalMailOAuthService $personalMailOAuth,
		private readonly \OCA\CustomerCheck\Service\RateLimitService $rateLimit,
		private readonly IL10N $l10n,
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
			$body = $this->requestBody();
			$body['actor'] = $uid;

			return $this->ok($this->workspaceService->updateSettings($workspaceId, $body));
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
			$ctx = $this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');
			$canReassign = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'bulk', false);
			return $this->ok($this->plans->upsert($companyId, $this->requestBody(), $uid, $canReassign));
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
				'nextAssignOn' => null,
				'status' => null,
				'mapSteps' => [],
				'milestones' => [],
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function promisesRollCall(): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			if (!$this->access->isAppAdmin($uid)) {
				return $this->fail('WS_ROLE_DENIED', 'Only app administrators can open the weekly promise check.', [], 403);
			}
			return $this->ok(['items' => $this->promisesRollCall->build()]);
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
	public function completeMapStep(int $companyId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$company = $this->companies->get($companyId);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');
			return $this->ok($this->plans->setMapStepStatus($companyId, $id, $wsId, 'done'));
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
			$ctx = $this->workspaces->assertUserCanAccessCompany($uid, $wsId, 'write');
			$canReassign = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'bulk', false);

			return $this->ok($this->promises->create($companyId, $this->requestBody(), $uid, $canReassign));
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
	public function mailboxes(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$boxes = $this->mailbox->listMailboxes($workspaceId, \OCA\CustomerCheck\Service\MailAclLogic::KIND_TEAM);
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
	public function personalMailboxes(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$settings = $this->workspaceService->getSettings($workspaceId);

			return $this->ok([
				'personalMailEnabled' => !empty($settings['personalMailEnabled']),
				'items' => $this->personalMail->listMine($workspaceId, $uid),
				'oauthProviders' => $this->personalMailOAuth->providerStatus(),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function connectPersonalMailbox(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');

			return $this->ok($this->personalMail->connectImap($workspaceId, $uid, $this->requestBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function savePersonalMailboxFolders(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$body = $this->requestBody();
			$folders = isset($body['folders']) && is_array($body['folders']) ? $body['folders'] : [];

			return $this->ok($this->personalMail->saveFolders($workspaceId, $id, $uid, $folders));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function refreshPersonalMailboxFolders(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			if (!$this->rateLimit->allow(
				'pm_sync:' . $uid,
				Constants::PERSONAL_MAIL_SYNC_USER_LIMIT,
				Constants::PERSONAL_MAIL_SYNC_USER_WINDOW,
				true,
			)) {
				throw ValidationException::field(
					Constants::ERR_RATE_LIMITED,
					'rate',
					$this->l10n->t('Folder refresh rate limit reached. Try again later.'),
				);
			}

			return $this->ok($this->personalMail->refreshFolders($workspaceId, $id, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function disconnectPersonalMailbox(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$this->personalMail->disconnect($workspaceId, $id, $uid);

			return $this->ok(['deleted' => true, 'id' => $id]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function syncPersonalMailbox(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$this->personalMail->assertPersonalMailEnabled($workspaceId);
			if (!$this->rateLimit->allow(
				'pm_sync:' . $uid,
				Constants::PERSONAL_MAIL_SYNC_USER_LIMIT,
				Constants::PERSONAL_MAIL_SYNC_USER_WINDOW,
				true,
			)) {
				throw ValidationException::field(
					Constants::ERR_RATE_LIMITED,
					'rate',
					$this->l10n->t('Sync rate limit reached. Try again later.'),
				);
			}
			$mine = $this->personalMail->listMine($workspaceId, $uid);
			$owned = false;
			foreach ($mine as $box) {
				if ((int)$box['id'] === $id) {
					$owned = true;
					break;
				}
			}
			if (!$owned) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'id', $this->l10n->t('Personal mailbox not found.'));
			}
			$result = $this->mailboxSync->syncOne($id, $workspaceId);

			return $this->ok($result);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function savePersonalMailboxSignature(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$body = $this->requestBody();

			return $this->ok($this->personalMail->saveSignature($workspaceId, $id, $uid, $body));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveTeamMailboxSignature(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mailbox');
			$body = $this->requestBody();

			return $this->ok($this->mailbox->saveTeamSignature($workspaceId, $id, $uid, $body));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function mailInbox(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$tab = (string)$this->request->getParam('tab', 'mine');
			$limit = (int)$this->request->getParam('limit', 50);
			$mailboxId = (int)$this->request->getParam('mailboxId', 0);
			$cursorRaw = $this->request->getParam('cursor', null);
			$cursor = is_string($cursorRaw) ? $cursorRaw : null;
			$canTriage = false;
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'write');
				$canTriage = true;
			} catch (\Throwable) {
				$canTriage = false;
			}

			return $this->ok($this->personalMail->listInbox($workspaceId, $uid, $limit, $tab, $mailboxId, $canTriage, $cursor));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function mailSendAccounts(int $workspaceId): JSONResponse
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

			return $this->ok(['items' => $this->mailSend->listSendAccounts($workspaceId, $uid, $canSend)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function beginPersonalMailOAuth(int $workspaceId, string $provider): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');

			return $this->ok($this->personalMailOAuth->beginAuthorize($workspaceId, $uid, $provider, $this->requestBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * OAuth redirect landing — exchanges code (web) or stays passive for companion AuthSession.
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function personalMailOAuthCallback(): JSONResponse|\OCP\AppFramework\Http\RedirectResponse|\OCP\AppFramework\Http\DataDisplayResponse
	{
		try {
			$sessionUid = $this->access->currentUserId();
			$state = (string)$this->request->getParam('state', '');
			$code = (string)$this->request->getParam('code', '');
			// Companion AuthSession uses the same redirect URI; do not burn state/code here.
			if ($this->personalMailOAuth->isNativeOAuthState($state)) {
				$msg = $this->l10n->t('Sign-in complete. You can return to the CustomerCheck app.');
				$lang = method_exists($this->l10n, 'getLanguageCode') ? (string)$this->l10n->getLanguageCode() : 'en';
				if (!preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]+)*$/', $lang)) {
					$lang = 'en';
				}
				$html = '<!DOCTYPE html><html lang="' . htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><head><meta charset="utf-8">'
					. '<meta name="viewport" content="width=device-width, initial-scale=1">'
					. '<title>' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
					. '<meta name="color-scheme" content="light dark">'
					. '<style>html{color-scheme:light dark}'
					. 'body{font-family:system-ui,sans-serif;margin:2rem;line-height:1.5;color:CanvasText;background:Canvas}'
					. 'p{max-width:36rem;font-size:1.125rem}</style></head><body>'
					. '<p role="status">' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
					. '</body></html>';

				return new \OCP\AppFramework\Http\DataDisplayResponse(
					$html,
					\OCP\AppFramework\Http::STATUS_OK,
					['Content-Type' => 'text/html; charset=UTF-8'],
				);
			}
			$settingsUrl = \OC::$server->get(\OCP\IURLGenerator::class)
				->linkToRouteAbsolute('customercheck.page.settingsSection', ['section' => 'my-email']);
			$sep = str_contains($settingsUrl, '?') ? '&' : '?';
			$box = $this->personalMailOAuth->completeAuthorize($state, $code, $sessionUid);

			return new \OCP\AppFramework\Http\RedirectResponse(
				$settingsUrl . $sep . 'personalMail=connected&mailboxId=' . (int)($box['id'] ?? 0),
			);
		} catch (\Throwable $e) {
			$settingsUrl = \OC::$server->get(\OCP\IURLGenerator::class)
				->linkToRouteAbsolute('customercheck.page.settingsSection', ['section' => 'my-email']);
			$sep = str_contains($settingsUrl, '?') ? '&' : '?';
			$msg = rawurlencode($e->getMessage());

			return new \OCP\AppFramework\Http\RedirectResponse($settingsUrl . $sep . 'personalMailError=' . $msg);
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
	public function deleteMailbox(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mailbox');
			$this->mailboxSync->deleteMailbox($workspaceId, $id);
			return $this->ok(['deleted' => true, 'id' => $id]);
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
			$body = $this->requestBody();
			$body['workspaceId'] = $workspaceId;
			return $this->ok($this->mailboxSync->testConnection($body));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function syncMailbox(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mailbox');
			if ($id <= 0) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'id',
					$this->l10n->t('Choose a mailbox to sync.'),
				);
			}
			if ($this->mailbox->resolveMailboxKind($workspaceId, $id) === MailAclLogic::KIND_PERSONAL) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'id',
					$this->l10n->t('Sync personal mail from My email or Inbox — not the team mailbox sync.'),
				);
			}
			$result = $this->mailboxSync->syncOne($id, $workspaceId);
			$message = match ((string)($result['status'] ?? '')) {
				'ok' => $this->l10n->n(
					'Synced %n message into the CRM index.',
					'Synced %n messages into the CRM index.',
					(int)($result['ingested'] ?? 0),
				),
				'locked' => $this->l10n->t('Sync already running for this mailbox. Try again in a moment.'),
				'missing' => $this->l10n->t('Mailbox not found in this workspace.'),
				'auth', 'error' => $this->l10n->t('IMAP login failed during sync. Test the connection and save again.'),
				'no_imap' => $this->l10n->t('This server cannot sync that mailbox encryption mode without the PHP IMAP extension.'),
				default => $this->l10n->t('Sync finished with status: %s', [(string)($result['status'] ?? 'unknown')]),
			};

			return $this->ok([
				'ingested' => (int)($result['ingested'] ?? 0),
				'status' => (string)($result['status'] ?? ''),
				'message' => $message,
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function syncAllMailboxes(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');

			$result = $this->mailboxSync->syncWorkspace($workspaceId);
			$sources = $this->workspaceService->getReviewSources($workspaceId);
			if (!empty($sources['shared_mail']['enabled'])) {
				$result = array_merge($result, $this->review->postMailboxSync($workspaceId));
				$result['message'] = $this->formatMailboxSyncSummary($result);
			}

			return $this->ok($result);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function sendMail(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			// Personal From: any member with write may send as their own account.
			// Team From: still requires mail.send + member can_mail_send (enforced in MailSendService).
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
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
				$uid,
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

			return $this->ok($this->mailDrafts->getFailed($workspaceId, $id, $uid));
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
			$this->mailDrafts->deleteFailed($workspaceId, $id, $uid);

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
			$canTriage = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'write', false);
			$items = $this->mailbox->unmatched($workspaceId);
			// AS-12: personal unmatched content (subject/from/snippet/preview) only for triage/write.
			if (!$canTriage) {
				$needsTriage = $this->l10n->t('Needs triage');
				foreach ($items as &$item) {
					$item = MailAclLogic::redactPersonalUnmatchedListItem($item, $needsTriage);
				}
				unset($item);
			}

			return $this->ok([
				'items' => $items,
				'disclosure' => $this->mailbox->disclosure('shared', (string)$ctx['workspace']->getName()),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * On-demand plain-text body preview for an indexed shared-mail message.
	 * POST-only: may dial IMAP and cache plain text — side-effectful; CSRF via requesttoken.
	 * Does not return HTML or attachments — full bodies stay on the IMAP server.
	 */
	#[NoAdminRequired]
	public function previewMail(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$canReadCompany = false;
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'read');
				$canReadCompany = true;
			} catch (\Throwable) {
				$canReadCompany = false;
			}
			// AS-12: personal unmatched shared bodies need write/triage, not bare mail.read.
			$canTriage = false;
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'write');
				$canTriage = true;
			} catch (\Throwable) {
				$canTriage = false;
			}

			return $this->ok($this->mailboxSync->previewIndexedMessage(
				$workspaceId,
				$id,
				$uid,
				$canReadCompany,
				$canTriage,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Answer helper: reply compose pack for mailto / Nextcloud Mail / in-app send.
	 */
	#[NoAdminRequired]
	public function replyMail(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$canReadCompany = false;
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'read');
				$canReadCompany = true;
			} catch (\Throwable) {
				$canReadCompany = false;
			}
			$canTriage = false;
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'write');
				$canTriage = true;
			} catch (\Throwable) {
				$canTriage = false;
			}

			return $this->ok($this->mailboxSync->replyComposeIndexedMessage(
				$workspaceId,
				$id,
				$uid,
				$canReadCompany,
				$canTriage,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function triageMail(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			// ARGUS MF-01: matching shared mail mutates CRM + company feed — write required.
			// mail.read alone (viewer) may preview/reply-compose but must not triage/assign.
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$body = $this->requestBody();
			$this->mailbox->triageAssign($id, $workspaceId, (int)($body['companyId'] ?? 0));
			return $this->ok(['ok' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Inbox Personal/Team — permanently delete on the mail host (when live UID exists).
	 * Write + mail.read; personal dials only for the mailbox owner.
	 */
	#[NoAdminRequired]
	public function deleteMail(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$canReadCompany = false;
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'read');
				$canReadCompany = true;
			} catch (\Throwable) {
				$canReadCompany = false;
			}

			return $this->ok($this->mailboxSync->deleteIndexedMessageFromInbox(
				$workspaceId,
				$id,
				$uid,
				$canReadCompany,
				true,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Inbox Personal/Team — move to Junk/Spam on the mail host (when live UID exists).
	 */
	#[NoAdminRequired]
	public function markSpamMail(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'mail.read');
			$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			$canReadCompany = false;
			try {
				$this->workspaces->assertCapability($uid, $workspaceId, 'read');
				$canReadCompany = true;
			} catch (\Throwable) {
				$canReadCompany = false;
			}

			return $this->ok($this->mailboxSync->markIndexedMessageSpamFromInbox(
				$workspaceId,
				$id,
				$uid,
				$canReadCompany,
				true,
			));
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

	/**
	 * @param array<string, mixed> $result
	 */
	private function formatMailboxSyncSummary(array $result): string
	{
		$parts = [];
		$ingested = (int)($result['ingested'] ?? 0);
		$refreshed = (int)($result['refreshed'] ?? 0);
		$filed = (int)($result['filed'] ?? 0);
		$promoted = (int)($result['promoted'] ?? 0);
		$reopened = (int)($result['reopened'] ?? 0);
		if ($ingested > 0) {
			$parts[] = $this->l10n->n('Synced %n new message.', 'Synced %n new messages.', $ingested);
		}
		if ($refreshed > 0) {
			$parts[] = $this->l10n->n(
				'Updated %n existing message for matching.',
				'Updated %n existing messages for matching.',
				$refreshed,
			);
		}
		if ($filed > 0) {
			$parts[] = $this->l10n->n(
				'Filed %n message on known customers.',
				'Filed %n messages on known customers.',
				$filed,
			);
		}
		if ($promoted > 0) {
			$parts[] = $this->l10n->n('Added %n message to Assign.', 'Added %n messages to Assign.', $promoted);
		}
		if ($reopened > 0) {
			$parts[] = $this->l10n->n(
				'Reopened %n message from archived companies.',
				'Reopened %n messages from archived companies.',
				$reopened,
			);
		}
		if ($parts !== []) {
			return implode(' ', $parts);
		}

		return (string)($result['message'] ?? '');
	}
}
