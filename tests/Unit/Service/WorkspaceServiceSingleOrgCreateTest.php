<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CirclesDirectoryService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * Single-organisation create lock — cap + exclusive lock + no second book.
 */
final class WorkspaceServiceSingleOrgCreateTest extends TestCase
{
	public function testCreateRejectedWhenOrganisationAlreadyExists(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('admin')->willReturn(true);

		$workspaces = $this->createMock(WorkspaceMapper::class);
		$workspaces->expects($this->once())->method('countAll')->willReturn(1);
		$workspaces->expects($this->never())->method('insert');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with('customercheck/workspace_create', ILockingProvider::LOCK_EXCLUSIVE);
		$locking->expects($this->once())->method('releaseLock')
			->with('customercheck/workspace_create', ILockingProvider::LOCK_EXCLUSIVE);

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');

		$svc = $this->service($workspaces, $access, $db, $locking);
		try {
			$svc->create('Second', 'second-org', 'admin');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_WS_LIMIT, $e->getErrorCode());
			$this->assertArrayHasKey('workspace', $e->getFields());
			$this->assertStringContainsString('single organisation', strtolower($e->getFields()['workspace']));
		}
	}

	public function testCreateRequiresAppAdmin(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('member')->willReturn(false);
		$workspaces = $this->createMock(WorkspaceMapper::class);
		$workspaces->expects($this->never())->method('countAll');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');
		$db = $this->createMock(IDBConnection::class);

		$svc = $this->service($workspaces, $access, $db, $locking);
		$this->expectException(ValidationException::class);
		$svc->create('X', 'xxx-org', 'member');
	}

	public function testCreateSourceMarksFirstBookDefault(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkspaceService.php');
		$this->assertStringContainsString('setIsDefault((!Constants::MULTI_WORKSPACE_ENABLED && $existing === 0) ? 1 : 0)', $src);
	}

	private function service(
		WorkspaceMapper $workspaces,
		AccessControlService $access,
		IDBConnection $db,
		ILockingProvider $locking,
	): WorkspaceService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return new WorkspaceService(
			$workspaces,
			$this->createMock(WorkspaceMemberMapper::class),
			$access,
			$db,
			$this->createMock(ITimeFactory::class),
			$l10n,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(CirclesDirectoryService::class),
			$locking,
		);
	}
}
