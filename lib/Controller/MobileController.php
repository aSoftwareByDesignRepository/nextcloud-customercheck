<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\MobileGateException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\MobileApiService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
		private readonly ActivityService $activities,
		private readonly DealService $deals,
		private readonly AgreementService $agreements,
		private readonly MobileIdempotencyService $idempotency,
		private readonly MobilePushService $push,
		private readonly IAppManager $appManager,
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
			$today = $this->mobile->today($wsId);

			return $this->success(['items' => $today['items']], $today['meta']);
		});
	}

	// ── Companies ───────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companies(int $workspaceId, string $q = '', int $limit = 25, int $offset = 0): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $q, $limit, $offset): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			$limit = max(1, min(100, $limit));
			$offset = max(0, $offset);
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

			return $this->success($this->mobile->companyLite($company, $uid));
		});
	}

	// ── Activities ──────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function activities(int $workspaceId, int $open = 1, int $companyId = 0, int $limit = 100): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $open, $companyId, $limit): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			if ($companyId > 0) {
				$this->requireCompanyInWorkspace($companyId, $wsId);
			}
			$rows = $this->activities->searchForMobile($uid, [
				'openOnly' => $open === 1,
				'companyId' => $companyId,
				'workspaceId' => $wsId,
			], max(1, min(500, $limit)));

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
			$activity = $this->idempotency->createActivity($uid, $body, $this->idempotencyKey($body));

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

	// ── Deals ───────────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deals(int $workspaceId, int $companyId = 0, int $limit = 200): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $companyId, $limit): JSONResponse {
			$uid = $this->requireGatedUid();
			$wsId = $this->requireMobileWorkspace($uid, $workspaceId);
			if ($companyId > 0) {
				$this->requireCompanyInWorkspace($companyId, $wsId);
			}
			$rows = $this->deals->search([
				'openOnly' => true,
				'companyId' => $companyId,
				'workspaceId' => $wsId,
			], max(1, min(500, $limit)));

			return $this->success(array_map(
				fn ($deal): array => MobileApiService::serialiseDeal($deal, fn ($d): bool => $this->deals->isStale($d)),
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
			$data = MobileApiService::serialiseDeal($result['deal'], fn ($d): bool => $this->deals->isStale($d));
			$data['sequencesFired'] = $result['sequencesFired'];

			return $this->success($data);
		});
	}

	// ── Agreements ──────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function agreements(int $workspaceId, int $expiringWithinDays = 60, int $companyId = 0, int $limit = 200): JSONResponse
	{
		return $this->guard(function () use ($workspaceId, $expiringWithinDays, $companyId, $limit): JSONResponse {
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
			$rows = $this->agreements->search($filters, max(1, min(500, $limit)));

			return $this->success(array_map([MobileApiService::class, 'serialiseAgreement'], $rows));
		});
	}

	// ── Plumbing ────────────────────────────────────────────────────────

	/**
	 * Explicit workspace membership for companion routes (product §3.3).
	 * Missing / foreign workspace → 404 (no existence leak).
	 *
	 * @return int resolved workspace id
	 */
	private function requireMobileWorkspace(string $uid, int $workspaceId, string $capability = 'read'): int
	{
		if ($workspaceId <= 0) {
			throw new NotFoundException('Workspace not found.');
		}
		$this->workspaceAccess->assertCapability($uid, $workspaceId, $capability);
		$ctx = $this->workspaceAccess->resolveForUser($uid, $workspaceId);

		return (int)$ctx['workspace']->getId();
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
	 * Mutations accept app-password Authorization OR a valid CSRF requesttoken —
	 * never a bare session cookie (CSRF hardening for NoCSRFRequired routes).
	 *
	 * @throws AppAccessDeniedException
	 */
	private function assertSafeMutationChannel(): void
	{
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if ($auth !== '' && preg_match('/^(Basic|Bearer)\s+\S+/i', $auth) === 1) {
			return;
		}
		if ($this->request->passesCSRFCheck()) {
			return;
		}

		throw new AppAccessDeniedException('CSRF or app password required');
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
