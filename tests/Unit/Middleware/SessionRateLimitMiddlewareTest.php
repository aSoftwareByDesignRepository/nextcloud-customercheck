<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Middleware;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Controller\MiddlewareTestFeedController;
use OCA\CustomerCheck\Controller\MiddlewareTestPageController;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Middleware\SessionRateLimitMiddleware;
use OCA\CustomerCheck\Service\RateLimitService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MiddlewareTestControllers.php';

final class SessionRateLimitMiddlewareTest extends TestCase
{
	public function testGetIsSkipped(): void
	{
		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->expects($this->never())->method('allow');

		$middleware = $this->middleware(
			path: '/apps/customercheck/api/companies',
			method: 'GET',
			rateLimit: $rateLimit,
			withUser: true,
		);

		$middleware->beforeController(
			new MiddlewareTestPageController($this->createMock(IRequest::class)),
			'index',
		);
	}

	public function testNonApiPathIsSkipped(): void
	{
		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->expects($this->never())->method('allow');

		$middleware = $this->middleware(
			path: '/apps/customercheck/companies',
			method: 'POST',
			rateLimit: $rateLimit,
			withUser: true,
		);

		$middleware->beforeController(
			new MiddlewareTestPageController($this->createMock(IRequest::class)),
			'create',
		);
	}

	public function testPostApiWhenDeniedThrowsRateLimited(): void
	{
		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->expects($this->once())->method('allow')->willReturn(false);

		$middleware = $this->middleware(
			path: '/apps/customercheck/api/companies',
			method: 'POST',
			rateLimit: $rateLimit,
			withUser: true,
		);

		$this->expectException(ValidationException::class);
		try {
			$middleware->beforeController(
				new MiddlewareTestPageController($this->createMock(IRequest::class)),
				'create',
			);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_RATE_LIMITED, $e->getErrorCode());
			throw $e;
		}
	}

	public function testAfterExceptionReturns429Json(): void
	{
		$middleware = $this->middleware(
			path: '/apps/customercheck/api/companies',
			method: 'POST',
			rateLimit: $this->createMock(RateLimitService::class),
			withUser: true,
		);

		$response = $middleware->afterException(
			new MiddlewareTestPageController($this->createMock(IRequest::class)),
			'create',
			new ValidationException(Constants::ERR_RATE_LIMITED, 'Too many requests'),
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(Constants::ERR_RATE_LIMITED, $data['error'] ?? null);
	}

	public function testPublicFeedControllerIsSkipped(): void
	{
		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->expects($this->never())->method('allow');

		$middleware = $this->middleware(
			path: '/apps/customercheck/api/feed/renewals',
			method: 'POST',
			rateLimit: $rateLimit,
			withUser: true,
		);

		$middleware->beforeController(
			new MiddlewareTestFeedController($this->createMock(IRequest::class)),
			'renewals',
		);
	}

	private function middleware(
		string $path,
		string $method,
		RateLimitService $rateLimit,
		bool $withUser,
	): SessionRateLimitMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getMethod')->willReturn($method);

		$session = $this->createMock(IUserSession::class);
		if ($withUser) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new SessionRateLimitMiddleware($request, $session, $rateLimit, $factory);
	}
}
