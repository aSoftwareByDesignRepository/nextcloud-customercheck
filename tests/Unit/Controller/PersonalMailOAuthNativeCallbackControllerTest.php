<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\PersonaApiController;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Native OAuth callback must stay passive (no complete authorize / no state burn).
 */
final class PersonalMailOAuthNativeCallbackControllerTest extends TestCase
{
	public function testNativeStateReturnsHtmlWithoutExchange(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['state', '', 'native-state'],
			['code', '', 'auth-code'],
		]);

		$oauth = $this->createMock(PersonalMailOAuthService::class);
		$oauth->expects($this->once())->method('isNativeOAuthState')->with('native-state')->willReturn(true);
		$oauth->expects($this->never())->method('completeAuthorize');

		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alex');

		$ctrl = $this->buildController($request, $access, $oauth);
		$res = $ctrl->personalMailOAuthCallback();
		$this->assertInstanceOf(DataDisplayResponse::class, $res);
		$body = '';
		if (method_exists($res, 'render')) {
			$body = (string)$res->render();
		}
		if ($body === '' && method_exists($res, 'getData')) {
			$body = (string)$res->getData();
		}
		$this->assertStringContainsString('CustomerCheck', $body);
		$this->assertStringContainsString('role="status"', $body);
	}

	private function buildController(IRequest $request, AccessControlService $access, PersonalMailOAuthService $oauth): PersonaApiController
	{
		$ref = new \ReflectionClass(PersonaApiController::class);
		/** @var PersonaApiController $ctrl */
		$ctrl = $ref->newInstanceWithoutConstructor();
		$parent = $ref->getParentClass();
		if ($parent !== false && $parent->hasProperty('request')) {
			$p = $parent->getProperty('request');
			$p->setAccessible(true);
			$p->setValue($ctrl, $request);
		}
		foreach ([
			'access' => $access,
			'personalMailOAuth' => $oauth,
			'l10n' => $this->l10n(),
		] as $name => $value) {
			$p = $ref->getProperty($name);
			$p->setAccessible(true);
			$p->setValue($ctrl, $value);
		}

		return $ctrl;
	}

	private function l10n(): \OCP\IL10N
	{
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		return $l10n;
	}
}
