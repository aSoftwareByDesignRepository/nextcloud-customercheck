<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CommandBoardService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class TodayApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly TodayService $today,
		private readonly CommandBoardService $commandBoard,
		private readonly WorkspaceAccessService $workspaces,
		private readonly ?\OCP\ICacheFactory $cacheFactory = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			$uid = $this->accessControl->currentUserId();
			$ctx = $this->workspaces->resolveForUser($uid);
			$wsId = (int)$ctx['workspace']->getId();
			$q = $this->today->build(null, $wsId > 0 ? $wsId : null);

			return $this->ok($q, 200, $q['meta']);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function command(): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			$uid = $this->accessControl->currentUserId();
			$ctx = $this->workspaces->resolveForUser($uid);
			$board = $this->commandBoard->build(
				null,
				$uid,
				(int)$ctx['workspace']->getId(),
				$ctx['role'],
				$ctx['isAppAdmin'],
			);

			return $this->ok($board, 200, $board['meta']);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
	/**
	 * Compact widget snapshot (SPEC NH-P2): ONE document per sync — the same
	 * command board the app assembles, wrapped with serverNow so widgets can
	 * correct device-clock skew (MH-P18). Cached 60 s per user + workspace.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function widgetSnapshot(): JSONResponse
	{
		try {
			// Access door before the cache read — a revoked user must not be
			// served a cached board inside the TTL window.
			$this->accessControl->requireCanUseApp();
			$uid = $this->accessControl->currentUserId();
			$ctx = $this->workspaces->resolveForUser($uid);
			$wsId = (int)$ctx['workspace']->getId();
			// Hand-constructed controllers (tests) may lack the optional tail
			// dep; autowiring always injects it in production.
			$cacheFactory = $this->cacheFactory ?? \OCP\Server::get(\OCP\ICacheFactory::class);
			$cache = $cacheFactory->createDistributed('customercheck_wsnap');
			// Workspace scopes the cache — a per-user-only key would serve the
			// previous book's payload for the TTL after a workspace switch.
			$key = $uid . ':' . $wsId . ':command';
			if (($hit = $cache->get($key)) !== null) {
				return new JSONResponse($hit);
			}
			$response = $this->command();
			$doc = $response->getData();
			// Error envelopes pass through uncached at their real status — a
			// transient failure must not stick to the user for the TTL window.
			if (!is_array($doc) || ($doc['success'] ?? false) !== true || !is_array($doc['data'] ?? null)) {
				return $response;
			}
			$doc['data']['serverNow'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
			$cache->set($key, $doc, 60);
			return new JSONResponse($doc);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

}
