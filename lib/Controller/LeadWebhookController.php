<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\LeadWebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class LeadWebhookController extends Controller {
	use ApiControllerTrait;
	public function __construct(string $appName, IRequest $request, private readonly LeadWebhookService $leads) {
		parent::__construct($appName, $request);
	}
	#[PublicPage]
	#[NoCSRFRequired]
	public function receive(string $token): JSONResponse {
		try {
			$auth = (string)$this->request->getHeader('Authorization');
			if (str_starts_with($auth, 'Bearer ')) {
				$token = trim(substr($auth, 7));
			}
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			$ip = $this->request->getRemoteAddress() ?: '0.0.0.0';
			return $this->ok($this->leads->handle($token, $ip, $body), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
