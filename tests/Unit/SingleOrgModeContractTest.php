<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed single-organisation surface — controller + repair + access pin.
 */
final class SingleOrgModeContractTest extends TestCase
{
	public function testFlagsAreHardOff(): void
	{
		$this->assertFalse(Constants::MULTI_WORKSPACE_ENABLED);
		$this->assertSame(1, Constants::WS_MAX_PER_INSTANCE);
	}

	public function testPersonaApiRejectsCreateAndMoveAtController(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('function createWorkspace', $src);
		$this->assertStringContainsString('function moveCompany', $src);
		$this->assertStringContainsString(
			"if (!Constants::MULTI_WORKSPACE_ENABLED && \$this->workspaceService->listAll() !== [])",
			$src,
		);
		$this->assertStringNotContainsString(
			'if (false && !Constants::MULTI_WORKSPACE_ENABLED && $this->workspaceService->listAll()',
			$src,
		);
		$movePos = strpos($src, 'function moveCompany');
		$this->assertNotFalse($movePos);
		$moveBody = substr($src, $movePos, 500);
		$this->assertStringContainsString("if (!Constants::MULTI_WORKSPACE_ENABLED)", $moveBody);
		$this->assertStringContainsString('cannot be moved between workspaces', $moveBody);
		$this->assertStringNotContainsString('if (false && !Constants::MULTI_WORKSPACE_ENABLED)', $moveBody);
	}

	public function testBootstrapNeverInsertsSecondBookWhenAnyExists(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Repair/BootstrapDefaultWorkspace.php');
		$this->assertStringContainsString('Promoted existing workspace', $src);
		$this->assertStringContainsString('never mint a second book', $src);
		$this->assertMatchesRegularExpression('/if\s*\(\s*\$anyRow\s*!==\s*false\s*\)/', $src);
		$this->assertDoesNotMatchRegularExpression('/if\s*\(\s*false\s*&&\s*\$anyRow\s*!==\s*false\s*\)/', $src);
		// Promote path must run before insert of Organisation.
		$promotePos = strpos($src, 'Promoted existing workspace');
		$insertPos = strpos($src, "createNamedParameter('organisation')");
		$this->assertNotFalse($promotePos);
		$this->assertNotFalse($insertPos);
		$this->assertLessThan($insertPos, $promotePos);
	}

	public function testAccessPinsListsAndNeedsPickerWhenSingleOrg(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/WorkspaceAccessService.php');
		$this->assertStringContainsString('MULTI_WORKSPACE_ENABLED', $src);
		$this->assertStringContainsString('canonicalWorkspaceOrNull', $src);
		$this->assertStringContainsString('visibleWorkspaceEntities', $src);
		$this->assertStringContainsString("'needsPicker' => false", $src);
		$this->assertStringContainsString('forceNoPicker', $src);
	}

	public function testCreateUsesExclusiveLockAndTransaction(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/WorkspaceService.php');
		$this->assertStringContainsString('customercheck/workspace_create', $src);
		$this->assertStringContainsString('ILockingProvider::LOCK_EXCLUSIVE', $src);
		$this->assertStringContainsString('DbTransaction::begin', $src);
		$this->assertStringContainsString('DbTransaction::rollBack', $src);
		$this->assertStringContainsString('releaseLock', $src);
	}

	public function testUserFacingCopyAvoidsWorkspaceJargon(): void
	{
		$nav = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$settings = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings.php');
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/parts/workspace-boot-status.php');
		$circles = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/circles-roll-call.php');
		$tokens = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/tokens.php');
		$this->assertStringContainsString("\$l->t('Team & mailbox')", $nav);
		$this->assertStringContainsString("\$l->t('Team & mailbox')", $settings);
		$this->assertStringNotContainsString("\$l->t('Workspace')", $settings);
		$this->assertStringContainsString("\$l->t('Loading team settings…')", $boot);
		$this->assertStringNotContainsString('Loading workspace…', $boot);
		$this->assertStringContainsString("\$l->t('Did the team keep its weekly promises?')", $circles);
		$this->assertStringNotContainsString('each workspace', $circles);
		$this->assertStringNotContainsString('Per-workspace feeds', $tokens);
		$this->assertStringNotContainsString('Workspace & mailbox', $tokens);
	}
}
