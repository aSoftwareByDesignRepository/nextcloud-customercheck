<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Controller\ReviewApiController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Live gate: mark-spam-bulk resolves through DI and returns validation (not route 404).
 */
final class ReviewSpamBulkApiIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
		$user = \OC::$server->get(IUserManager::class)->get(self::ACTOR);
		if ($user === null) {
			$this->markTestSkipped('admin user missing');
		}
		\OC::$server->get(IUserSession::class)->setUser($user);
	}

	public function testMarkSpamBulkEmptyIdsReturnsValidationNotNotFound(): void
	{
		$request = \OC::$server->get(IRequest::class);
		if (method_exists($request, 'setBody')) {
			$request->setBody(json_encode(['ids' => []], JSON_THROW_ON_ERROR));
		}

		$ctrl = \OC::$server->get(ReviewApiController::class);
		$res = $ctrl->markSpamBulk(1);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus(), json_encode($res->getData()));
		$payload = $res->getData();
		$this->assertFalse((bool)($payload['success'] ?? true));
		$this->assertSame(Constants::ERR_VALIDATION, $payload['error'] ?? null);
	}
}
