<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Unauthenticated liveness probe for companion reachability (no secrets).
 */
class HealthController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function check(): JSONResponse
	{
		try {
			$ok = $this->db->tableExists('crm_company');
			$status = $ok ? 'healthy' : 'degraded';
			$code = $ok ? Http::STATUS_OK : Http::STATUS_SERVICE_UNAVAILABLE;

			return new JSONResponse([
				'status' => $status,
				'app' => Application::APP_ID,
				'timestamp' => time(),
			], $code);
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck health check failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return new JSONResponse([
				'status' => 'unhealthy',
				'app' => Application::APP_ID,
				'timestamp' => time(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
