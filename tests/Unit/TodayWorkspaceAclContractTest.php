<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Today surfaces must never blend workspaces (product §3.3 / §21.1). */
final class TodayWorkspaceAclContractTest extends TestCase
{
	public function testTodayApiPassesResolvedWorkspace(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/TodayApiController.php');
		$this->assertStringContainsString('resolveForUser', $src);
		$this->assertStringContainsString('today->build(null, $wsId > 0 ? $wsId : null)', $src);
		$this->assertStringNotContainsString('today->build()', $src);
	}

	public function testSummaryApiPassesResolvedWorkspace(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/SummaryApiController.php');
		$this->assertStringContainsString('WorkspaceAccessService', $src);
		$this->assertStringContainsString('today->build(null, $wsId > 0 ? $wsId : null)', $src);
	}

	public function testCommandBoardLegacySectionsTakeWorkspace(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CommandBoardService.php');
		$this->assertStringContainsString('legacySections($today, $companyNames, $workspaceId > 0 ? $workspaceId : null)', $src);
		$this->assertStringContainsString('findOpenDueUpTo($plus7, 500, $wsFilter)', $src);
		$this->assertStringContainsString('findRenewalCandidates(2000, 0, $wsFilter)', $src);
		$this->assertStringContainsString('findOpenStaleBefore($staleCutoff, 200, $wsFilter)', $src);
		$this->assertStringContainsString('companyInWorkspace(', $src);
	}

	public function testTodayTemplateNeverPrintsRawKind(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$this->assertStringContainsString('kindLabel', $html);
		$this->assertStringNotContainsString('<?php p($kind); ?>', $html);
	}

	public function testTodayServiceEmitsKindLabels(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/TodayService.php');
		$this->assertStringContainsString("'kindLabel' => \$this->labels->todayKind(", $src);
	}

	public function testAccountPlanHydrateUsesCallbacksAndRenewals(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccountPlanService.php');
		$this->assertStringContainsString('countOpenCallbacksOverdue', $src);
		$this->assertStringContainsString('hasRenewalHot', $src);
		$this->assertStringNotContainsString("'openCallbacks' => 0", $src);
		$this->assertStringNotContainsString("'renewalsHot' => false", $src);
	}

	public function testBulkReassignFiltersAllEntityTypes(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CoachingService.php');
		$this->assertStringContainsString('filterOwnedRowsInWorkspace', $src);
		$this->assertStringContainsString('$scopedCompanyIds', $src);
		$this->assertStringContainsString('$scopedPromiseIds', $src);
		$this->assertStringContainsString('$scopedCommitmentIds', $src);
	}
}
