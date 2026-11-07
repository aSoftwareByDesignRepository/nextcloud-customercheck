<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CommandBoardService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
	/** Compact widget snapshot (NH-P2): one doc per sync + serverNow (MH-P18), 60 s/user cache. */
	#[NoAdminRequired]
	public function widgetSnapshot(): JSONResponse
	{
		$uid = \OCP\Server::get(\OCP\IUserSession::class)->getUser()?->getUID();
		if ($uid === null) return new JSONResponse(['error' => 'unauthenticated'], 401);
		$cache = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('customercheck_wsnap');
		$key = $uid . ':command';
		if (($hit = $cache->get($key)) !== null) return new JSONResponse($hit);
		$status = $this->command()->getData();
		$payload = ['status' => $status,
			'serverNow' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM)];
		$cache->set($key, $payload, 60);
		return new JSONResponse($payload);
	}

}
