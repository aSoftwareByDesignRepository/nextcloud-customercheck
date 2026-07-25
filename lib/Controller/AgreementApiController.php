<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\DeliveryPackService;
use OCA\CustomerCheck\Service\PartnerMailService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AgreementApiController extends Controller {
	use ApiControllerTrait;
	public function __construct(string $appName, IRequest $request, private readonly AccessControlService $accessControl, private readonly AgreementService $agreements, private readonly DeliveryPackService $delivery, private readonly PartnerMailService $partnerMail) {
		parent::__construct($appName, $request);
	}
	private function body(): array { $d=json_decode(file_get_contents('php://input')?:'{}', true); return is_array($d)?$d:[]; }
	private function ser($a): array {
		return ['id'=>(int)$a->getId(),'companyId'=>(int)$a->getCompanyId(),'kind'=>(string)$a->getKind(),'productCode'=>(string)$a->getProductCode(),'label'=>(string)$a->getLabel(),'limits'=>$a->decodedLimits(),'startsAt'=>$a->getStartsAt()?->format('Y-m-d'),'validUntil'=>$a->getValidUntil()?->format('Y-m-d'),'amountCents'=>(int)$a->getAmountCents(),'invoiceStatus'=>(string)$a->getInvoiceStatus(),'invoiceRef'=>$a->getInvoiceRef(),'status'=>(string)$a->getStatus(),'keyFingerprint'=>$a->getKeyFingerprint(),'salesLogId'=>$a->getSalesLogId(),'updatedAt'=>OptimisticLock::format($a->getUpdatedAt())];
	}
	#[NoAdminRequired] public function index(): JSONResponse {
		try { $this->accessControl->requireCanUseApp(); $f=['companyId'=>(int)$this->request->getParam('companyId',0),'kind'=>(string)$this->request->getParam('kind',''),'status'=>(string)$this->request->getParam('status','')]; return $this->ok(array_map([$this,'ser'], $this->agreements->search($f,500))); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function create(): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); return $this->ok($this->ser($this->agreements->create($this->body(),$uid)), Http::STATUS_CREATED); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function update(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=$this->body(); return $this->ok($this->ser($this->agreements->update($id,$b,$uid,$this->updatedAtPrecondition($b)))); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function cancel(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=$this->body(); return $this->ok($this->ser($this->agreements->cancel($id,(string)($b['reason']??''),$uid,$this->updatedAtPrecondition($b)))); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function supersede(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=$this->body(); $r=$this->agreements->supersede($id,$b,$uid,$this->updatedAtPrecondition($b)); return $this->ok(['previous'=>$this->ser($r['previous']),'current'=>$this->ser($r['current'])]); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function deliveryPack(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=$this->body(); return $this->ok($this->delivery->build($id,$uid, isset($b['personId']) ? (int)$b['personId'] : null, isset($b['toEmail']) ? (string)$b['toEmail'] : null)); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
	#[NoAdminRequired] public function partnerMail(int $id): JSONResponse {
		try { $uid=$this->accessControl->requireCanUseApp(); $b=$this->body(); return $this->ok($this->partnerMail->build($id,$uid,!empty($b['promptHours']), isset($b['personId']) ? (int)$b['personId'] : null, isset($b['toEmail']) ? (string)$b['toEmail'] : null)); }
		catch(\Throwable $e){ return $this->fromThrowable($e);} }
}
