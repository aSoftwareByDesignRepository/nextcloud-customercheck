<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Middleware;

use OCA\CustomerCheck\Controller\PageController;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Middleware\AppAccessMiddleware;
use OCA\CustomerCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AppAccessMiddlewareTest extends TestCase
{
	public function testAppGateReturnsJsonForApiPath(): void
	{
		$middleware = $this->middleware(
			path: '/apps/customercheck/api/companies',
			method: 'GET',
			canUseApp: false,
		);
		$response = $middleware->afterException(
			$this->createMock(PageController::class),
			'settings',
			new AppAccessDeniedException(),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAdminGateReturnsFriendlyPageForGet(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sales');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->with('sales')->willReturn(true);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/customercheck/settings');
		$request->method('getMethod')->willReturn('GET');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willReturn('/');
		$url->method('linkToRoute')->willReturn('/apps/customercheck/');

		$middleware = new AppAccessMiddleware(
			$session,
			$access,
			$request,
			$url,
			$factory,
			$this->createMock(LoggerInterface::class),
		);

		$response = $middleware->afterException(
			$this->createMock(PageController::class),
			'settings',
			new AccessDeniedException(),
		);
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('guest', $response->getRenderAs());
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAppGateRestrictionRendersAllowlistHint(): void
	{
		$middleware = $this->middleware(
			path: '/apps/customercheck/today',
			method: 'GET',
			canUseApp: false,
		);
		$response = $middleware->afterException(
			$this->createMock(PageController::class),
			'today',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$params = $response->getParams();
		$this->assertStringContainsString('restricts CustomerCheck access', (string)($params['message'] ?? ''));
		$this->assertStringContainsString('Access mode', (string)($params['hint'] ?? ''));
	}

	public function testNotFoundHtmlReturns404WithFriendlyPage(): void
	{
		$middleware = $this->middleware(
			path: '/apps/customercheck/companies/99999',
			method: 'GET',
			canUseApp: true,
		);
		$response = $middleware->afterException(
			$this->createMock(PageController::class),
			'companyDetail',
			new \OCA\CustomerCheck\Exception\NotFoundException('Company not found.'),
		);
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('guest', $response->getRenderAs());
	}

	public function testUnhandledThrowableOnApiPathReturnsJson500(): void
	{
		$middleware = $this->middleware(
			path: '/apps/customercheck/api/v1/companies',
			method: 'GET',
			canUseApp: true,
		);
		$response = $middleware->afterException(
			$this->createMock(PageController::class),
			'companies',
			new \RuntimeException('db gone'),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$body = $response->getData();
		$this->assertFalse($body['success']);
		$this->assertSame('SERVER_ERROR', $body['error']);
		$this->assertStringNotContainsString('db gone', (string)($body['message'] ?? ''));
	}

	public function testUnhandledThrowableOnPageRouteRethrows(): void
	{
		$middleware = $this->middleware(
			path: '/apps/customercheck/companies',
			method: 'GET',
			canUseApp: true,
		);
		$this->expectException(\RuntimeException::class);
		$middleware->afterException(
			$this->createMock(PageController::class),
			'companies',
			new \RuntimeException('db gone'),
		);
	}

	public function testUnhandledThrowableOnApiWriteReturnsJson500(): void
	{
		$middleware = $this->middleware(
			path: '/apps/customercheck/companies/5',
			method: 'POST',
			canUseApp: true,
		);
		$response = $middleware->afterException(
			$this->createMock(PageController::class),
			'updateCompany',
			new \RuntimeException('db gone'),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testBeforeControllerThrowsRestrictionReason(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('outsider');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->with('outsider')->willReturn(false);
		$access->method('denialReasonWhenCannotUseApp')->with('outsider')
			->willReturn(AccessControlService::DENIAL_RESTRICTION);

		$request = $this->createMock(IRequest::class);
		$middleware = new AppAccessMiddleware(
			$session,
			$access,
			$request,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFactory::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(AppAccessDeniedException::class);
		$this->expectExceptionMessage(AccessControlService::DENIAL_RESTRICTION);
		$middleware->beforeController($this->createMock(PageController::class), 'today');
	}

	private function middleware(string $path, string $method, bool $canUseApp): AppAccessMiddleware
	{
		$session = $this->createMock(IUserSession::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->willReturn($canUseApp);
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getMethod')->willReturn($method);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willReturn('/');
		$url->method('linkToRoute')->willReturn('/apps/customercheck/');

		return new AppAccessMiddleware(
			$session,
			$access,
			$request,
			$url,
			$factory,
			$this->createMock(LoggerInterface::class),
		);
	}
}
