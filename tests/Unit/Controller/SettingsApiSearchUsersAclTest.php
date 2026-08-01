<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\SettingsApiController;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SettingsApiSearchUsersAclTest extends TestCase
{
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;

	/** @var DirectoryOptionsService&MockObject */
	private DirectoryOptionsService $directory;

	/** @var WorkspaceAccessService&MockObject */
	private WorkspaceAccessService $workspaces;

	/** @var IRequest&MockObject */
	private IRequest $request;

	private SettingsApiController $controller;

	protected function setUp(): void
	{
		$this->access = $this->createMock(AccessControlService::class);
		$this->directory = $this->createMock(DirectoryOptionsService::class);
		$this->workspaces = $this->createMock(WorkspaceAccessService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new SettingsApiController(
			'customercheck',
			$this->request,
			$this->access,
			$this->createMock(SettingsService::class),
			$this->directory,
			$this->createMock(ProductCodeRegistry::class),
			$this->workspaces,
		);
	}

	public function testAppAdminMaySearchWithoutWorkspaceId(): void
	{
		$this->access->method('requireCanUseApp')->willReturn('admin');
		$this->access->method('isAppAdmin')->with('admin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['workspaceId', 0, 0],
			['q', '', 'anna'],
		]);
		$this->directory->expects($this->once())
			->method('searchUsers')
			->with('anna', 20)
			->willReturn([['id' => 'anna', 'displayName' => 'Anna']]);
		$this->workspaces->expects($this->never())->method('assertCapability');

		$res = $this->controller->searchUsers('anna');
		$this->assertSame(200, $res->getStatus());
		$data = $res->getData();
		$this->assertSame([['id' => 'anna', 'displayName' => 'Anna']], $data['data']['users'] ?? $data['users'] ?? null);
	}

	public function testManagerMaySearchWithWorkspaceId(): void
	{
		$this->access->method('requireCanUseApp')->willReturn('mgr');
		$this->access->method('isAppAdmin')->with('mgr')->willReturn(false);
		$this->request->method('getParam')->willReturnMap([
			['workspaceId', 0, 7],
			['q', '', 'bob'],
		]);
		$this->workspaces->expects($this->once())
			->method('assertCapability')
			->with('mgr', 7, 'write');
		$this->directory->method('searchUsers')->willReturn([['id' => 'bob', 'displayName' => 'Bob']]);

		$res = $this->controller->searchUsers('bob');
		$this->assertSame(200, $res->getStatus());
	}

	public function testNonAdminWithoutWorkspaceIdIsDenied(): void
	{
		$this->access->method('requireCanUseApp')->willReturn('viewer');
		$this->access->method('isAppAdmin')->with('viewer')->willReturn(false);
		$this->request->method('getParam')->willReturnMap([
			['workspaceId', 0, 0],
			['q', '', 'x'],
		]);
		$this->workspaces->expects($this->never())->method('assertCapability');
		$this->directory->expects($this->never())->method('searchUsers');

		$res = $this->controller->searchUsers('xy');
		$this->assertGreaterThanOrEqual(400, $res->getStatus());
	}

	public function testShortQueryReturnsEmptyWithoutDirectoryHit(): void
	{
		$this->access->method('requireCanUseApp')->willReturn('admin');
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['workspaceId', 0, 0],
			['q', '', 'a'],
		]);
		$this->directory->expects($this->never())->method('searchUsers');

		$res = $this->controller->searchUsers('a');
		$this->assertSame(200, $res->getStatus());
		$data = $res->getData();
		$users = $data['data']['users'] ?? $data['users'] ?? ['sentinel'];
		$this->assertSame([], $users);
	}
}
