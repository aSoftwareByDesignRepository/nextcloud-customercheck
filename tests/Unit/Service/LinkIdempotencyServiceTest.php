<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\LinkIdempotencyMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\LinkIdempotencyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** SHARED-IDENTITY MH-12 / §9.3b — Idempotency-Key required on createOrLink HTTP. */
final class LinkIdempotencyServiceTest extends TestCase
{
	private function service(): LinkIdempotencyService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $msg, array $args = []) => vsprintf($msg, $args));

		return new LinkIdempotencyService(
			$this->createMock(LinkIdempotencyMapper::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);
	}

	public function testRequireKeyThrowsWhenMissing(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Idempotency-Key')->willReturn('');

		$this->expectException(ValidationException::class);
		$this->service()->requireKey($request, []);
	}

	public function testRequireKeyAcceptsHeader(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Idempotency-Key')->willReturn('abc-123');
		$this->assertSame('abc-123', $this->service()->requireKey($request, []));
	}

	public function testRequireKeyAcceptsBody(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Idempotency-Key')->willReturn('');
		$this->assertSame('body-key', $this->service()->requireKey($request, ['idempotencyKey' => 'body-key']));
	}

	public function testNormaliseRejectsBadCharacters(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->normaliseKey('bad key!');
	}

	public function testControllerRequiresKeyOnCreateOrLink(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/CompanyApiController.php');
		$this->assertSame(2, substr_count($src, '->requireKey($this->request, $body)'));
		$this->assertStringNotContainsString('->resolveKey($this->request, $body)', $src);
	}
}
