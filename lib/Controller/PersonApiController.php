<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PersonApiController extends Controller {
	use ApiControllerTrait;
	public function __construct(string $appName, IRequest $request, private readonly AccessControlService $accessControl, private readonly PersonService $persons) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired] public function index(int $id): JSONResponse {
		try { $this->accessControl->requireCanUseApp(); return $this->ok(array_map([$this,'ser'], $this->persons->listForCompany($id))); }
		catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
	#[NoAdminRequired] public function create(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $body=json_decode(file_get_contents('php://input')?:'{}', true)?:[]; return $this->ok($this->ser($this->persons->create($id,$body,$uid)), Http::STATUS_CREATED); }
		catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
	#[NoAdminRequired] public function update(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $body=json_decode(file_get_contents('php://input')?:'{}', true)?:[]; return $this->ok($this->ser($this->persons->update($id,$body,$uid,$this->updatedAtPrecondition($body)))); }
		catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
	#[NoAdminRequired] public function destroy(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $this->persons->delete($id,$uid); return $this->ok(['deleted'=>true]); }
		catch (\Throwable $e) { return $this->fromThrowable($e); }
	}
	private function ser($p): array {
		return ['id'=>(int)$p->getId(),'companyId'=>(int)$p->getCompanyId(),'name'=>(string)$p->getName(),'role'=>$p->getRole(),'email'=>$p->getEmail(),'phone'=>$p->getPhone(),'isPrimary'=>(int)$p->getIsPrimary()===1,'updatedAt'=>OptimisticLock::format($p->getUpdatedAt())];
	}
}
