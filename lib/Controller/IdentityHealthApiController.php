<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin Identity Health KPI API (SHARED-IDENTITY MH-11 / AC-C-13).
 * Counts only — no PII payloads.
 */
class IdentityHealthApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly IdentityLinkHealthService $identityHealth,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function summary(): JSONResponse
	{
		try {
			$uid = $this->accessControl->currentUserId();
			if (!$this->accessControl->canManageAppConfiguration($uid)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'ACCESS_DENIED',
					'message' => 'App administrator access required.',
				], Http::STATUS_FORBIDDEN);
			}

			return $this->ok($this->identityHealth->adminHealthCounts());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
