<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Argus security regressions — deal checklist ACL / IDOR / denormalised workspace_id.
 */
final class DealChecklistAclSecurityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testWebMutationsGateViaCompanyCapabilityNotLastUsed(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/DealChecklistApiController.php');
		$this->assertStringContainsString('function gateDeal', $ctrl);
		$this->assertStringContainsString('function gateItem', $ctrl);
		$this->assertStringContainsString("requireCompanyCapability((int)\$deal->getCompanyId()", $ctrl);
		$this->assertStringContainsString("requireCompanyCapability((int)\$item->getCompanyId()", $ctrl);
		$this->assertStringNotContainsString('NoCSRFRequired', $ctrl);
		$this->assertStringNotContainsString('PublicPage', $ctrl);
		$this->assertStringContainsString("gateDeal(\$dealId, 'write')", $ctrl);
		$this->assertStringContainsString("gateItem(\$id, 'write')", $ctrl);
		$this->assertStringContainsString("gateDeal(\$dealId, 'read')", $ctrl);
		// WorkspaceScopedTrait::requireCompanyCapability uses $this->companies — missing DI → 500 "Unexpected error".
		$this->assertStringContainsString('use OCA\\CustomerCheck\\Service\\CompanyService;', $ctrl);
		$this->assertMatchesRegularExpression(
			'/private readonly CompanyService \$companies/',
			$ctrl,
			'DealChecklistApiController must inject CompanyService for company-workspace gates',
		);
		$this->assertStringContainsString('Something went wrong. Reload the page and try again.', (string)file_get_contents($this->root . '/js/pages/company-detail.js'));
	}

	public function testMobileDoneGatesOnCompanyWorkspaceNotStaleItemWorkspaceId(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/MobileController.php');
		$done = $this->extractMethod($ctrl, 'doneChecklistItem');
		$this->assertStringContainsString('assertSafeMutationChannel', $done);
		$this->assertStringContainsString('requireMobileWorkspace($uid, $workspaceId, \'write\')', $done);
		$this->assertStringContainsString('requireCompanyInWorkspace((int)$item->getCompanyId(), $wsId)', $done);
		$this->assertStringNotContainsString('getWorkspaceId() !== $wsId', $done);
		// Evidence must come from the client (or a real file) — no server placeholder auto-fill.
		$this->assertStringNotContainsString("evidenceNote'] = 'Done'", $done);
	}

	public function testMoveAndMergeRestampChecklistWorkspaceId(): void
	{
		$move = $this->extractMethod(
			(string)file_get_contents($this->root . '/lib/Service/CompanyService.php'),
			'moveToWorkspace',
		);
		$this->assertStringContainsString("update('crm_deal_cl_item')", $move);
		$this->assertStringContainsString('checklistItemsRestamped', $move);
		$merge = $this->extractMethod(
			(string)file_get_contents($this->root . '/lib/Service/CompanyMergeService.php'),
			'reassignPersonaLedgers',
		);
		$this->assertStringContainsString("update('crm_deal_cl_item')", $merge);
		$this->assertStringContainsString("set('company_id'", $merge);
		$this->assertMatchesRegularExpression(
			"/update\\('crm_deal_cl_item'\\)[\\s\\S]*set\\('workspace_id'/m",
			$merge,
		);
	}

	public function testLinkFileUsesActorScopedFilesAccessNeverRawSql(): void
	{
		$svc = (string)file_get_contents($this->root . '/lib/Service/DealChecklistService.php');
		$link = $this->extractMethod($svc, 'linkFile');
		$this->assertStringContainsString('assertFileUnderFolder($actor, $fileId, $folderId)', $link);
		$this->assertStringContainsString('getFilesFolderId()', $link);
		$this->assertStringContainsString('fileId < 1', $link);
		$this->assertStringNotContainsString('filecache', $link);
	}

	public function testAssigneeMustBeWorkspaceMember(): void
	{
		$svc = (string)file_get_contents($this->root . '/lib/Service/DealChecklistService.php');
		$fn = $this->extractMethod($svc, 'normaliseAssignee');
		$this->assertStringContainsString('findMembershipOrNull($workspaceId, $uid)', $fn);
		$this->assertStringContainsString('assigneeUserId', $fn);
	}

	public function testUpdateItemUsesCompanyWorkspaceNotStaleItemStamp(): void
	{
		$svc = (string)file_get_contents($this->root . '/lib/Service/DealChecklistService.php');
		$fn = $this->extractMethod($svc, 'updateItem');
		$this->assertStringContainsString('(int)($company->getWorkspaceId() ?? 0)', $fn);
		$this->assertStringContainsString('setWorkspaceId($ws)', $fn);
		$this->assertStringNotContainsString('$ws = (int)$item->getWorkspaceId();', $fn);
	}

	public function testTemplateCrossWorkspaceIsNotFound(): void
	{
		$svc = (string)file_get_contents($this->root . '/lib/Service/DealChecklistService.php');
		$fn = $this->extractMethod($svc, 'requireTemplateForWorkspace');
		$this->assertStringContainsString('if ($tmplWs !== $workspaceId)', $fn);
		$this->assertStringContainsString('Checklist template not found.', $fn);
	}

	public function testIdempotentReplayReGatesPathResourceAndScopesKeys(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/MobileController.php');
		$this->assertStringContainsString('function scopedIdempotencyKey', $ctrl);
		$this->assertStringContainsString('function checklistItemPayloadForIdempotentReplay', $ctrl);
		$this->assertStringContainsString('function loadDealChecklistForIdempotentReplay', $ctrl);

		$upload = $this->extractMethod($ctrl, 'uploadChecklistFile');
		$this->assertStringContainsString("scopedIdempotencyKey(\$body, 'cl-upload', 'bytes:'", $upload);
		$this->assertStringContainsString('checklistItemPayloadForIdempotentReplay($existingItemId, $wsId, $id)', $upload);
		$this->assertStringNotContainsString('checklistItemPayload($existingItemId)', $upload);

		$link = $this->extractMethod($ctrl, 'linkChecklistFile');
		$this->assertStringContainsString("scopedIdempotencyKey(\$body, 'cl-link', 'file:'", $link);
		$this->assertStringContainsString('checklistItemPayloadForIdempotentReplay', $link);

		$apply = $this->extractMethod($ctrl, 'applyChecklistTemplate');
		$this->assertStringContainsString("scopedIdempotencyKey(\$body, 'cl-apply', 'tmpl:'", $apply);
		$this->assertStringContainsString('loadDealChecklistForIdempotentReplay', $apply);

		$createCo = $this->extractMethod($ctrl, 'createCompany');
		$this->assertStringContainsString("scopedIdempotencyKey(\$body, 'co-create', '', \$wsId)", $createCo);
		$this->assertStringContainsString('loadCompanyForIdempotentReplay', $createCo);

		$createPe = $this->extractMethod($ctrl, 'createPerson');
		$this->assertStringContainsString("scopedIdempotencyKey(\$body, 'pe-create', '', \$wsId, \$id)", $createPe);
		$this->assertStringContainsString('loadPersonForIdempotentReplay', $createPe);

		$createDe = $this->extractMethod($ctrl, 'createDeal');
		$this->assertStringContainsString("scopedIdempotencyKey(\$body, 'de-create', '', \$wsId, \$id)", $createDe);
		$this->assertStringContainsString('loadDealForIdempotentReplay', $createDe);

		$scoped = $this->extractMethod($ctrl, 'scopedIdempotencyKey');
		$this->assertStringContainsString('$raw . "\\0" . $intent', $scoped);

		$replayItem = $this->extractMethod($ctrl, 'checklistItemPayloadForIdempotentReplay');
		$this->assertStringContainsString('if ($itemId !== $expectedItemId)', $replayItem);
		$this->assertStringContainsString('requireCompanyInWorkspace((int)$item->getCompanyId(), $workspaceId)', $replayItem);

		$replayDeal = $this->extractMethod($ctrl, 'loadDealChecklistForIdempotentReplay');
		$this->assertStringContainsString('if ($dealId !== $expectedDealId)', $replayDeal);
		$this->assertStringContainsString('requireDealInWorkspace($dealId, $workspaceId)', $replayDeal);
	}

	public function testGdprCompanyEraseWithdrawsChecklistNotificationsAfterCommit(): void
	{
		$svc = (string)file_get_contents($this->root . '/lib/Service/CompanyService.php');
		$this->assertStringContainsString('?NotificationService $notifications = null', $svc);
		$erase = $this->extractMethod($svc, 'gdprErase');
		// Must not withdraw inside the DB try (rolled-back erase would orphan notifications wrongly).
		$this->assertStringContainsString('foreach ($checklistItemIds as $checklistItemId)', $erase);
		$this->assertStringContainsString("withdraw('deal_checklist_item'", $erase);
		$this->assertMatchesRegularExpression(
			'/\$this->db->commit\(\);.*?withdraw\(\'deal_checklist_item\'/s',
			$erase,
			'Checklist notification withdraw must run after successful DB commit',
		);
		$helper = $this->extractMethod($svc, 'eraseDealChecklists');
		$this->assertStringContainsString('function eraseDealChecklists(int $companyId): array', $helper);
		$this->assertStringContainsString('return $itemIds;', $helper);
		$twin = $this->extractMethod(
			(string)file_get_contents($this->root . '/lib/Service/DealChecklistService.php'),
			'eraseCompany',
		);
		$this->assertStringContainsString("withdraw('deal_checklist_item'", $twin);
	}

	public function testCompleteAndApplyNeverRollBackAfterCommit(): void
	{
		$svc = (string)file_get_contents($this->root . '/lib/Service/DealChecklistService.php');
		$complete = $this->extractMethod($svc, 'completeItem');
		$this->assertStringContainsString('$idempotentDone = true', $complete);
		$this->assertStringContainsString('if ($idempotentDone)', $complete);
		// serItem/filesGrouped for idempotent Done must sit outside the catch/rollBack path.
		$this->assertDoesNotMatchRegularExpression(
			'/STATUS_DONE\) \{\s*DbTransaction::commit\([^;]+;\s*return \$this->serItem/s',
			$complete,
		);
		$apply = $this->extractMethod($svc, 'applyTemplate');
		$this->assertStringContainsString('$emptyAppend = true', $apply);
		$this->assertStringContainsString('if ($emptyAppend)', $apply);
		$this->assertDoesNotMatchRegularExpression(
			'/willInsert < 1\) \{\s*DbTransaction::commit\([^;]+;\s*\$listed = \$this->listForDeal/s',
			$apply,
		);
	}

	private function extractMethod(string $src, string $name): string
	{
		$pos = strpos($src, 'function ' . $name);
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos);
		if (preg_match('/^function\s+' . preg_quote($name, '/') . '\b.*?(?=\n\tpublic function|\n\tprivate function|\n\tprotected function|\n}\n)/s', $chunk, $m) === 1) {
			return $m[0];
		}

		return substr($chunk, 0, 5000);
	}
}
