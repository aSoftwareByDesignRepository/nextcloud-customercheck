<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\SettingsService;
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
	 * Search Nextcloud users for the allowed-users picker (app admin only).
	 */
	#[NoAdminRequired]
	public function searchUsers(string $q = ''): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$this->accessControl->requireAppAdmin();
			$query = $q !== '' ? $q : (string)$this->request->getParam('q', '');
			return $this->ok(['users' => $this->directory->searchUsers($query, 20)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
