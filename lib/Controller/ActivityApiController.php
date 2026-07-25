<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ActivityApiController extends Controller {
	use ApiControllerTrait;
	public function __construct(string $appName, IRequest $request, private readonly AccessControlService $accessControl, private readonly ActivityService $activities) {
		parent::__construct($appName, $request);
	}
	private function ser($a): array {
		return ['id'=>(int)$a->getId(),'companyId'=>(int)$a->getCompanyId(),'dealId'=>$a->getDealId(),'type'=>(string)$a->getType(),'title'=>(string)$a->getTitle(),'details'=>$a->getDetails(),'dueDate'=>$a->getDueDate()?->format('Y-m-d'),'doneAt'=>OptimisticLock::format($a->getDoneAt()),'origin'=>(string)$a->getOrigin(),'updatedAt'=>OptimisticLock::format($a->getUpdatedAt())];
	}
	#[NoAdminRequired] public function index(): JSONResponse {
		try { $this->accessControl->requireCanUseApp(); return $this->ok(array_map([$this,'ser'], $this->activities->search(['openOnly'=>$this->request->getParam('openOnly','1')==='1','companyId'=>(int)$this->request->getParam('companyId',0)],500))); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function create(): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=json_decode(file_get_contents('php://input')?:'{}', true)?:[]; return $this->ok($this->ser($this->activities->create($b,$uid)), Http::STATUS_CREATED); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function update(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=json_decode(file_get_contents('php://input')?:'{}', true)?:[]; return $this->ok($this->ser($this->activities->update($id,$b,$uid,$this->updatedAtPrecondition($b)))); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function done(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=json_decode(file_get_contents('php://input')?:'{}', true)?:[]; return $this->ok($this->ser($this->activities->setDone($id, ($b['done']??true)!==false, $uid, $this->updatedAtPrecondition($b)))); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function pushTodo(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); return $this->ok($this->activities->pushToTasks($id,$uid)); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function dismissFollowUps(int $dealId): JSONResponse {
		try {
			$uid = $this->accessControl->requireCanUseApp();
			return $this->ok($this->activities->dismissRemainingFollowUps($dealId, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
