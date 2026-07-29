<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Activities API — workspace-scoped (product §3.3).
 */
class ActivityApiController extends Controller
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
		private readonly ActivityService $activities,
	) {
		parent::__construct($appName, $request);
	}

	private function ser($a): array
	{
		return [
			'id' => (int)$a->getId(),
			'companyId' => (int)$a->getCompanyId(),
			'dealId' => $a->getDealId(),
			'type' => (string)$a->getType(),
			'title' => (string)$a->getTitle(),
			'details' => $a->getDetails(),
			'dueDate' => $a->getDueDate()?->format('Y-m-d'),
			'doneAt' => OptimisticLock::format($a->getDoneAt()),
			'origin' => (string)$a->getOrigin(),
			'updatedAt' => OptimisticLock::format($a->getUpdatedAt()),
		];
	}

	private function jsonBody(): array
	{
		$b = json_decode(file_get_contents('php://input') ?: '{}', true);

		return is_array($b) ? $b : [];
	}

	private function gateActivity(int $id, string $capability = 'write'): array
	{
		$activity = $this->activities->get($id);
		$this->requireCompanyInResolvedWorkspace((int)$activity->getCompanyId());

		return $this->requireWorkspaceCapability($capability);
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

			return $this->ok(array_map([$this, 'ser'], $this->activities->search([
				'openOnly' => $this->request->getParam('openOnly', '1') === '1',
				'companyId' => $companyId,
				'workspaceId' => $ctx['workspaceId'],
			], 500)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('write');
			$b = $this->jsonBody();
			$this->requireCompanyInResolvedWorkspace((int)($b['companyId'] ?? 0));

			return $this->ok($this->ser($this->activities->create($b, $ctx['uid'])), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		try {
			$ctx = $this->gateActivity($id, 'write');
			$b = $this->jsonBody();

			return $this->ok($this->ser($this->activities->update($id, $b, $ctx['uid'], $this->updatedAtPrecondition($b))));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function done(int $id): JSONResponse
	{
		try {
			$ctx = $this->gateActivity($id, 'write');
			$b = $this->jsonBody();

			return $this->ok($this->ser($this->activities->setDone($id, ($b['done'] ?? true) !== false, $ctx['uid'], $this->updatedAtPrecondition($b))));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function pushTodo(int $id): JSONResponse
	{
		try {
			$ctx = $this->gateActivity($id, 'write');

			return $this->ok($this->activities->pushToTasks($id, $ctx['uid']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function dismissFollowUps(int $dealId): JSONResponse
	{
		try {
			$deal = $this->deals->get($dealId);
			$this->requireCompanyInResolvedWorkspace((int)$deal->getCompanyId());
			$ctx = $this->requireWorkspaceCapability('write');

			return $this->ok($this->activities->dismissRemainingFollowUps($dealId, $ctx['uid']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
