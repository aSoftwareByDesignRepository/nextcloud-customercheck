<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ImportService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Two-phase import API — product §15.4 / §3.3.
 *
 * Always scoped to the active workspace with write capability.
 */
class ImportApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly ImportService $imports,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function scan(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$ctx = $this->workspaces->resolveForUser($uid);
			$wsId = (int)$ctx['workspace']->getId();
			$this->workspaces->assertCapability($uid, $wsId, 'write');
			$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			if (!is_array($payload)) {
				$payload = [];
			}
			$payload['workspaceId'] = $wsId;
			return $this->ok($this->imports->scan($payload));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$ctx = $this->workspaces->resolveForUser($uid);
			$wsId = (int)$ctx['workspace']->getId();
			$this->workspaces->assertCapability($uid, $wsId, 'write');
			$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			if (!is_array($payload)) {
				$payload = [];
			}
			$payload['workspaceId'] = $wsId;
			return $this->ok($this->imports->apply($payload, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
