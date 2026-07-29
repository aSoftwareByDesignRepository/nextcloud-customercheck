<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\TodayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SummaryApiController extends Controller {
	use ApiControllerTrait;
	public function __construct(string $appName, IRequest $request, private readonly AccessControlService $accessControl, private readonly TodayService $today, private readonly FacadeProbeService $facades) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired] public function index(): JSONResponse {
		try {
			$this->accessControl->requireCanUseApp();
			$q=$this->today->build();
			return $this->ok(['todayCount'=>count($q['items']),'capabilities'=>$this->facades->capabilityMatrix()]);
		} catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
}
