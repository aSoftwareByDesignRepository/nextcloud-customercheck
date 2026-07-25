<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\DealService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class DealApiController extends Controller {
	use ApiControllerTrait;
	public function __construct(string $appName, IRequest $request, private readonly AccessControlService $accessControl, private readonly DealService $deals) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired] public function index(): JSONResponse {
		try { $this->accessControl->requireCanUseApp(); $rows=$this->deals->search(['stage'=>(string)$this->request->getParam('stage',''),'companyId'=>(int)$this->request->getParam('companyId',0),'openOnly'=>$this->request->getParam('openOnly')==='1'], 500); return $this->ok(array_map(fn($d)=>$this->deals->toArray($d), $rows)); }
		catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
	#[NoAdminRequired] public function create(): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $body=json_decode(file_get_contents('php://input')?:'{}', true)?:[]; return $this->ok($this->deals->toArray($this->deals->create($body,$uid)), Http::STATUS_CREATED); }
		catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
	#[NoAdminRequired] public function update(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $body=json_decode(file_get_contents('php://input')?:'{}', true)?:[]; return $this->ok($this->deals->toArray($this->deals->update($id,$body,$uid))); }
		catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
	#[NoAdminRequired] public function stage(int $id): JSONResponse {
		try {
			$uid=$this->accessControl->requireCanUseApp();
			$body=json_decode(file_get_contents('php://input')?:'{}', true)?:[];
			$result=$this->deals->changeStage($id,(string)($body['stage']??''),$body['lostReason']??null,$this->updatedAtPrecondition($body),$uid);
			$data=$this->deals->toArray($result['deal']);
			$data['sequencesFired']=$result['sequencesFired'];
			return $this->ok($data);
		} catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
}
