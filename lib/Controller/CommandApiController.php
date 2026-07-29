<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CallbackService;
use OCA\CustomerCheck\Service\CompanyCommandService;
use OCA\CustomerCheck\Service\CompanyDocumentsService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CompanyTasksService;
use OCA\CustomerCheck\Service\PlatformLinksService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Command Center APIs — COMMAND-CENTER-SPEC §10.2.
 * Workspace-scoped: cross-WS company id → 404.
 */
class CommandApiController extends Controller
{
	use ApiControllerTrait;
	use WorkspaceScopedTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly CompanyCommandService $command,
		private readonly CompanyDocumentsService $documents,
		private readonly CompanyTasksService $tasks,
		private readonly CallbackService $callbacks,
		private readonly PlatformLinksService $platformLinks,
		private readonly CompanyService $companies,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function command(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'read');

			return $this->ok($this->command->build($id, $ws['uid']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function communication(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'read');
			$limit = (int)$this->request->getParam('limit', 25);
			$cursor = $this->request->getParam('cursor');
			$personId = $this->request->getParam('personId');
			$includeSystem = $this->request->getParam('includeSystem') === '1';

			return $this->ok($this->command->communication(
				$id,
				$ws['uid'],
				$limit,
				is_string($cursor) ? $cursor : null,
				$personId !== null && $personId !== '' ? (int)$personId : null,
				$includeSystem,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function people(int $id): JSONResponse
	{
		try {
			$this->requireCompanyInResolvedWorkspace($id);

			return $this->ok($this->command->peopleRoster($id));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function scheduleCallback(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$activity = $this->callbacks->schedule($id, $body, $ws['uid']);

			return $this->ok([
				'id' => (int)$activity->getId(),
				'title' => (string)$activity->getTitle(),
				'dueDate' => $activity->getDueDate()?->format('Y-m-d'),
				'isCallback' => true,
				'updatedAt' => OptimisticLock::format($activity->getUpdatedAt()),
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function putFilesFolder(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$fileIdRaw = $body['fileId'] ?? null;
			$fileId = $fileIdRaw !== null && $fileIdRaw !== '' ? (int)$fileIdRaw : null;
			$path = isset($body['path']) ? (string)$body['path'] : null;
			$updated = $this->documents->linkFromPicker(
				$id,
				$ws['uid'],
				$fileId,
				$path,
				$this->updatedAtPrecondition($body),
			);

			return $this->ok([
				'fileId' => $updated->getFilesFolderId(),
				'path' => $updated->getFilesFolderPath(),
				'updatedAt' => OptimisticLock::format($updated->getUpdatedAt()),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createFilesFolder(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$parent = isset($body['parentFileId']) ? (int)$body['parentFileId'] : null;
			$result = $this->documents->createFolder($id, $ws['uid'], $parent, $this->updatedAtPrecondition($body));

			return $this->ok([
				'fileId' => $result['fileId'],
				'path' => $result['path'],
				'updatedAt' => OptimisticLock::format($result['company']->getUpdatedAt()),
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteFilesFolder(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$updated = $this->documents->unlinkFolder($id, $ws['uid'], $this->updatedAtPrecondition($body));

			return $this->ok([
				'fileId' => null,
				'path' => null,
				'updatedAt' => OptimisticLock::format($updated->getUpdatedAt()),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function filesChildren(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'read');

			return $this->ok($this->documents->listChildren($id, $ws['uid']));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function searchFolders(): JSONResponse
	{
		try {
			$ws = $this->requireWorkspaceContext();
			$q = (string)$this->request->getParam('q', '');

			return $this->ok($this->documents->searchFolders($ws['uid'], $q));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function tasks(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'read');
			$includeDone = $this->request->getParam('includeDone') === '1';

			return $this->ok($this->tasks->list($id, $ws['uid'], $includeDone));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createTask(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'write');

			return $this->ok($this->tasks->create($id, $this->jsonBody(), $ws['uid']), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function platformLinks(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'read');
			$folderId = $company->getFilesFolderId();

			return $this->ok($this->platformLinks->forUser(
				$ws['uid'],
				$folderId !== null ? (int)$folderId : null,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** @return array<string, mixed> */
	private function jsonBody(): array
	{
		$raw = file_get_contents('php://input') ?: '';
		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}
}
