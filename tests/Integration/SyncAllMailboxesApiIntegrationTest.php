<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Controller\PersonaApiController;
use OCA\CustomerCheck\Controller\ReviewApiController;
use OCP\AppFramework\Http;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Live gate: sync-all resolves through DI (not route 404) and Review exposes mailSync.
 */
final class SyncAllMailboxesApiIntegrationTest extends TestCase
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

	public function testSyncMailboxReturnsStructuredPayloadNotServerError(): void
	{
		$ctrl = \OC::$server->get(PersonaApiController::class);
		$res = $ctrl->syncMailbox(1, 1);
		$this->assertNotSame(Http::STATUS_INTERNAL_SERVER_ERROR, $res->getStatus(), json_encode($res->getData()));
		$this->assertNotSame(Http::STATUS_NOT_FOUND, $res->getStatus(), json_encode($res->getData()));
		$payload = $res->getData();
		$this->assertTrue((bool)($payload['success'] ?? false), json_encode($payload));
		$data = $payload['data'] ?? [];
		$this->assertArrayHasKey('ingested', $data);
		$this->assertArrayHasKey('status', $data);
		$this->assertArrayHasKey('message', $data);
		$this->assertIsInt($data['ingested']);
		$this->assertIsString($data['status']);
		$this->assertIsString($data['message']);
		$this->assertContains($data['status'], ['ok', 'locked', 'missing', 'auth', 'error', 'no_imap']);
	}

	public function testSyncAllMailboxesReturnsStructuredPayloadNotNotFound(): void
	{
		$ctrl = \OC::$server->get(PersonaApiController::class);
		$res = $ctrl->syncAllMailboxes(1);
		$this->assertNotSame(Http::STATUS_NOT_FOUND, $res->getStatus(), json_encode($res->getData()));
		$payload = $res->getData();
		$this->assertTrue((bool)($payload['success'] ?? false), json_encode($payload));
		$data = $payload['data'] ?? [];
		$this->assertArrayHasKey('mailboxes', $data);
		$this->assertArrayHasKey('attempted', $data);
		$this->assertArrayHasKey('message', $data);
		$this->assertIsString($data['message']);
	}

	public function testReviewListItemsIncludesMailSyncKey(): void
	{
		$ctrl = \OC::$server->get(ReviewApiController::class);
		$res = $ctrl->listItems(1);
		$this->assertSame(Http::STATUS_OK, $res->getStatus(), json_encode($res->getData()));
		$data = $res->getData()['data'] ?? [];
		$this->assertArrayHasKey('mailSync', $data);
		if ($data['mailSync'] !== null) {
			$this->assertArrayHasKey('mailboxCount', $data['mailSync']);
			$this->assertArrayHasKey('backgroundIntervalMinutes', $data['mailSync']);
			$this->assertSame(15, $data['mailSync']['backgroundIntervalMinutes']);
		}
	}
}
