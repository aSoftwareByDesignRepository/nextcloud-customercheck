<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Companion path-scoped routes must not rewrite ambient last-used workspace. */
final class MobileWorkspaceRememberContractTest extends TestCase
{
	public function testRequireMobileWorkspacePassesRememberFalse(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileController.php');
		$this->assertStringContainsString(
			'assertCapability($uid, $workspaceId, $capability, false)',
			$src,
		);
		$this->assertStringContainsString(
			'resolveForUser($uid, $workspaceId, false)',
			$src,
		);
		$this->assertStringContainsString('requireMobileWorkspaceContext', $src);
		$this->assertStringContainsString("WorkspaceCapability::allows(\$wsCtx['role'], 'bulk', false)", $src);
	}

	public function testMobileMailAssertCapabilityNeverRemembers(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileMailController.php');
		$this->assertStringContainsString('assertCapability($uid, $workspaceId,', $src);
		$this->assertStringNotContainsString("assertCapability(\$uid, \$workspaceId, 'mail.read');", $src);
		$this->assertStringNotContainsString("assertCapability(\$uid, \$workspaceId, 'write');", $src);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'mail.read', false)", $src);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'mail.send', false)", $src);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'write', false)", $src);
		$bare = preg_match_all('/assertCapability\(\$uid, \$workspaceId, \'[^\']+\'\);/', $src);
		$this->assertSame(0, $bare, 'Mobile mail must pass rememberLast=false on every assertCapability call');
	}

	public function testAssertCapabilityAcceptsRememberFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkspaceAccessService.php');
		$this->assertStringContainsString(
			'bool $rememberLast = true',
			$src,
		);
		$this->assertStringContainsString(
			'function assertCapability(string $userId, int $workspaceId, string $capability, bool $rememberLast = true)',
			$src,
		);
		$this->assertStringContainsString(
			'resolveForUser($userId, $workspaceId, $rememberLast)',
			$src,
		);
	}

	public function testResolveForUserAcceptsRememberFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkspaceAccessService.php');
		$this->assertStringContainsString(
			'bool $rememberLast = true',
			$src,
		);
		$this->assertStringContainsString('if ($rememberLast)', $src);
	}

	public function testMembersRequiresWriteCapability(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileController.php');
		$pos = strpos($src, 'function members(int $workspaceId)');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 600);
		$this->assertStringContainsString(
			"requireMobileWorkspace(\$uid, \$workspaceId, 'write')",
			$chunk,
		);
	}

	public function testCreateCompanyStripsMassAssignFields(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileController.php');
		$pos = strpos($src, 'function createCompany(int $workspaceId)');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 1200);
		$this->assertStringContainsString("unset(", $chunk);
		$this->assertStringContainsString("accountOwnerUserId", $chunk);
		$this->assertStringContainsString("pcCustomerId", $chunk);
		$this->assertStringContainsString("tcCustomerId", $chunk);
	}

	public function testCreateDealGatesOwnerReassign(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileController.php');
		$pos = strpos($src, 'function createDeal(int $workspaceId, int $id)');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 1400);
		$this->assertStringContainsString("unset(\$body['ownerUserId'])", $chunk);
		$this->assertStringContainsString("WorkspaceCapability::allows(\$wsCtx['role'], 'bulk', false)", $chunk);
	}
}
