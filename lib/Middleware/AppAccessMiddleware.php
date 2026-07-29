<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Middleware;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
		private readonly IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\CustomerCheck\\Controller\\')) {
			return;
		}
		if ($this->isPublic($controller, $methodName)) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		$uid = $user->getUID();
		if ($this->accessControl->canUseApp($uid)) {
			return;
		}
		$this->logger->warning('customercheck access denied', ['userId' => $uid]);
		throw new AppAccessDeniedException('ACCESS_DENIED');
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if ($exception instanceof NotFoundException) {
			$path = (string)($this->request->getPathInfo() ?? '');
			$wantsJson = str_contains($path, '/api/') || $this->request->getMethod() !== 'GET';
			$message = $exception->getMessage() !== '' ? $exception->getMessage() : 'Not found';
			if ($wantsJson) {
				return new JSONResponse([
					'success' => false,
					'error' => $exception->getErrorCode() ?: 'NOT_FOUND',
					'message' => $message,
					'fields' => new \stdClass(),
				], Http::STATUS_NOT_FOUND);
			}
			$l = $this->l10nFactory->get('customercheck');

			return new TemplateResponse('customercheck', 'access-denied', [
				'message' => $l->t('Not found.'),
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
				'todayUrl' => $this->urlGenerator->linkToRoute('customercheck.page.today'),
				'showAppLink' => true,
			], 'guest');
		}

		$isAppGate = $exception instanceof AppAccessDeniedException;
		$isAdminGate = $exception instanceof AccessDeniedException;
		if (!$isAppGate && !$isAdminGate) {
			throw $exception;
		}
		$path = (string)($this->request->getPathInfo() ?? '');
		$wantsJson = str_contains($path, '/api/') || $this->request->getMethod() !== 'GET';
		$l = $this->l10nFactory->get('customercheck');
		$message = $isAdminGate
			? $l->t('You need CustomerCheck administrator rights for this action.')
			: $l->t('You do not have access to CustomerCheck.');
		$error = $isAdminGate ? 'ACCESS_DENIED' : 'ACCESS_DENIED';
		if ($wantsJson) {
			return new JSONResponse([
				'success' => false,
				'error' => $error,
				'message' => $message,
				'fields' => new \stdClass(),
			], Http::STATUS_FORBIDDEN);
		}
		return new TemplateResponse('customercheck', 'access-denied', [
			'message' => $message,
			'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			'todayUrl' => $this->urlGenerator->linkToRoute('customercheck.page.today'),
			'showAppLink' => false,
		], 'guest');
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
