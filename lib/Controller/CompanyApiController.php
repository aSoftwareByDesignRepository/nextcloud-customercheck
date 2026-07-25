<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CompanyApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly CompanyService $companies,
		private readonly SlugService $slugs,
		private readonly TimelineService $timeline,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			$filters = [
				'status' => (string)$this->request->getParam('status', ''),
				'search' => (string)$this->request->getParam('q', ''),
				'archived' => $this->request->getParam('archived') === '1',
			];
			$limit = (int)$this->request->getParam('limit', 100);
			$offset = (int)$this->request->getParam('offset', 0);
			$rows = $this->companies->search($filters, $limit, $offset);
			$total = $this->companies->count($filters);
			return $this->ok(array_map([$this, 'serialize'], $rows), Http::STATUS_OK, [
				'total' => $total, 'limit' => $limit, 'offset' => $offset,
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$body = $this->jsonBody();
			$company = $this->companies->create($body, $uid);
			return $this->ok($this->serialize($company), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function suggestSlug(): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			$body = $this->jsonBody();
			return $this->ok(['slug' => $this->slugs->suggestUnique((string)($body['name'] ?? ''))]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			return $this->ok($this->serialize($this->companies->get($id)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$body = $this->jsonBody();
			$company = $this->companies->update($id, $body, $uid, $this->updatedAtPrecondition($body));
			return $this->ok($this->serialize($company));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$body = $this->jsonBody();
			$this->companies->gdprErase($id, (string)($body['confirmSlug'] ?? ''), $uid);
			return $this->ok(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function archive(int $id): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			return $this->ok($this->serialize($this->companies->archive($id, $uid)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function restore(int $id): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			return $this->ok($this->serialize($this->companies->restore($id, $uid)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function export(int $id): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			return $this->ok($this->companies->gdprExport($id));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function timeline(int $id): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			$this->companies->get($id);
			$events = $this->timeline->listForCompany($id, 100);
			$data = array_map(static function ($e): array {
				return [
					'id' => (int)$e->getId(),
					'eventType' => (string)$e->getEventType(),
					'actor' => (string)$e->getActor(),
					'payloadJson' => $e->getPayloadJson(),
					'createdAt' => $e->getCreatedAt()?->format(\DateTimeInterface::ATOM),
				];
			}, $events);
			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** @return array<string,mixed> */
	private function jsonBody(): array
	{
		$raw = file_get_contents('php://input') ?: '';
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function serialize(\OCA\CustomerCheck\Db\Company $c): array
	{
		return [
			'id' => (int)$c->getId(),
			'slug' => (string)$c->getSlug(),
			'name' => (string)$c->getName(),
			'status' => (string)$c->getStatus(),
			'lang' => (string)$c->getLang(),
			'country' => (string)$c->getCountry(),
			'vatId' => $c->getVatId(),
			'website' => $c->getWebsite(),
			'notes' => $c->getNotes(),
			'addrStreet' => $c->getAddrStreet(),
			'addrZip' => $c->getAddrZip(),
			'addrCity' => $c->getAddrCity(),
			'pcCustomerId' => $c->getPcCustomerId(),
			'tcCustomerId' => $c->getTcCustomerId(),
			'archivedAt' => OptimisticLock::format($c->getArchivedAt()),
			'updatedAt' => OptimisticLock::format($c->getUpdatedAt()),
		];
	}
}
