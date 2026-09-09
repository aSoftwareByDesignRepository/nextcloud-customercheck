<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\MobileGateException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyDocumentsService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealChecklistService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\MobileApiService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Companion mobile API v1 (COMPANION-APP §9) — `/apps/customercheck/api/mobile/*`.
 *
 * Auth: HTTP Basic with an app password (Login Flow v2). CSRF posture:
 * NoCSRFRequired on every route because token-auth clients have no session
 * cookie; mutations still reject cookie-only requests without a valid
 * requesttoken (see {@see assertSafeMutationChannel}), so a browser session
 * cannot be ridden through these endpoints.
 *
 * Gate ladder (per request):
 *   1 NC auth        → 401 AUTH_REQUIRED
 *   2 canUseApp      → 403 ACCESS_DENIED (AppAccessMiddleware)
 *   3–6 license/seat → 402 (MobileGateService; skipped for bootstrap)
 *   7 workspace ACL  → 404 on foreign book / object (product §3.3 / §21.1)
 *
 * Data routes are path-scoped under `/workspaces/{workspaceId}/…`. The client
 * must send an explicit workspace on every call — never rely on last-used alone
 * (avoids switch races blending two books).
 */
class MobileController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaceAccess,
		private readonly MobileGateService $gate,
		private readonly MobileApiService $mobile,
		private readonly CompanyService $companies,
		private readonly PersonService $persons,
		private readonly ActivityService $activities,
		private readonly DealService $deals,
		private readonly AgreementService $agreements,
		private readonly MobileIdempotencyService $idempotency,
		private readonly MobilePushService $push,
		private readonly CompanyDocumentsService $documents,
		private readonly WorkspaceService $workspaceService,
		private readonly IUserManager $userManager,
		private readonly IAppManager $appManager,
		private readonly ?DealChecklistService $dealChecklists = null,
	) {
		parent::__construct($appName, $request);
	}

	// ── Bootstrap (no license gate — feeds LicenseGate copy) ────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		return $this->guard(function (): JSONResponse {
			$uid = $this->requireUid();
			$displayName = $this->userSession->getUser()?->getDisplayName() ?? $uid;
			$version = $this->appManager->getAppVersion(Application::APP_ID, false);

			return new JSONResponse($this->gate->bootstrapPayload($uid, $displayName, $version));
		});
	}

	// ── Workspaces ──────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workspaces(): JSONResponse
	{
		return $this->guard(function (): JSONResponse {
			$uid = $this->requireGatedUid();

			return $this->success($this->workspaceAccess->listForMobile($uid));
		});
	}

	// ── Today ───────────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function today(int $workspaceId): JSONResponse
	{
		return $this->guard(function () use ($workspaceId): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			$today = $this->mobile->today($wsId, $uid);

			return $this->success(['items' => $today['items']], $today['meta']);
		});
	}

	// ── Companies ───────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companies(int $workspaceId, string $q = ''): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $q): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			// Avoid typed `$limit` method params — NC 34 Dispatcher rejects out-of-range
			// int `$limit` with uncaught ParameterOutOfRangeException (HTML 500).
			$limit = $this->clampQueryLimit(25, 100);
			$offset = max(0, (int)$this->request->getParam('offset', 0));
			$filters = ['search' => trim($q), 'archived' => false, 'workspaceId' => $wsId];
			$rows = $this->companies->search($filters, $limit, $offset);
			$total = $this->companies->count($filters);

			return $this->success(
				array_map([MobileApiService::class, 'serialiseCompanyRow'], $rows),
				['total' => $total, 'limit' => $limit, 'offset' => $offset],
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyDetail(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			$company = $this->requireCompanyInWorkspace($id, $wsId);

			return $this->success($this->mobile->companyRoadDetail($company, $uid));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createCompany(int $workspaceId): JSONResponse
	{
		return $this->guard(function () use ($workspaceId): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$body = $this->jsonBody();
			$body['workspaceId'] = $wsId;
			// Folder / cross-app / owner binding — never mass-assign from the companion.
			unset(
				$body['filesFolderId'],
				$body['filesFolderPath'],
				$body['accountOwnerUserId'],
				$body['pcCustomerId'],
				$body['tcCustomerId'],
			);
			$company = $this->idempotency->once(
				$uid,
				$this->scopedIdempotencyKey($body, 'co-create', '', $wsId),
				fn () => $this->companies->create($body, $uid),
				static fn ($c): int => (int)$c->getId(),
				fn (int $cid) => $this->loadCompanyForIdempotentReplay($cid, $wsId),
			);

			return $this->success(
				$this->mobile->companyRoadDetail($company, $uid),
				null,
				Http::STATUS_CREATED,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateCompany(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsCtx = $this->requireMobileWorkspaceContext($uid, $workspaceId, 'write');
			$wsId = $wsCtx['workspaceId'];
			$this->requireCompanyInWorkspace($id, $wsId);
			$body = $this->jsonBody();
			unset($body['workspaceId'], $body['filesFolderId'], $body['filesFolderPath']);
			$canReassign = $wsCtx['isAppAdmin']
				|| WorkspaceCapability::allows($wsCtx['role'], 'bulk', false);
			$company = $this->companies->update($id, $body, $uid, $this->preconditionFrom($body), $canReassign);

			return $this->success($this->mobile->companyRoadDetail($company, $uid));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createPerson(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$this->requireCompanyInWorkspace($id, $wsId);
			$body = $this->jsonBody();
			$person = $this->idempotency->once(
				$uid,
				$this->scopedIdempotencyKey($body, 'pe-create', '', $wsId, $id),
				fn () => $this->persons->create($id, $body, $uid),
				static fn ($p): int => (int)$p->getId(),
				fn (int $pid) => $this->loadPersonForIdempotentReplay($pid, $wsId, $id),
			);

			return $this->success(MobileApiService::serialisePerson($person), null, Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updatePerson(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$person = $this->persons->get($id);
			$this->requireCompanyInWorkspace((int)$person->getCompanyId(), $wsId);
			$body = $this->jsonBody();
			$updated = $this->persons->update($id, $body, $uid, $this->preconditionFrom($body));

			return $this->success(MobileApiService::serialisePerson($updated));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createDeal(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsCtx = $this->requireMobileWorkspaceContext($uid, $workspaceId, 'write');
			$wsId = $wsCtx['workspaceId'];
			$this->requireCompanyInWorkspace($id, $wsId);
			$body = $this->jsonBody();
			$body['companyId'] = $id;
			// Mirror update's reassignment gate — create must not silently assign foreign owners.
			$canReassign = $wsCtx['isAppAdmin']
				|| WorkspaceCapability::allows($wsCtx['role'], 'bulk', false);
			if (!$canReassign) {
				unset($body['ownerUserId']);
			}
			$deal = $this->idempotency->once(
				$uid,
				$this->scopedIdempotencyKey($body, 'de-create', '', $wsId, $id),
				fn () => $this->deals->create($body, $uid),
				static fn ($d): int => (int)$d->getId(),
				fn (int $did) => $this->loadDealForIdempotentReplay($did, $wsId, $id),
			);

			return $this->success(
				MobileApiService::serialiseDeal($deal, fn ($d): bool => $this->deals->isStale($d), $this->deals->staleDealDays()),
				null,
				Http::STATUS_CREATED,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyFiles(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			$this->requireCompanyInWorkspace($id, $wsId);
			$listing = $this->documents->listChildren($id, $uid, false);
			$files = [];
			foreach ($listing['items'] as $item) {
				if (!empty($item['isFolder'])) {
					continue;
				}
				$files[] = [
					'fileId' => (int)$item['fileId'],
					'name' => (string)$item['name'],
					'size' => (int)($item['size'] ?? 0),
					'mtime' => (int)($item['mtime'] ?? 0),
				];
			}

			return $this->success($files, [
				'broken' => !empty($listing['broken']),
				'path' => $listing['path'] ?? null,
			]);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function members(int $workspaceId): JSONResponse
	{
		return $this->guard(function () use ($workspaceId): JSONResponse {
			$uid = $this->requireGatedUid();
			// Writers (owner pickers) only — viewers must not enumerate uids/roles.
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$out = [];
			foreach ($this->workspaceService->listMembers($wsId) as $member) {
				$userId = (string)$member->getUserId();
				$display = $this->userManager->get($userId)?->getDisplayName() ?? $userId;
				$out[] = [
					'userId' => $userId,
					'displayName' => $display,
					'role' => (string)$member->getRole(),
				];
			}

			return $this->success($out);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function checklistTemplates(int $workspaceId): JSONResponse
	{
		return $this->guard(function () use ($workspaceId): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			if ($this->dealChecklists === null) {
				throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
			}

			return $this->success($this->dealChecklists->listTemplates($wsId));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dealChecklist(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			$this->requireDealInWorkspace($id, $wsId);
			if ($this->dealChecklists === null) {
				throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
			}
			$listed = $this->dealChecklists->listForDeal($id, $uid);

			return $this->success($listed['items'] ?? []);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function applyChecklistTemplate(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$this->requireDealInWorkspace($id, $wsId);
			if ($this->dealChecklists === null) {
				throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
			}
			$body = $this->jsonBody();
			$tmplId = (int)($body['templateId'] ?? 0);
			$mode = strtolower(trim((string)($body['mode'] ?? 'append')));
			if ($mode !== 'replace') {
				$mode = 'append';
			}
			$listed = $this->idempotency->once(
				$uid,
				$this->scopedIdempotencyKey($body, 'cl-apply', 'tmpl:' . $tmplId . '|mode:' . $mode, $wsId, $id),
				fn () => $this->dealChecklists->applyTemplate($id, $body, $uid),
				static fn (array $row): int => (int)($row['dealId'] ?? $id),
				fn (int $dealId): array => $this->loadDealChecklistForIdempotentReplay($dealId, $wsId, $id, $uid),
			);
			// once() committed — safe to fan-out deferred assign pushes from nested apply.
			$this->dealChecklists->flushPendingAssignNotifies($listed);

			return $this->success(
				$listed['items'] ?? [],
				[
					'mode' => $listed['mode'] ?? null,
					'inserted' => (int)($listed['inserted'] ?? 0),
				],
				Http::STATUS_CREATED,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateChecklistItem(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			if ($this->dealChecklists === null) {
				throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
			}
			$item = $this->dealChecklists->requireItemForGate($id);
			$this->requireCompanyInWorkspace((int)$item->getCompanyId(), $wsId);
			$body = $this->jsonBody();

			return $this->success($this->dealChecklists->updateItem(
				$id,
				$body,
				$uid,
				$this->preconditionFrom($body),
			));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function linkChecklistFile(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			if ($this->dealChecklists === null) {
				throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
			}
			$item = $this->dealChecklists->requireItemForGate($id);
			$this->requireCompanyInWorkspace((int)$item->getCompanyId(), $wsId);
			$body = $this->jsonBody();
			$fileId = (int)($body['fileId'] ?? 0);
			$payload = $this->idempotency->once(
				$uid,
				$this->scopedIdempotencyKey($body, 'cl-link', 'file:' . $fileId, $wsId, $id),
				function () use ($id, $fileId, $uid): array {
					$this->dealChecklists->linkFile($id, $fileId, $uid);

					return $this->checklistItemPayload($id);
				},
				static fn (array $row): int => (int)($row['id'] ?? $id),
				fn (int $itemId): array => $this->checklistItemPayloadForIdempotentReplay($itemId, $wsId, $id),
			);

			return $this->success($payload, null, Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadChecklistFile(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			if ($this->dealChecklists === null) {
				throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
			}
			$item = $this->dealChecklists->requireItemForGate($id);
			$companyId = (int)$item->getCompanyId();
			$this->requireCompanyInWorkspace($companyId, $wsId);

			$uploaded = $this->request->getUploadedFile('file');
			if (!is_array($uploaded) || empty($uploaded['tmp_name']) || !is_uploaded_file((string)$uploaded['tmp_name'])) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'file',
					'Pick a file to upload.',
				);
			}
			$maxBytes = 20 * 1024 * 1024;
			$size = (int)($uploaded['size'] ?? 0);
			if ($size < 1 || $size > $maxBytes) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'file',
					'File must be between 1 byte and 20 MB.',
				);
			}
			$binary = file_get_contents((string)$uploaded['tmp_name']);
			if ($binary === false || $binary === '') {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'file',
					'Could not read the uploaded file.',
				);
			}
			$name = (string)($uploaded['name'] ?? 'upload.bin');
			$body = $this->jsonBody();
			$bytesDigest = hash('sha256', $binary);
			$key = $this->scopedIdempotencyKey($body, 'cl-upload', 'bytes:' . $bytesDigest, $wsId, $id);
			// Replay without re-upload — Files I/O must not sit inside the ledger TX.
			// Argus: never return another item's payload for a reused key.
			$existingItemId = $this->idempotency->findResourceId($uid, $key);
			if ($existingItemId !== null) {
				return $this->success(
					$this->checklistItemPayloadForIdempotentReplay($existingItemId, $wsId, $id),
					null,
					Http::STATUS_CREATED,
				);
			}
			$stored = $this->documents->uploadIntoCompanyFolder($companyId, $uid, $name, $binary);
			$fileId = (int)($stored['fileId'] ?? 0);
			try {
				$payload = $this->idempotency->once(
					$uid,
					$key,
					function () use ($fileId, $uid, $id): array {
						$this->dealChecklists->linkFile($id, $fileId, $uid);

						return $this->checklistItemPayload($id);
					},
					static fn (array $row): int => (int)($row['id'] ?? $id),
					fn (int $itemId): array => $this->checklistItemPayloadForIdempotentReplay($itemId, $wsId, $id),
				);
			} catch (\Throwable $e) {
				$this->documents->tryDeleteUploadedFile($uid, $fileId);
				throw $e;
			}
			// Drop orphan bytes when this request's upload was not linked:
			// - race loser (once() returned prior winner without linking our fileId)
			// - unique-ledger race after factory (link rolled back with TX)
			if (!$this->checklistPayloadLinksFileId($payload, $fileId)) {
				$this->documents->tryDeleteUploadedFile($uid, $fileId);
			}

			return $this->success($payload, null, Http::STATUS_CREATED);
		});
	}

	// ── Activities ──────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function activities(int $workspaceId, int $open = 1, int $companyId = 0): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $open, $companyId): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			if ($companyId > 0) {
				$this->requireCompanyInWorkspace($companyId, $wsId);
			}
			$rows = $this->activities->searchForMobile($uid, [
				'openOnly' => $open === 1,
				'companyId' => $companyId,
				'workspaceId' => $wsId,
			], $this->clampQueryLimit(100, 500));

			return $this->success(array_map([MobileApiService::class, 'serialiseActivity'], $rows));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createActivity(int $workspaceId): JSONResponse
	{
		return $this->guard(function () use ($workspaceId): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$body = MobileApiService::normaliseActivityBody($this->jsonBody());
			$companyId = (int)($body['companyId'] ?? 0);
			$this->requireCompanyInWorkspace($companyId, $wsId);
			$activity = $this->idempotency->createActivity(
				$uid,
				$body,
				$this->scopedIdempotencyKey($body, 'act-create', '', $wsId, $companyId),
			);

			return $this->success(
				MobileApiService::serialiseActivity($activity),
				null,
				Http::STATUS_CREATED,
			);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function registerPush(): JSONResponse
	{
		return $this->guard(function (): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$body = $this->jsonBody();
			$this->push->register(
				$uid,
				(string)($body['token'] ?? ''),
				(string)($body['platform'] ?? ''),
			);

			return $this->success(['registered' => true]);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function unregisterPush(): JSONResponse
	{
		return $this->guard(function (): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$body = $this->jsonBody();
			$token = isset($body['token']) && is_string($body['token']) ? $body['token'] : null;
			$this->push->unregister($uid, $token);

			return $this->success(['registered' => false]);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateActivity(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$this->requireActivityInWorkspace($id, $wsId);
			$this->activities->requireMobileAccess($id, $uid);
			$body = MobileApiService::normaliseActivityBody($this->jsonBody());
			if (isset($body['companyId'])) {
				$this->requireCompanyInWorkspace((int)$body['companyId'], $wsId);
			}

			return $this->success(MobileApiService::serialiseActivity(
				$this->activities->update($id, $body, $uid, $this->preconditionFrom($body)),
			));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function doneActivity(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$this->requireActivityInWorkspace($id, $wsId);
			$this->activities->requireMobileAccess($id, $uid);
			$body = $this->jsonBody();

			return $this->success(MobileApiService::serialiseActivity(
				$this->activities->setDone($id, ($body['done'] ?? true) !== false, $uid, $this->preconditionFrom($body)),
			));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function doneChecklistItem(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			if ($this->dealChecklists === null) {
				throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
			}
			$item = $this->dealChecklists->requireItemForGate($id);
			// Company workspace is authoritative — never trust denormalised item.workspace_id alone
			// (stale after move would let old-book writers Done a company that left).
			$this->requireCompanyInWorkspace((int)$item->getCompanyId(), $wsId);
			$body = $this->jsonBody();

			return $this->success($this->dealChecklists->completeItem(
				$id,
				$body,
				$uid,
				$this->preconditionFrom($body),
			));
		});
	}

	// ── Deals ───────────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deals(int $workspaceId, int $companyId = 0): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $companyId): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			if ($companyId > 0) {
				$this->requireCompanyInWorkspace($companyId, $wsId);
			}
			$rows = $this->deals->search([
				'openOnly' => true,
				'companyId' => $companyId,
				'workspaceId' => $wsId,
			], $this->clampQueryLimit(200, 500));

			return $this->success(array_map(
				fn ($deal): array => MobileApiService::serialiseDeal($deal, fn ($d): bool => $this->deals->isStale($d), $this->deals->staleDealDays()),
				$rows,
			));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dealStage(int $workspaceId, int $id): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $id): JSONResponse {
			$this->assertSafeMutationChannel();
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId, 'write');
			$this->requireDealInWorkspace($id, $wsId);
			$body = $this->jsonBody();
			$result = $this->deals->changeStage(
				$id,
				(string)($body['stage'] ?? ''),
				isset($body['lostReason']) ? (string)$body['lostReason'] : null,
				$this->preconditionFrom($body),
				$uid,
				$body,
			);
			$data = MobileApiService::serialiseDeal($result['deal'], fn ($d): bool => $this->deals->isStale($d), $this->deals->staleDealDays());
			$data['sequencesFired'] = $result['sequencesFired'];

			return $this->success($data);
		});
	}

	// ── Agreements ──────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function agreements(int $workspaceId, int $expiringWithinDays = 60, int $companyId = 0): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $expiringWithinDays, $companyId): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			if ($companyId > 0) {
				$this->requireCompanyInWorkspace($companyId, $wsId);
			}
			$filters = ['companyId' => $companyId, 'workspaceId' => $wsId];
			if ($expiringWithinDays > 0) {
				$horizon = new \DateTimeImmutable(sprintf('+%d days', min(3660, $expiringWithinDays)));
				$filters['expiringBefore'] = $horizon;
			}
			$rows = $this->agreements->search($filters, $this->clampQueryLimit(200, 500));

			return $this->success(array_map([MobileApiService::class, 'serialiseAgreement'], $rows));
		});
	}

	// ── Plumbing ────────────────────────────────────────────────────────

	/**
	 * Reload a checklist item DTO (with files) after link/upload.
	 *
	 * @return array<string, mixed>
	 */
	private function checklistItemPayload(int $itemId): array
	{
		if ($this->dealChecklists === null) {
			throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
		}
		$item = $this->dealChecklists->requireItemForGate($itemId);
		$listed = $this->dealChecklists->listForDeal((int)$item->getDealId());
		foreach ($listed['items'] ?? [] as $row) {
			if ((int)($row['id'] ?? 0) === $itemId) {
				return $row;
			}
		}

		throw new NotFoundException('Checklist item not found', Constants::ERR_NOT_FOUND);
	}

	/**
	 * True when the item DTO already lists this NC file id (upload was linked).
	 *
	 * @param array<string, mixed> $payload
	 */
	private function checklistPayloadLinksFileId(array $payload, int $fileId): bool
	{
		if ($fileId < 1) {
			return false;
		}
		$files = $payload['files'] ?? null;
		if (!is_array($files)) {
			return false;
		}
		foreach ($files as $file) {
			if (!is_array($file)) {
				continue;
			}
			if ((int)($file['fileId'] ?? 0) === $fileId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Explicit workspace membership for companion routes (product §3.3).
	 * Missing / foreign workspace → 404 (no existence leak).
	 *
	 * @return int resolved workspace id
	 */
	/**
	 * @return array{workspaceId: int, role: string, isAppAdmin: bool}
	 */
	private function requireMobileWorkspaceContext(string $uid, int $workspaceId, string $capability = 'read'): array
	{
		if ($workspaceId <= 0) {
			throw new NotFoundException('Workspace not found.');
		}
		// Path-scoped companion routes: never rewrite ambient last-used (assert + resolve).
		$this->workspaceAccess->assertCapability($uid, $workspaceId, $capability, false);
		$ctx = $this->workspaceAccess->resolveForUser($uid, $workspaceId, false);

		return [
			'workspaceId' => (int)$ctx['workspace']->getId(),
			'role' => (string)$ctx['role'],
			'isAppAdmin' => (bool)$ctx['isAppAdmin'],
		];
	}

	private function requireMobileWorkspace(string $uid, int $workspaceId, string $capability = 'read'): int
	{
		return $this->requireMobileWorkspaceContext($uid, $workspaceId, $capability)['workspaceId'];
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireCompanyInWorkspace(int $companyId, int $workspaceId): Company
	{
		if ($companyId <= 0) {
			throw new NotFoundException('Company not found.');
		}
		$company = $this->companies->get($companyId);
		$this->workspaceAccess->assertCompanyInWorkspace(
			(int)($company->getWorkspaceId() ?? 0),
			$workspaceId,
		);

		return $company;
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireActivityInWorkspace(int $activityId, int $workspaceId): void
	{
		$activity = $this->activities->get($activityId);
		$this->requireCompanyInWorkspace((int)$activity->getCompanyId(), $workspaceId);
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireDealInWorkspace(int $dealId, int $workspaceId): void
	{
		$deal = $this->deals->get($dealId);
		$this->requireCompanyInWorkspace((int)$deal->getCompanyId(), $workspaceId);
	}

	/**
	 * Clamp query `limit` without a typed `$limit` method parameter.
	 * NC 34 Dispatcher auto-rejects out-of-range int `$limit` with HTML 500.
	 */
	private function clampQueryLimit(int $default, int $max): int
	{
		$raw = $this->request->getParam('limit', $default);
		if ($raw === null || $raw === '') {
			return max(1, min($max, $default));
		}

		return max(1, min($max, (int)$raw));
	}

	/**
	 * @param callable(): JSONResponse $fn
	 */
	private function guard(callable $fn): JSONResponse
	{
		try {
			return $fn();
		} catch (MobileGateException $e) {
			return $this->error($e->getErrorCode(), $e->getMessage(), Http::STATUS_PAYMENT_REQUIRED);
		} catch (ValidationException $e) {
			return $this->error(
				'VALIDATION',
				$e->getMessage(),
				Http::STATUS_UNPROCESSABLE_ENTITY,
				$e->getFields(),
				$e->getErrorCode(),
			);
		} catch (ConflictException $e) {
			return $this->error(
				$e->getErrorCode() !== '' ? $e->getErrorCode() : Constants::ERR_CONFLICT_STALE,
				$e->getMessage(),
				Http::STATUS_CONFLICT,
				$e->getFields(),
			);
		} catch (NotFoundException $e) {
			return $this->error(
				Constants::ERR_NOT_FOUND,
				$e->getMessage(),
				Http::STATUS_NOT_FOUND,
			);
		} catch (AccessDeniedException | AppAccessDeniedException $e) {
			return $this->error(Constants::ERR_ACCESS_DENIED, $e->getMessage(), Http::STATUS_FORBIDDEN);
		} catch (\OCA\CustomerCheck\Exception\NotAuthenticatedException $e) {
			return $this->error('AUTH_REQUIRED', $e->getMessage(), Http::STATUS_UNAUTHORIZED);
		}
	}

	private function success(mixed $data, ?array $meta = null, int $status = Http::STATUS_OK): JSONResponse
	{
		$payload = ['success' => true, 'data' => $data];
		if ($meta !== null) {
			$payload['meta'] = $meta;
		}

		return new JSONResponse($payload, $status);
	}

	/**
	 * @param array<string, string> $fields
	 */
	private function error(
		string $code,
		string $message,
		int $status,
		array $fields = [],
		?string $detailCode = null,
	): JSONResponse {
		$payload = [
			'success' => false,
			'error' => $code,
			'message' => $message,
			'fields' => $fields === [] ? new \stdClass() : $fields,
		];
		if ($detailCode !== null && $detailCode !== $code) {
			$payload['detail'] = $detailCode;
		}

		return new JSONResponse($payload, $status);
	}

	/**
	 * @throws \OCA\CustomerCheck\Exception\NotAuthenticatedException
	 */
	private function requireUid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \OCA\CustomerCheck\Exception\NotAuthenticatedException('Authentication required.');
		}

		return $user->getUID();
	}

	/**
	 * Rungs 1–6 for data routes.
	 */
	private function requireGatedUid(): string
	{
		$uid = $this->requireUid();
		$this->accessControl->requireCanUseApp();
		$this->gate->assertGatePassed($uid);

		return $uid;
	}

	/**
	 * Mutations accept a valid CSRF requesttoken OR Basic credentials that
	 * authenticate as the already-bound session user (companion app password).
	 *
	 * Junk `Authorization: Bearer x` / `Basic junk` MUST NOT bypass CSRF —
	 * that would let a same-site attacker ride the victim's browser cookie.
	 *
	 * @throws AppAccessDeniedException
	 */
	private function assertSafeMutationChannel(): void
	{
		if ($this->request->passesCSRFCheck()) {
			return;
		}
		if ($this->authorizationBasicAuthenticatesCurrentUser()) {
			return;
		}

		throw new AppAccessDeniedException('CSRF or app password required');
	}

	/**
	 * True only when Authorization Basic credentials checkPassword as the
	 * current session UID (blocks presence-only Bearer/Basic CSRF escapes).
	 */
	private function authorizationBasicAuthenticatesCurrentUser(): bool
	{
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if (preg_match('/^Basic\s+(\S+)$/i', $auth, $m) !== 1) {
			return false;
		}
		$decoded = base64_decode($m[1], true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return false;
		}
		[$login, $secret] = explode(':', $decoded, 2);
		if ($login === '' || $secret === '') {
			return false;
		}
		$current = $this->userSession->getUser();
		if ($current === null) {
			return false;
		}
		$authed = $this->userManager->checkPassword($login, $secret);
		if ($authed === false) {
			return false;
		}

		return hash_equals($current->getUID(), $authed->getUID());
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function preconditionFrom(array $body): ?string
	{
		$header = trim((string)$this->request->getHeader('If-Match-Updated-At'));
		if ($header !== '') {
			return $header;
		}
		if (isset($body['updatedAt']) && is_string($body['updatedAt']) && trim($body['updatedAt']) !== '') {
			return trim($body['updatedAt']);
		}

		return null;
	}

	/**
	 * Bind client Idempotency-Key to this operation + path resources + intent
	 * so a retained key cannot replay across templates/modes/files/bytes
	 * (Aristoteles — intent-blind replay after timeout).
	 *
	 * @param array<string, mixed> $body
	 * @param string $intent Extra binding (e.g. "tmpl:3|mode:append", "file:55", "bytes:<sha256>")
	 */
	private function scopedIdempotencyKey(array $body, string $operation, string $intent = '', int ...$resourceIds): ?string
	{
		$raw = $this->idempotencyKey($body);
		if ($raw === null) {
			return null;
		}
		$parts = [$operation];
		foreach ($resourceIds as $rid) {
			$parts[] = (string)max(0, $rid);
		}
		// One digest: client key + intent (empty intent keeps create-* behaviour).
		$parts[] = hash('sha256', $raw . "\0" . $intent);

		return implode(':', $parts);
	}

	/**
	 * Optional companion outbox key: body.idempotencyKey, Idempotency-Key, or
	 * X-CustomerCheck-Idempotency-Key (COMPANION-APP AC12).
	 *
	 * @param array<string, mixed> $body
	 */
	private function idempotencyKey(array $body): ?string
	{
		foreach (['Idempotency-Key', 'X-CustomerCheck-Idempotency-Key'] as $header) {
			$value = trim((string)$this->request->getHeader($header));
			if ($value !== '') {
				return $value;
			}
		}
		if (isset($body['idempotencyKey']) && is_string($body['idempotencyKey'])) {
			return $body['idempotencyKey'];
		}

		return null;
	}

	private function loadCompanyForIdempotentReplay(int $companyId, int $workspaceId): Company
	{
		$this->requireCompanyInWorkspace($companyId, $workspaceId);

		return $this->companies->get($companyId);
	}

	private function loadPersonForIdempotentReplay(int $personId, int $workspaceId, int $expectedCompanyId): Person
	{
		$person = $this->persons->get($personId);
		if ((int)$person->getCompanyId() !== $expectedCompanyId) {
			throw new NotFoundException('Person not found.', Constants::ERR_NOT_FOUND);
		}
		$this->requireCompanyInWorkspace((int)$person->getCompanyId(), $workspaceId);

		return $person;
	}

	private function loadDealForIdempotentReplay(int $dealId, int $workspaceId, int $expectedCompanyId): Deal
	{
		$deal = $this->deals->get($dealId);
		if ((int)$deal->getCompanyId() !== $expectedCompanyId) {
			throw new NotFoundException('Deal not found.', Constants::ERR_NOT_FOUND);
		}
		$this->requireDealInWorkspace($dealId, $workspaceId);

		return $deal;
	}

	/**
	 * @return array{dealId: int, companyId: int, items: list<array<string, mixed>>}
	 */
	private function loadDealChecklistForIdempotentReplay(
		int $dealId,
		int $workspaceId,
		int $expectedDealId,
		string $uid,
	): array {
		if ($dealId !== $expectedDealId) {
			throw new NotFoundException('Deal not found.', Constants::ERR_NOT_FOUND);
		}
		$this->requireDealInWorkspace($dealId, $workspaceId);
		if ($this->dealChecklists === null) {
			throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
		}

		return $this->dealChecklists->listForDeal($dealId, $uid);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function checklistItemPayloadForIdempotentReplay(
		int $itemId,
		int $workspaceId,
		int $expectedItemId,
	): array {
		if ($itemId !== $expectedItemId) {
			throw new NotFoundException('Checklist item not found', Constants::ERR_NOT_FOUND);
		}
		if ($this->dealChecklists === null) {
			throw new NotFoundException('Checklist not available', Constants::ERR_NOT_FOUND);
		}
		$item = $this->dealChecklists->requireItemForGate($itemId);
		$this->requireCompanyInWorkspace((int)$item->getCompanyId(), $workspaceId);

		return $this->checklistItemPayload($itemId);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array
	{
		$raw = file_get_contents('php://input');
		if (is_string($raw) && $raw !== '') {
			try {
				$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
				if (is_array($decoded)) {
					return $decoded;
				}
			} catch (\JsonException) {
				// fall through to request params
			}
		}
		$params = $this->request->getParams();
		unset($params['id'], $params['workspaceId'], $params['_route']);

		return $params;
	}
}
