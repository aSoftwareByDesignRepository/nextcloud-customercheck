<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\TodayApiController;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Exception\NotAuthenticatedException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CommandBoardService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Widget snapshot endpoint (SPEC NH-P2): single cached document per sync.
 *
 * Legs: access door before the cache read, cache-hit short-circuits the board
 * assembly, miss wraps the command doc + serverNow at 60 s TTL, command errors
 * pass through uncached at their real status, unauthenticated maps to the 401
 * envelope.
 */
final class WidgetSnapshotTest extends TestCase
{
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var CommandBoardService&MockObject */
	private CommandBoardService $board;
	/** @var WorkspaceAccessService&MockObject */
	private WorkspaceAccessService $workspaces;
	/** @var ICacheFactory&MockObject */
	private ICacheFactory $cacheFactory;
	/** @var ICache&MockObject */
	private ICache $cache;

	private TodayApiController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->access = $this->createMock(AccessControlService::class);
		$this->board = $this->createMock(CommandBoardService::class);
		$this->workspaces = $this->createMock(WorkspaceAccessService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory->method('createDistributed')
			->with('customercheck_wsnap')
			->willReturn($this->cache);

		$this->controller = new TodayApiController(
			'customercheck',
			$this->createMock(IRequest::class),
			$this->access,
			$this->createMock(TodayService::class),
			$this->board,
			$this->workspaces,
			$this->cacheFactory,
		);
	}

	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function workspaceCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);
		return [
			'workspace' => $ws,
			'role' => 'member',
			'isAppAdmin' => false,
			'member' => null,
			'needsPicker' => false,
		];
	}

	private function stubDoor(): void
	{
		$this->access->method('requireCanUseApp')->willReturn('alice');
		$this->access->method('currentUserId')->willReturn('alice');
		$this->workspaces->method('resolveForUser')->willReturn($this->workspaceCtx());
	}

	public function testCacheHitSkipsBoardAssembly(): void
	{
		$this->stubDoor();
		$cached = [
			'success' => true,
			'data' => ['lanes' => [], 'meta' => [], 'serverNow' => '2026-11-08T08:00:00+00:00'],
			'meta' => [],
		];
		$this->cache->method('get')->with('alice:7:command')->willReturn($cached);
		$this->board->expects(self::never())->method('build');

		$response = $this->controller->widgetSnapshot();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($cached, $response->getData());
	}

	public function testMissBuildsWrapsAndCaches(): void
	{
		$this->stubDoor();
		$this->cache->method('get')->with('alice:7:command')->willReturn(null);
		$this->board->method('build')->willReturn(['lanes' => ['x'], 'meta' => ['n' => 1]]);
		$this->cache->expects(self::once())
			->method('set')
			->with(
				'alice:7:command',
				self::callback(static fn ($payload): bool => is_array($payload)
					&& ($payload['success'] ?? false) === true
					&& isset($payload['data']['serverNow'], $payload['data']['lanes'])
					&& ($payload['data']['meta']['n'] ?? null) === 1),
				60,
			);

		$response = $this->controller->widgetSnapshot();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(['x'], $data['data']['lanes']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['data']['serverNow']);
	}

	public function testWorkspaceSwitchMissesOldCacheKey(): void
	{
		$this->access->method('requireCanUseApp')->willReturn('alice');
		$this->access->method('currentUserId')->willReturn('alice');
		$this->workspaces->method('resolveForUser')->willReturn($this->workspaceCtx(42));
		$this->cache->expects(self::once())->method('get')->with('alice:42:command')->willReturn(null);
		$this->board->method('build')->willReturn(['lanes' => [], 'meta' => []]);

		$response = $this->controller->widgetSnapshot();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCommandErrorPassesThroughUncached(): void
	{
		$this->stubDoor();
		$this->cache->method('get')->willReturn(null);
		$this->board->method('build')
			->willThrowException(new NotFoundException('workspace'));
		$this->cache->expects(self::never())->method('set');

		$response = $this->controller->widgetSnapshot();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('NOT_FOUND', $data['error']);
	}

	public function testUnauthenticatedMapsTo401Envelope(): void
	{
		$this->access->method('requireCanUseApp')
			->willThrowException(new NotAuthenticatedException());
		$this->cache->expects(self::never())->method('get');

		$response = $this->controller->widgetSnapshot();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('NOT_AUTHENTICATED', $data['error']);
	}

	public function testAccessDeniedBeforeCacheRead(): void
	{
		$this->access->method('requireCanUseApp')
			->willThrowException(new AppAccessDeniedException('ACCESS_DENIED'));
		$this->cache->expects(self::never())->method('get');

		$response = $this->controller->widgetSnapshot();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('ACCESS_DENIED', $data['error']);
	}
}
