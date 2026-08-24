<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Deal checklists (CUC-DEAL-CHECKLIST-1).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-deal-checklist-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$lockPath = $appRoot . '/.mutation.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another mutation run holds {$lockPath} — aborting.\n");
	exit(1);
}
register_shutdown_function(static function () use ($lockFh, $lockPath): void {
	flock($lockFh, LOCK_UN);
	fclose($lockFh);
	@unlink($lockPath);
});

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function deal_checklist_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Controller/DealChecklistApiController.php',
			'from' => "/** Required by WorkspaceScopedTrait::requireCompanyCapability / requireCompanyInResolvedWorkspace. */\n\t\tprivate readonly CompanyService \$companies,\n\t\tprivate readonly DealService \$deals,",
			'to' => "private readonly DealService \$deals,",
			'label' => 'checklist_api_drops_company_service_di',
			'filter' => 'DealChecklistAclSecurityContractTest::testWebMutationsGateViaCompanyCapabilityNotLastUsed|WorkspaceAclSurfaceContractTest::testCompanyOwnedMutationsUseCompanyWorkspaceWrite',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "\$this->items->deleteOpenByDeal(\$dealId);",
			'to' => "\$this->items->deleteByDeal(\$dealId);",
			'label' => 'replace_wipes_done_history',
			'filter' => 'DealChecklistServiceTest::testApplyTemplateReplaceDeletesOpenOnlyNotDone|DealChecklistUxContractTest::testReplaceUsesOpenOnlyDelete',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "if (\$note === '' && \$fileCount < 1) {",
			'to' => "if (false && \$note === '' && \$fileCount < 1) {",
			'label' => 'complete_skips_evidence_rule',
			'filter' => 'DealChecklistServiceTest::testCompleteRequiresEvidenceWhenNoFiles',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "if (\$fileId < 1) {",
			'to' => "if (false && \$fileId < 1) {",
			'label' => 'link_file_allows_zero_id',
			'filter' => 'DealChecklistServiceTest::testLinkFileRejectsZeroFileId',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'data-crm-deal-checklist',
			'to' => 'data-crm-x-checklist',
			'label' => 'company360_drops_checklist_button',
			'filter' => 'DealChecklistUxContractTest::testCompanyDetailExposesChecklistButtonOnDeals',
		],
		[
			'file' => $appRoot . '/appinfo/routes.php',
			'from' => "['name' => 'mobile#doneChecklistItem', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/checklist-items/{id}/done', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\\d+', 'id' => '\\d+']],",
			'to' => "// mutated: mobile checklist done removed\n\t\t",
			'label' => 'mobile_drops_checklist_done_route',
			'filter' => 'DealChecklistUxContractTest::testMobileRoutesExposeChecklistDone',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "if ((int)\$tmpl->getIsSystem() === 1 || (int)\$tmpl->getWorkspaceId() === 0) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'templateId',\n\t\t\t\t\$this->l10n->t('Built-in templates cannot be edited. Make a copy first.'),\n\t\t\t);",
			'to' => "if (false && ((int)\$tmpl->getIsSystem() === 1 || (int)\$tmpl->getWorkspaceId() === 0)) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'templateId',\n\t\t\t\t\$this->l10n->t('Built-in templates cannot be edited. Make a copy first.'),\n\t\t\t);",
			'label' => 'allows_edit_system_template',
			'filter' => 'DealChecklistServiceTest::testUpdateTemplateRejectsSystemTemplates',
		],
		[
			'file' => $appRoot . '/templates/settings.php',
			'from' => "'checklists' => 'workspace-checklists.php',",
			'to' => "// mutated checklists section removed\n",
			'label' => 'settings_drops_checklist_section',
			'filter' => 'DealChecklistUxContractTest::testSettingsExposesChecklistTemplateSection',
		],
		[
			'file' => $appRoot . '/lib/Migration/Version1011Date20260811200000.php',
			'from' => "'key' => 'hardware_order'",
			'to' => "'key' => 'hardware_order_gone'",
			'label' => 'drops_hardware_order_template_key',
			'filter' => 'DealChecklistUxContractTest::testSystemTemplatesIncludeHardwareGoodsOrder',
		],
		[
			'file' => $appRoot . '/lib/Service/DealService.php',
			'from' => "\$deal->setProducts(isset(\$data['products']) ? trim((string)\$data['products']) : null);",
			'to' => "\$deal->setProducts(null);",
			'label' => 'create_ignores_products_text',
			'filter' => 'DealServiceProductsAgnosticTest::testCreateAcceptsHardwareAndMixedProductsText',
		],
		[
			'file' => $appRoot . '/lib/Service/CommandBoardService.php',
			'from' => "'checklistItemId' => \$row['checklistItemId'] ?? null,\n\t\t\t\t\t\t\t'updatedAt' => \$row['updatedAt'] ?? null,\n\t\t\t\t\t\t\t'covering' => \$covering,\n\t\t\t\t\t\t\t'coveringPrimary' => \$covering ? \$owner : null,\n\t\t\t\t\t\t\t'coveringBadge' => \$covering ? \$this->l10n->t('Covering') : null,",
			'to' => "'checklistItemId' => \$row['checklistItemId'] ?? null,\n\t\t\t\t\t\t\t'updatedAt' => \$row['updatedAt'] ?? null,\n\t\t\t\t\t\t\t'covering' => \$covering,\n\t\t\t\t\t\t\t'coveringPrimary' => \$covering ? \$owner : null,\n\t\t\t\t\t\t\t'coveringBadge' => \$covering ? null : null,",
			'label' => 'my_deal_tasks_drops_covering_badge',
			'filter' => 'DealChecklistUxContractTest::testTodayOverviewAndNotificationsSurface',
		],
		[
			'file' => $appRoot . '/lib/Service/MobilePushService.php',
			'from' => "Constants::NOTIFY_ACTIVITY_DUE,\n\t\tConstants::NOTIFY_ACTIVITY_ASSIGNED,\n\t\tConstants::NOTIFY_DEAL_CHECKLIST,\n\t\tConstants::NOTIFY_DEAL_ROTTING,\n\t];",
			'to' => "Constants::NOTIFY_ACTIVITY_DUE,\n\t\tConstants::NOTIFY_ACTIVITY_ASSIGNED,\n\t\tConstants::NOTIFY_DEAL_ROTTING,\n\t];",
			'label' => 'push_drops_deal_checklist_subject',
			'filter' => 'MobilePushServiceTest::testIsPushSubjectOnlyReminderDigestActivityDueAndDealChecklist|DealChecklistUxContractTest::testTodayOverviewAndNotificationsSurface',
		],
		[
			'file' => $appRoot . '/appinfo/routes.php',
			'from' => "['name' => 'mobile#doneChecklistItem', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/checklist-items/{id}/done', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\\d+', 'id' => '\\d+']],",
			'to' => "// mutated: companion checklist done removed\n",
			'label' => 'companion_drops_checklist_done_route',
			'filter' => 'MobileCompanionScopeContractTest::testMobileRoutesCoverRoadAuthoringNotTemplateManage|DealChecklistUxContractTest::testMobileRoutesExposeChecklistDone',
		],
		[
			'file' => $appRoot . '/js/utils/deal-checklist-ux.js',
			'from' => "export const DEFAULT_CHECKLIST_DONE_NOTE = 'Done';",
			'to' => "export const DEFAULT_CHECKLIST_DONE_NOTE = '';",
			'label' => 'drops_default_done_note',
			'filter' => 'BachusDealChecklistUxContractTest::testCompanyChecklistUsesUxModuleAndOneClickDone',
		],
		[
			'file' => $appRoot . '/templates/today.php',
			'from' => 'data-crm-complete-checklist',
			'to' => 'data-crm-x-complete-checklist',
			'label' => 'today_drops_checklist_done',
			'filter' => 'BachusDealChecklistUxContractTest::testTodayOneClickCompletesDealChecklist',
		],
		[
			'file' => $appRoot . '/js/utils/deal-checklist-ux.js',
			'from' => "export function requireTypedReplaceConfirm() {\n  return false;\n}",
			'to' => "export function requireTypedReplaceConfirm() {\n  return true;\n}",
			'label' => 'forces_typed_replace_confirm',
			'filter' => 'BachusDealChecklistUxContractTest::testCompanyChecklistUsesUxModuleAndOneClickDone',
		],
		[
			'file' => $appRoot . '/lib/Service/CompanyService.php',
			'from' => "if (\$this->db->tableExists('crm_deal_cl_item')) {\n\t\t\t\t\$qb = \$this->db->getQueryBuilder();\n\t\t\t\t\$checklistRestamped = \$qb->update('crm_deal_cl_item')\n\t\t\t\t\t->set('workspace_id', \$qb->createNamedParameter(\$toWorkspaceId, IQueryBuilder::PARAM_INT))\n\t\t\t\t\t->where(\$qb->expr()->eq('company_id', \$qb->createNamedParameter(\$id, IQueryBuilder::PARAM_INT)))\n\t\t\t\t\t->executeStatement();\n\t\t\t}",
			'to' => "// mutated: skip checklist workspace restamp on move\n\t\t\t\$checklistRestamped = 0;",
			'label' => 'move_skips_checklist_workspace_restamp',
			'filter' => 'DealChecklistAclSecurityContractTest::testMoveAndMergeRestampChecklistWorkspaceId|WorkspaceAclSurfaceContractTest::testMoveAndMergeRestampPersonaLedgers',
		],
		[
			'file' => $appRoot . '/lib/Controller/MobileController.php',
			'from' => "// Company workspace is authoritative — never trust denormalised item.workspace_id alone\n\t\t\t// (stale after move would let old-book writers Done a company that left).\n\t\t\t\$this->requireCompanyInWorkspace((int)\$item->getCompanyId(), \$wsId);",
			'to' => "// mutated: skip company gate on checklist done\n\t\t\tif ((int)\$item->getWorkspaceId() !== \$wsId) {\n\t\t\t\tthrow new NotFoundException('Checklist item not found', Constants::ERR_NOT_FOUND);\n\t\t\t}",
			'label' => 'mobile_done_falls_back_to_stale_workspace_id',
			'filter' => 'DealChecklistAclSecurityContractTest::testMobileDoneGatesOnCompanyWorkspaceNotStaleItemWorkspaceId',
		],
		[
			'file' => $appRoot . '/appinfo/routes.php',
			'from' => "['name' => 'mobile#createCompany', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/companies', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\\d+']],",
			'to' => "// mutated: createCompany removed\n",
			'label' => 'road_drops_create_company_route',
			'filter' => 'MobileCompanionScopeContractTest::testMobileRoutesCoverRoadAuthoringNotTemplateManage',
		],
		[
			'file' => $appRoot . '/lib/Controller/MobileController.php',
			'from' => "\$this->assertSafeMutationChannel();\n\t\t\t\$uid = \$this->requireGatedUid();\n\t\t\t\$wsId = \$this->requireMobileWorkspace(\$uid, \$workspaceId, 'write');\n\t\t\t\$body = \$this->jsonBody();\n\t\t\t\$body['workspaceId'] = \$wsId;\n\t\t\t// Folder binding goes through CompanyDocumentsService (actor ACL) — never mass-assign.\n\t\t\tunset(\$body['filesFolderId'], \$body['filesFolderPath']);",
			'to' => "// mutated: createCompany skips mutation channel\n\t\t\t\$uid = \$this->requireGatedUid();\n\t\t\t\$wsId = \$this->requireMobileWorkspace(\$uid, \$workspaceId, 'write');\n\t\t\t\$body = \$this->jsonBody();\n\t\t\t\$body['workspaceId'] = \$wsId;\n\t\t\t// Folder binding goes through CompanyDocumentsService (actor ACL) — never mass-assign.\n\t\t\tunset(\$body['filesFolderId'], \$body['filesFolderPath']);",
			'label' => 'create_company_skips_mutation_channel',
			'filter' => 'MobileCompanionScopeContractTest::testRoadMutationsGateCompanyAndMutationChannel',
		],
		[
			'file' => $appRoot . '/lib/Controller/MobileController.php',
			'from' => "unset(\$body['workspaceId'], \$body['filesFolderId'], \$body['filesFolderPath']);",
			'to' => "// mutated: allow filesFolder mass-assignment on mobile update\n\t\t\t",
			'label' => 'mobile_update_allows_files_folder_smuggle',
			'filter' => 'MobileCompanionScopeContractTest::testRoadMutationsGateCompanyAndMutationChannel|MobileGateHttpIntegrationTest::testMobileCompanyUpdateRejectsFilesFolderMassAssignment',
		],
		[
			'file' => $appRoot . '/lib/Controller/MobileController.php',
			'from' => "if (\$this->authorizationBasicAuthenticatesCurrentUser()) {\n\t\t\treturn;\n\t\t}",
			'to' => "\$auth = trim((string)\$this->request->getHeader('Authorization'));\n\t\tif (\$auth !== '' && preg_match('/^(Basic|Bearer)\\s+\\S+/i', \$auth) === 1) {\n\t\t\treturn;\n\t\t}",
			'label' => 'csrf_accepts_junk_authorization_presence',
			'filter' => 'MobileCompanionScopeContractTest::testRoadMutationsGateCompanyAndMutationChannel',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "\$current = \$this->items->countOpenByDeal(\$dealId);",
			'to' => "\$current = \$this->items->countByDeal(\$dealId);",
			'label' => 'soft_cap_counts_done_history',
			'filter' => 'DealChecklistServiceTest::testSoftCapIgnoresDoneHistory|DealChecklistUxContractTest::testReplaceUsesOpenOnlyDelete',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "if (!\$this->items->lockRow(\$openId)) {\n\t\t\t\t\t\tcontinue;\n\t\t\t\t\t}\n\t\t\t\t\t\$fresh = \$this->requireItem(\$openId);\n\t\t\t\t\tif (\$fresh->getStatus() === DealChecklistItem::STATUS_OPEN) {\n\t\t\t\t\t\t\$openIds[] = \$openId;\n\t\t\t\t\t}",
			'to' => "\$openIds[] = \$openId;",
			'label' => 'replace_skips_open_item_locks',
			'filter' => 'DealChecklistServiceTest::testApplyTemplateReplaceDeletesOpenOnlyNotDone|DealChecklistUxContractTest::testReplaceUsesOpenOnlyDelete',
		],
		[
			'file' => $appRoot . '/lib/Controller/MobileController.php',
			'from' => "if (!\$this->checklistPayloadLinksFileId(\$payload, \$fileId)) {\n\t\t\t\t\$this->documents->tryDeleteUploadedFile(\$uid, \$fileId);\n\t\t\t}",
			'to' => "// mutated: skip orphan upload cleanup\n\t\t\t",
			'label' => 'upload_skips_orphan_cleanup',
			'filter' => 'MobileCompanionScopeContractTest::testRoadMutationsGateCompanyAndMutationChannel',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "if (\$matched === null) {\n\t\t\t\t\tthrow \$e;\n\t\t\t\t}",
			'to' => "// mutated: soft-succeed link insert even without existing row\n\t\t\t\t",
			'label' => 'link_file_soft_succeeds_non_unique_insert',
			'filter' => 'DealChecklistServiceTest::testLinkFileRethrowsWhenInsertFailsWithoutExistingRow',
		],
		[
			'file' => $appRoot . '/lib/Controller/MobileController.php',
			'from' => "\$parts[] = hash('sha256', \$raw . \"\\0\" . \$intent);",
			'to' => "\$parts[] = hash('sha256', \$raw);",
			'label' => 'scoped_key_drops_intent_binding',
			'filter' => 'DealChecklistAclSecurityContractTest::testIdempotentReplayReGatesPathResourceAndScopesKeys',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "\$file = \$this->filesAccess->assertFileUnderFolder(\$actor, \$fileId, \$folderId);",
			'to' => "\$file = \$this->filesAccess->resolveReadableFile(\$actor, \$fileId);",
			'label' => 'link_file_skips_company_folder_tenancy',
			'filter' => 'DealChecklistAclSecurityContractTest::testLinkFileUsesActorScopedFilesAccessNeverRawSql|DealChecklistServiceTest::testLinkFileRequiresCompanyFolder',
		],
		[
			'file' => $appRoot . '/lib/Service/DealChecklistService.php',
			'from' => "if (\$item->getStatus() === DealChecklistItem::STATUS_DONE) {\n\t\t\t\tDbTransaction::commit(\$this->db, \$ownsTxn);\n\t\t\t\t\$idempotentDone = true;\n\t\t\t} else {",
			'to' => "if (false && \$item->getStatus() === DealChecklistItem::STATUS_DONE) {\n\t\t\t\tDbTransaction::commit(\$this->db, \$ownsTxn);\n\t\t\t\t\$idempotentDone = true;\n\t\t\t} else {",
			'label' => 'complete_already_done_not_idempotent',
			'filter' => 'DealChecklistServiceTest::testCompleteAlreadyDoneIsIdempotent',
		],
		[
			'file' => $appRoot . '/lib/Service/CompanyService.php',
			'from' => "if (\$this->notifications !== null) {\n\t\t\tforeach (\$checklistItemIds as \$checklistItemId) {\n\t\t\t\t\$this->notifications->withdraw('deal_checklist_item', (string)\$checklistItemId);\n\t\t\t}\n\t\t}",
			'to' => "// mutated: skip checklist notification withdraw on GDPR erase\n\t\t",
			'label' => 'gdpr_erase_skips_checklist_notification_withdraw',
			'filter' => 'DealChecklistAclSecurityContractTest::testGdprCompanyEraseWithdrawsChecklistNotificationsAfterCommit',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --testsuite unit --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);

	return (int)$code;
}

$failed = [];
foreach (deal_checklist_mutations($appRoot) as $case) {
	$original = file_get_contents($case['file']);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$case['file']}\n");
		exit(1);
	}
	if (!str_contains($original, $case['from'])) {
		fwrite(STDERR, "FIND miss [{$case['label']}] in {$case['file']}\n");
		exit(1);
	}
	$mutated = str_replace($case['from'], $case['to'], $original, $count);
	if ($count !== 1) {
		fwrite(STDERR, "Expected 1 replacement for [{$case['label']}], got {$count}\n");
		exit(1);
	}
	file_put_contents($case['file'], $mutated);
	echo "==> mutant: {$case['label']}\n";
	$code = run_phpunit($phpunit, $appRoot, $case['filter']);
	file_put_contents($case['file'], $original);
	if ($code === 0) {
		$failed[] = $case['label'];
		fwrite(STDERR, "SURVIVED: {$case['label']} (tests should have failed)\n");
	} else {
		echo "killed: {$case['label']}\n";
	}
}

if ($failed !== []) {
	fwrite(STDERR, 'Deal checklist mutations survived: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "Deal checklist mutations: all killed.\n";
exit(0);
