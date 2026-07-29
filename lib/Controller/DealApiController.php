<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Deals API — workspace-scoped (product §3.3 / §11).
 */
class DealApiController extends Controller
{
	use ApiControllerTrait;
	use WorkspaceScopedTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly CompanyService $companies,
		private readonly DealService $deals,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceContext();
			$companyId = (int)$this->request->getParam('companyId', 0);
			if ($companyId > 0) {
				$this->requireCompanyInResolvedWorkspace($companyId);
			}
			$filters = [
				'stage' => (string)$this->request->getParam('stage', ''),
				'companyId' => $companyId,
				'openOnly' => $this->request->getParam('openOnly') === '1',
				'workspaceId' => $ctx['workspaceId'],
			];
			$rows = $this->deals->search($filters, 500);

			return $this->ok(array_map(fn ($d) => $this->deals->toArray($d), $rows));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('write');
			$body = $this->jsonBody();
			$companyId = (int)($body['companyId'] ?? 0);
			$this->requireCompanyInResolvedWorkspace($companyId);

			return $this->ok($this->deals->toArray($this->deals->create($body, $ctx['uid'])), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		try {
			$deal = $this->deals->get($id);
			$this->requireCompanyInResolvedWorkspace((int)$deal->getCompanyId());
			$ctx = $this->requireWorkspaceCapability('write');
			$body = $this->jsonBody();

			return $this->ok($this->deals->toArray($this->deals->update($id, $body, $ctx['uid'])));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function stage(int $id): JSONResponse
	{
		try {
			$deal = $this->deals->get($id);
			$this->requireCompanyInResolvedWorkspace((int)$deal->getCompanyId());
			$ctx = $this->requireWorkspaceCapability('write');
			$body = $this->jsonBody();
			$result = $this->deals->changeStage(
				$id,
				(string)($body['stage'] ?? ''),
				isset($body['lostReason']) ? (string)$body['lostReason'] : null,
				$this->updatedAtPrecondition($body),
				$ctx['uid'],
				$body,
			);
			$data = $this->deals->toArray($result['deal']);
			$data['sequencesFired'] = $result['sequencesFired'];

			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** @return array<string, mixed> */
	private function jsonBody(): array
	{
		$decoded = json_decode(file_get_contents('php://input') ?: '{}', true);

		return is_array($decoded) ? $decoded : [];
	}
}
