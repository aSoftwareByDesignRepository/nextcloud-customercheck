<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealChecklistService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Deal checklist API — CUC-DEAL-CHECKLIST-1. */
class DealChecklistApiController extends Controller
{
	use ApiControllerTrait;
	use WorkspaceScopedTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		/** Required by WorkspaceScopedTrait::requireCompanyCapability / requireCompanyInResolvedWorkspace. */
		private readonly CompanyService $companies,
		private readonly DealService $deals,
		private readonly DealChecklistService $checklists,
	) {
		parent::__construct($appName, $request);
	}

	private function jsonBody(): array
	{
		$b = json_decode(file_get_contents('php://input') ?: '{}', true);

		return is_array($b) ? $b : [];
	}

	/** @return array{uid: string, workspaceId: int, role: string, isAppAdmin: bool} */
	private function gateDeal(int $dealId, string $capability = 'write'): array
	{
		$deal = $this->deals->get($dealId);

		return $this->requireCompanyCapability((int)$deal->getCompanyId(), $capability);
	}

	/** @return array{uid: string, workspaceId: int, role: string, isAppAdmin: bool} */
	private function gateItem(int $itemId, string $capability = 'write'): array
	{
		$item = $this->checklists->requireItemForGate($itemId);

		return $this->requireCompanyCapability((int)$item->getCompanyId(), $capability);
	}

	#[NoAdminRequired]
	public function templates(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceContext();

			return $this->ok($this->checklists->listTemplates($ctx['workspaceId']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function templatesManage(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('members');

			return $this->ok($this->checklists->listTemplatesForManage($ctx['workspaceId']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createTemplate(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('members');

			return $this->ok(
				$this->checklists->createTemplate($ctx['workspaceId'], $this->jsonBody(), $ctx['uid']),
				Http::STATUS_CREATED,
			);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function cloneTemplate(int $id): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('members');
			$body = $this->jsonBody();
			$name = isset($body['name']) ? (string)$body['name'] : null;

			return $this->ok(
				$this->checklists->cloneTemplate($id, $ctx['workspaceId'], $ctx['uid'], $name),
				Http::STATUS_CREATED,
			);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateTemplate(int $id): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('members');

			return $this->ok(
				$this->checklists->updateTemplate($id, $ctx['workspaceId'], $this->jsonBody(), $ctx['uid']),
			);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteTemplate(int $id): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('members');
			$this->checklists->deleteTemplate($id, $ctx['workspaceId'], $ctx['uid']);

			return $this->ok(['ok' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function index(int $dealId): JSONResponse
	{
		try {
			$ctx = $this->gateDeal($dealId, 'read');

			return $this->ok($this->checklists->listForDeal($dealId, $ctx['uid']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(int $dealId): JSONResponse
	{
		try {
			$ctx = $this->gateDeal($dealId, 'write');

			return $this->ok($this->checklists->addItem($dealId, $this->jsonBody(), $ctx['uid']), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function fromTemplate(int $dealId): JSONResponse
	{
		try {
			$ctx = $this->gateDeal($dealId, 'write');

			return $this->ok($this->checklists->applyTemplate($dealId, $this->jsonBody(), $ctx['uid']), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		try {
			$ctx = $this->gateItem($id, 'write');
			$b = $this->jsonBody();

			return $this->ok($this->checklists->updateItem($id, $b, $ctx['uid'], $this->updatedAtPrecondition($b)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function complete(int $id): JSONResponse
	{
		try {
			$ctx = $this->gateItem($id, 'write');
			$b = $this->jsonBody();

			return $this->ok($this->checklists->completeItem($id, $b, $ctx['uid'], $this->updatedAtPrecondition($b)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function linkFile(int $id): JSONResponse
	{
		try {
			$ctx = $this->gateItem($id, 'write');
			$b = $this->jsonBody();
			$fileId = (int)($b['fileId'] ?? 0);

			return $this->ok($this->checklists->linkFile($id, $fileId, $ctx['uid']), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function unlinkFile(int $fileId): JSONResponse
	{
		try {
			$item = $this->checklists->requireItemForFileRow($fileId);
			$ctx = $this->requireCompanyCapability((int)$item->getCompanyId(), 'write');
			$this->checklists->unlinkFile($fileId, $ctx['uid']);

			return $this->ok(['ok' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
