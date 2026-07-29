<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MoneyApiController extends Controller {
	use ApiControllerTrait;
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly MoneyService $money,
		private readonly WorkspaceAccessService $workspaces,
	) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	public function index(): JSONResponse {
		try {
			$this->accessControl->requireCanUseApp();
			$uid = $this->accessControl->currentUserId();
			$ctx = $this->workspaces->resolveForUser($uid);
			$wsId = (int)$ctx['workspace']->getId();
			$state = $this->request->getParam('state');
			$stateFilter = is_string($state) && $state !== '' ? $state : null;
			$o = $this->money->overview(200, $stateFilter, $wsId);
			return $this->ok($o['items'], 200, $o['meta']);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
