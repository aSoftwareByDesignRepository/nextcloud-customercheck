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
		$parent = is_object($controller) ? (get_parent_class($controller) ?: '') : '';
		// PHPUnit mocks use a generated class name; honour the real controller parent.
		if (!str_starts_with($class, 'OCA\\CustomerCheck\\Controller\\')
			&& !str_starts_with($parent, 'OCA\\CustomerCheck\\Controller\\')) {
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
		$reason = $this->accessControl->denialReasonWhenCannotUseApp($uid);
		$this->logger->warning('customercheck access denied', ['userId' => $uid, 'reason' => $reason]);
		throw new AppAccessDeniedException($reason !== '' ? $reason : 'ACCESS_DENIED');
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
				'hint' => '',
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
		if ($isAdminGate) {
			$message = $l->t('You need CustomerCheck administrator rights for this action.');
			$hint = $l->t('Ask a Nextcloud administrator who manages CustomerCheck to help with this change.');
			$error = 'ACCESS_DENIED';
		} else {
			$reason = $exception->getMessage();
			[$message, $hint] = match ($reason) {
				AccessControlService::DENIAL_RESTRICTION => [
					$l->t('Your organisation restricts CustomerCheck access. You are not on the allowed-users list and you are not a member of any allowed group.'),
					$l->t('Ask a CustomerCheck administrator to add you under App settings → Access mode, or switch Access mode to Open if everyone should see the app.'),
				],
				default => [
					$l->t('You do not have access to CustomerCheck.'),
					$l->t('Ask a CustomerCheck administrator to check Access mode and allowlists under App settings.'),
				],
			};
			$error = 'ACCESS_DENIED';
		}
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
			'hint' => $hint,
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
