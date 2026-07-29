<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Middleware;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\RateLimitService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use ReflectionMethod;

/**
 * Enforce §15 session mutating API rate limit: 120 / user / min → 429 RATE_LIMITED.
 * Public token routes (ICS, lead webhook) are excluded — they have their own limits.
 */
class SessionRateLimitMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly RateLimitService $rateLimit,
		private readonly IFactory $l10nFactory,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\CustomerCheck\\Controller\\')) {
			return;
		}
		if ($this->isPublic($controller, $methodName)) {
			return;
		}
		$method = strtoupper($this->request->getMethod());
		if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
			return;
		}
		$path = (string)($this->request->getPathInfo() ?? '');
		if (!str_contains($path, '/api/')) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		$bucket = 'sess:' . $user->getUID();
		if ($this->rateLimit->allow($bucket, Constants::SESSION_RATE_LIMIT, Constants::SESSION_RATE_WINDOW_SECONDS)) {
			return;
		}
		$l = $this->l10nFactory->get('customercheck');
		throw new ValidationException(
			Constants::ERR_RATE_LIMITED,
			$l->t('Too many requests. Please wait a minute and try again.'),
		);
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!($exception instanceof ValidationException)
			|| $exception->getErrorCode() !== Constants::ERR_RATE_LIMITED
		) {
			throw $exception;
		}
		return new JSONResponse([
			'success' => false,
			'error' => Constants::ERR_RATE_LIMITED,
			'message' => $exception->getMessage(),
			'fields' => new \stdClass(),
		], Http::STATUS_TOO_MANY_REQUESTS);
	}

	private function isPublic(object $controller, string $methodName): bool
	{
		try {
			$ref = new ReflectionMethod($controller, $methodName);
			if ($ref->getAttributes(PublicPage::class) !== []) {
				return true;
			}
		} catch (\ReflectionException) {
		}
		$class = get_class($controller);
		return str_contains($class, 'FeedController') || str_contains($class, 'LeadWebhookController');
	}
}
