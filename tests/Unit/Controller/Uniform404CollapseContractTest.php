<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Controller\ApiControllerTrait;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Middleware\AppAccessMiddleware;
use OCA\CustomerCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Uniform-404 contract (existence-oracle defense).
 *
 * Every "entity not visible" denial — missing id, foreign-workspace id, or
 * entity nested under a foreign scope — must serialize to ONE canonical
 * payload: HTTP 404 + NOT_FOUND + "Not found." + empty fields. Entity-specific
 * codes (COMPANY_NOT_FOUND) and messages ("This company no longer exists.")
 * must never reach the wire.
 */
final class Uniform404CollapseContractTest extends TestCase
{
	private function harness(): object
	{
		return new class {
			use ApiControllerTrait;

			public function run(\Throwable $e): JSONResponse
			{
				return $this->fromThrowable($e);
			}
		};
	}

	public function testMissingAndForeignIdsProduceByteIdentical404(): void
	{
		$missing = $this->harness()->run(
			new NotFoundException('This company no longer exists.', Constants::ERR_COMPANY_NOT_FOUND),
		);
		$foreign = $this->harness()->run(new NotFoundException('Workspace not found.'));
		$foreignEntity = $this->harness()->run(new NotFoundException('Company not found.'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $foreignEntity->getStatus());

		$expected = json_encode([
			'success' => false,
			'error' => Constants::ERR_NOT_FOUND,
			'message' => 'Not found.',
			'fields' => new \stdClass(),
		]);
		$this->assertSame($expected, json_encode($missing->getData()));
		$this->assertSame($expected, json_encode($foreign->getData()));
		$this->assertSame($expected, json_encode($foreignEntity->getData()));
	}

	public function testValidationCarriedNotFoundCollapsesToo(): void
	{
		// Existence lookups thrown as ValidationException(ERR_NOT_FOUND) —
		// promises, account plans, mailbox lookups — must hit the same payload.
		$response = $this->harness()->run(
			ValidationException::field(Constants::ERR_NOT_FOUND, 'id', 'Open promise not found.'),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$body = $response->getData();
		$this->assertSame(Constants::ERR_NOT_FOUND, $body['error']);
		$this->assertSame('Not found.', $body['message']);
		$this->assertEquals(new \stdClass(), $body['fields']);
	}

	public function testNonExistenceErrorsKeepTheirContract(): void
	{
		// The collapse must not swallow real validation / conflict / denial codes.
		$validation = $this->harness()->run(
			ValidationException::field(Constants::ERR_VALIDATION, 'name', 'Name is required.'),
		);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $validation->getStatus());
		$this->assertSame(Constants::ERR_VALIDATION, $validation->getData()['error']);

		$conflict = $this->harness()->run(new ConflictException('stale_updated_at_precondition'));
		$this->assertSame(Http::STATUS_CONFLICT, $conflict->getStatus());
		$this->assertSame(Constants::ERR_CONFLICT_STALE, $conflict->getData()['error']);

		$denied = $this->harness()->run(new AccessDeniedException('nope'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $denied->getStatus());
		$this->assertSame(Constants::ERR_ACCESS_DENIED, $denied->getData()['error']);
	}

	public function testMiddlewareJson404IsCanonical(): void
	{
		$middleware = $this->middleware('/apps/customercheck/api/v1/companies/7');

		$missing = $middleware->afterException(
			new \stdClass(),
			'update',
			new NotFoundException('This company no longer exists.', Constants::ERR_COMPANY_NOT_FOUND),
		);
		$foreign = $middleware->afterException(
			new \stdClass(),
			'update',
			new NotFoundException('Workspace not found.'),
		);

		$this->assertInstanceOf(JSONResponse::class, $missing);
		$this->assertInstanceOf(JSONResponse::class, $foreign);
		$this->assertSame(json_encode($missing->getData()), json_encode($foreign->getData()));
		$this->assertSame('NOT_FOUND', $missing->getData()['error']);
		$this->assertSame('Not found.', $missing->getData()['message']);
	}

	public function testMobileGuardSourceCollapsesNotFound(): void
	{
		// The mobile guard lives in MobileController (no trait) — pin the
		// canonical string so the two envelopes can never diverge again.
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileController.php');
		$this->assertStringContainsString("'Not found.'", $src);
		$mail = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileMailController.php');
		$this->assertStringContainsString("'Not found.'", $mail);
	}

	private function middleware(string $path): AppAccessMiddleware
	{
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getMethod')->willReturn('GET');

		return new AppAccessMiddleware(
			$this->createMock(IUserSession::class),
			$this->createMock(AccessControlService::class),
			$request,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFactory::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
