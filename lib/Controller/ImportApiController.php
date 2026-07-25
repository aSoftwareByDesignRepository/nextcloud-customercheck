<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Two-phase import API — PSD §8.6 / §10.1.
 */
class ImportApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly ImportService $imports,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function scan(): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			return $this->ok($this->imports->scan(is_array($payload) ? $payload : []));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			return $this->ok($this->imports->apply(is_array($payload) ? $payload : [], $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
