<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use PHPUnit\Framework\TestCase;

/**
 * Real invokes for all WorkspaceCapability publics used by PageShellTrait + board services.
 */
final class WorkspaceCapabilityTest extends TestCase
{
	public function testNormalizeRoleAcceptsCanonicalAndFallsBack(): void
	{
		foreach (Constants::WS_ROLES as $role) {
			$this->assertSame($role, WorkspaceCapability::normalizeRole($role));
			$this->assertSame($role, WorkspaceCapability::normalizeRole(strtoupper($role)));
			$this->assertSame($role, WorkspaceCapability::normalizeRole('  ' . $role . '  '));
		}
		$this->assertSame('viewer', WorkspaceCapability::normalizeRole(''));
		$this->assertSame('viewer', WorkspaceCapability::normalizeRole('owner'));
		$this->assertSame('viewer', WorkspaceCapability::normalizeRole('admin'));
	}

	public function testAllowsMatrixAndAppAdminBypass(): void
	{
		$this->assertTrue(WorkspaceCapability::allows('viewer', 'read'));
		$this->assertFalse(WorkspaceCapability::allows('viewer', 'write'));
		$this->assertTrue(WorkspaceCapability::allows('member', 'write'));
		$this->assertTrue(WorkspaceCapability::allows('member', 'brief.confirm'));
		$this->assertFalse(WorkspaceCapability::allows('member', 'delete'));
		$this->assertTrue(WorkspaceCapability::allows('manager', 'members'));
		$this->assertTrue(WorkspaceCapability::allows('manager', 'bulk'));
		$this->assertTrue(WorkspaceCapability::allows('manager', 'team.coach'));
		$this->assertFalse(WorkspaceCapability::allows('ghost', 'read'));
		$this->assertTrue(WorkspaceCapability::allows('viewer', 'write', true));
		$this->assertTrue(WorkspaceCapability::allows('ghost', 'delete', true));
	}

	public function testCanOpenTeamBoard(): void
	{
		$this->assertFalse(WorkspaceCapability::canOpenTeamBoard('viewer'));
		$this->assertTrue(WorkspaceCapability::canOpenTeamBoard('member'));
		$this->assertTrue(WorkspaceCapability::canOpenTeamBoard('manager'));
		$this->assertTrue(WorkspaceCapability::canOpenTeamBoard('viewer', true));
		$this->assertFalse(WorkspaceCapability::canOpenTeamBoard('unknown'));
	}

	public function testTeamSeesAllRows(): void
	{
		$this->assertFalse(WorkspaceCapability::teamSeesAllRows('viewer'));
		$this->assertFalse(WorkspaceCapability::teamSeesAllRows('member'));
		$this->assertTrue(WorkspaceCapability::teamSeesAllRows('manager'));
		$this->assertTrue(WorkspaceCapability::teamSeesAllRows('member', true));
		$this->assertFalse(WorkspaceCapability::teamSeesAllRows(''));
	}
}
