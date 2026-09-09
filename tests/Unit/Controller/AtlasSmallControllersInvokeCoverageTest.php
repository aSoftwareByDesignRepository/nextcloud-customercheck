<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\HealthController;
use OCA\CustomerCheck\Controller\IdentityHealthApiController;
use OCA\CustomerCheck\Controller\ImportApiController;
use OCA\CustomerCheck\Controller\LeadWebhookController;
use OCA\CustomerCheck\Controller\MoneyApiController;
use OCA\CustomerCheck\Controller\SummaryApiController;
use OCA\CustomerCheck\Controller\TodayApiController;
use OCA\CustomerCheck\Controller\WebsiteOrderWebhookController;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CommandBoardService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCA\CustomerCheck\Service\ImportService;
use OCA\CustomerCheck\Service\LeadWebhookService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WebsiteOrderWebhookService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Atlas v3 used-function invoke coverage for small API controllers.
 * Each public route action is called (not merely reflected).
 */
final class AtlasSmallControllersInvokeCoverageTest extends TestCase
{
	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function workspaceCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);

		return [
			'workspace' => $ws,
			'role' => 'manager',
			'isAppAdmin' => true,
			'member' => null,
			'needsPicker' => false,
		];
	}

	public function testHealthControllerCheck(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$ctrl = new HealthController(
			'customercheck',
			$this->createMock(IRequest::class),
			$db,
			$this->createMock(LoggerInterface::class),
		);
		$res = $ctrl->check();
		self::assertInstanceOf(JSONResponse::class, $res);
		self::assertSame(200, $res->getStatus());
	}

	public function testLeadWebhookReceive(): void
	{
		$leads = $this->createMock(LeadWebhookService::class);
		$leads->method('handle')->willReturn(['id' => 1]);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$ctrl = new LeadWebhookController('customercheck', $request, $leads);
		$res = $ctrl->receive('tok');
		self::assertSame(201, $res->getStatus());
		self::assertTrue($res->getData()['success']);
	}

	public function testWebsiteOrderWebhookReceive(): void
	{
		$orders = $this->createMock(WebsiteOrderWebhookService::class);
		$orders->method('handle')->willReturn(['id' => 2]);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$ctrl = new WebsiteOrderWebhookController('customercheck', $request, $orders);
		$res = $ctrl->receive('tok');
		self::assertSame(201, $res->getStatus());
		self::assertTrue($res->getData()['success']);

		$denyOrders = $this->createMock(WebsiteOrderWebhookService::class);
		$denyOrders->method('handle')->willThrowException(
			ValidationException::field('HOOK_TOKEN_INVALID', 'token', 'bad'),
		);
		$deny = new WebsiteOrderWebhookController('customercheck', $request, $denyOrders);
		$neg = $deny->receive('bad-tok');
		self::assertInstanceOf(JSONResponse::class, $neg);
		self::assertGreaterThanOrEqual(400, $neg->getStatus());
	}

	public function testIdentityHealthSummaryHappyAndAuthz(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$access->method('canManageAppConfiguration')->willReturnOnConsecutiveCalls(true, false);
		$health = $this->createMock(IdentityLinkHealthService::class);
		$health->method('adminHealthCounts')->willReturn(['linked' => 1]);
		$ctrl = new IdentityHealthApiController(
			'customercheck',
			$this->createMock(IRequest::class),
			$access,
			$health,
		);
		$ok = $ctrl->summary();
		self::assertSame(200, $ok->getStatus());
		self::assertTrue($ok->getData()['success']);
		$deny = $ctrl->summary();
		self::assertSame(403, $deny->getStatus());
	}

	public function testMoneyIndex(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireCanUseApp')->willReturn('alice');
		$access->method('currentUserId')->willReturn('alice');
		$money = $this->createMock(MoneyService::class);
		$money->method('overview')->willReturn(['items' => [], 'meta' => ['total' => 0]]);
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->workspaceCtx());
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$ctrl = new MoneyApiController('customercheck', $request, $access, $money, $workspaces);
		$res = $ctrl->index();
		self::assertSame(200, $res->getStatus());
		self::assertTrue($res->getData()['success']);
	}

	public function testSummaryIndex(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireCanUseApp')->willReturn('alice');
		$access->method('currentUserId')->willReturn('alice');
		$today = $this->createMock(TodayService::class);
		$today->method('build')->willReturn(['items' => [1, 2], 'meta' => []]);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('capabilityMatrix')->willReturn(['mail' => true]);
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->workspaceCtx());
		$ctrl = new SummaryApiController(
			'customercheck',
			$this->createMock(IRequest::class),
			$access,
			$today,
			$facades,
			$workspaces,
		);
		$res = $ctrl->index();
		self::assertSame(200, $res->getStatus());
		self::assertSame(2, $res->getData()['data']['todayCount']);
	}

	public function testTodayIndexAndCommand(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireCanUseApp')->willReturn('alice');
		$access->method('currentUserId')->willReturn('alice');
		$today = $this->createMock(TodayService::class);
		$today->method('build')->willReturn(['items' => [], 'meta' => ['n' => 0]]);
		$board = $this->createMock(CommandBoardService::class);
		$board->method('build')->willReturn(['lanes' => [], 'meta' => []]);
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->workspaceCtx());
		$ctrl = new TodayApiController(
			'customercheck',
			$this->createMock(IRequest::class),
			$access,
			$today,
			$board,
			$workspaces,
		);
		$idx = $ctrl->index();
		self::assertSame(200, $idx->getStatus());
		$cmd = $ctrl->command();
		self::assertSame(200, $cmd->getStatus());
	}

	public function testImportScanAndApply(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireCanUseApp')->willReturn('alice');
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->workspaceCtx());
		$workspaces->method('assertCapability');
		$imports = $this->createMock(ImportService::class);
		$imports->method('scan')->willReturn(['rows' => []]);
		$imports->method('apply')->willReturn(['applied' => 0]);
		$ctrl = new ImportApiController(
			'customercheck',
			$this->createMock(IRequest::class),
			$access,
			$workspaces,
			$imports,
		);
		self::assertSame(200, $ctrl->scan()->getStatus());
		self::assertSame(200, $ctrl->apply()->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new ImportApiController(
			'customercheck',
			$this->createMock(IRequest::class),
			$denyAccess,
			$workspaces,
			$imports,
		);
		foreach ([
			static fn () => $deny->scan(),
			static fn () => $deny->apply(),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'import authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'import authz idx ' . $i);
		}
	}
}
