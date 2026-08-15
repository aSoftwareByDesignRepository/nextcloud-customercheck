<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly SettingsService $settings,
		private readonly DirectoryOptionsService $directory,
		private readonly \OCA\CustomerCheck\Service\ProductCodeRegistry $productCodes,
		private readonly WorkspaceAccessService $workspaces,
		private readonly \OCA\CustomerCheck\Service\PersonalMailOAuthService $personalMailOAuth,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			return $this->ok($this->settings->getForUser($uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			return $this->ok($this->settings->update($uid, $body));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateMe(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			$view = $this->settings->updatePipelineView($uid, (string)($body['pipelineView'] ?? 'table'));
			return $this->ok(['pipelineView' => $view]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function rotateToken(string $kind): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$token = $this->settings->rotateToken($uid, $kind);
			return $this->ok(['token' => $token, 'kind' => $kind]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Rotate the ICS feed token for one workspace book (§15.2 multi-book).
	 * Managers (members capability) may rotate; independent of the Default feed.
	 */
	#[NoAdminRequired]
	public function rotateWorkspaceIcs(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$token = $this->settings->rotateWorkspaceIcsToken($workspaceId);

			return $this->ok([
				'token' => $token,
				'icsFeedUrl' => $this->settings->workspaceIcsFeedUrl($workspaceId),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function productCodes(): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			return $this->ok($this->productCodes->listAll());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveProductCodes(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			if (!$this->accessControl->canManageAppConfiguration($uid)) {
				throw new \OCA\CustomerCheck\Exception\AccessDeniedException();
			}
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			$custom = is_array($body['custom'] ?? null) ? $body['custom'] : [];
			return $this->ok($this->productCodes->saveCustom($custom));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function personalMailOAuthConfig(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			if (!$this->accessControl->canManageAppConfiguration($uid)) {
				throw new AccessDeniedException();
			}

			return $this->ok($this->personalMailOAuth->adminConfigStatus());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function savePersonalMailOAuthConfig(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			if (!$this->accessControl->canManageAppConfiguration($uid)) {
				throw new AccessDeniedException();
			}
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

			return $this->ok($this->personalMailOAuth->saveAdminConfig(is_array($body) ? $body : []));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Directory search for people pickers (allowed users, seats, workspace members, owners).
	 *
	 * App administrators may always search. Workspace members with write capability
	 * may search when they pass workspaceId — never viewers.
	 */
	#[NoAdminRequired]
	public function searchUsers(string $q = ''): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$workspaceId = (int)$this->request->getParam('workspaceId', 0);
			if (!$this->accessControl->isAppAdmin($uid)) {
				if ($workspaceId < 1) {
					throw new AccessDeniedException();
				}
				$this->workspaces->assertCapability($uid, $workspaceId, 'write');
			}
			$query = $q !== '' ? $q : (string)$this->request->getParam('q', '');
			if (mb_strlen(trim($query)) < 2) {
				return $this->ok(['users' => []]);
			}
			return $this->ok(['users' => $this->directory->searchUsers($query, 20)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
