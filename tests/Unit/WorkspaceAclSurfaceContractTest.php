<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workspace ACL contracts for Import + Search + notifications (§3.3).
 */
final class WorkspaceAclSurfaceContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testImportApiRequiresWriteAndPassesWorkspaceId(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/ImportApiController.php');
		$this->assertStringContainsString("assertCapability(\$uid, \$wsId, 'write')", $src);
		$this->assertStringContainsString("\$payload['workspaceId'] = \$wsId", $src);
	}

	public function testImportMatcherAcceptsWorkspaceScope(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/ImportMatcher.php');
		$this->assertStringContainsString('function match(array $row, int $workspaceId)', $src);
		$this->assertStringContainsString('findByEmail($email, $ws)', $src);
		$this->assertStringContainsString('Fail closed — never match across books', $src);
	}

	public function testCompanySearchScopesToAccessibleWorkspaces(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Search/CompanySearchProvider.php');
		$this->assertStringContainsString('listAccessibleWorkspaceIds', $src);
		$this->assertStringContainsString("'workspaceIds' => \$workspaceIds", $src);
		$this->assertStringNotContainsString("search(['search' => \$term], \$limit, 0)", $src);
	}

	public function testNotificationServiceHasWorkspaceFanout(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/NotificationService.php');
		$this->assertStringContainsString('function notifyWorkspaceUsers', $src);
		$this->assertStringContainsString('listWorkspaceMemberUserIds', $src);
	}

	public function testFeedRenewalsScopesToDefaultWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/FeedController.php');
		$this->assertStringContainsString('findDefault()', $src);
		$this->assertStringContainsString("'workspaceId' => \$workspaceId", $src);
		$this->assertStringNotContainsString('agreements->search([], 2000)', $src);
	}

	public function testImportNavHiddenWithoutWrite(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		$this->assertStringContainsString('$canWriteNav', $nav);
		$this->assertStringContainsString('if ($canWriteNav):', $nav);
	}

	public function testPersonCreateAssertsBuyerUnderLock(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/PersonService.php');
		$this->assertStringContainsString('persistWithPrimaryInvariant($person, $wantsPrimary, $companyId, true)', $src);
		$persist = $this->extractMethod($src, 'persistWithPrimaryInvariant');
		$this->assertStringContainsString('assertSingleEconomicBuyer', $persist);
		$this->assertStringContainsString('lockRow($companyId)', $persist);
	}

	public function testGdprEraseClearsAccountOwner(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/CompanyService.php');
		$erase = $this->extractMethod($src, 'gdprErase');
		$this->assertStringContainsString('setAccountOwnerUserId(null)', $erase);
		$this->assertStringContainsString('setFilesFolderId(null)', $erase);
		$this->assertStringContainsString('setFilesFolderPath(null)', $erase);
	}

	public function testCompanyOwnedMutationsUseCompanyWorkspaceWrite(): void
	{
		$trait = (string)file_get_contents($this->root . '/lib/Controller/WorkspaceScopedTrait.php');
		$this->assertStringContainsString('function requireCompanyCapability', $trait);
		$this->assertStringContainsString("assertLoadedCompanyInWorkspace(\$company, \$capability)", $trait);

		foreach (['PersonApiController', 'DealApiController', 'AgreementApiController', 'ActivityApiController', 'HoursApiController'] as $ctrl) {
			$src = (string)file_get_contents($this->root . '/lib/Controller/' . $ctrl . '.php');
			$this->assertStringContainsString('requireCompanyCapability', $src, $ctrl . ' must gate writes on company workspace');
			// Create/update must not pair company-read with last-used write.
			$this->assertStringNotContainsString(
				"requireCompanyInResolvedWorkspace(\$id);\n\t\t\t\$ctx = \$this->requireWorkspaceCapability('write')",
				$src,
				$ctrl . ' still uses last-used write after company read',
			);
		}
	}

	public function testPersonFindByEmailRequiresWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/PersonMapper.php');
		$this->assertStringContainsString('function findByEmail(string $email, int $workspaceId)', $src);
		$this->assertStringContainsString('$workspaceId <= 0', $src);
	}

	public function testApiJsPinsWorkspaceFromUrl(): void
	{
		$src = (string)file_get_contents($this->root . '/js/common/api.js');
		$this->assertStringContainsString('function withWorkspace', $src);
		$this->assertStringContainsString('fetch(BASE + withWorkspace(path)', $src);
	}

	public function testInvoicePaidNeverFansOutInstanceWide(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/InvoicePaidSyncService.php');
		$this->assertStringContainsString('notifyWorkspaceUsers', $src);
		$this->assertStringNotContainsString('notifyCrmUsers', $src);
	}

	public function testLeadAndOrderIntakeScopesEmailMatch(): void
	{
		$lead = (string)file_get_contents($this->root . '/lib/Service/LeadWebhookService.php');
		$order = (string)file_get_contents($this->root . '/lib/Service/WebsiteOrderWebhookService.php');
		$this->assertStringContainsString('findByEmail($email, $workspaceId)', $lead);
		$this->assertStringContainsString('findByEmail($email, $workspaceId)', $order);
		// Fail closed when workspace is unknown — never scan all books.
		$this->assertMatchesRegularExpression(
			'/\$workspaceId > 0\s*\n\s*\? \$this->personMapper->findByEmail\(\$email, \$workspaceId\)\s*\n\s*: \[\];/',
			$lead,
		);
		$this->assertStringNotContainsString('findByEmail($email, 0)', $lead);
		$this->assertStringNotContainsString('notifyCrmUsers', $lead);
		$this->assertStringNotContainsString('notifyCrmUsers', $order);
	}

	public function testActivityDueFailsClosedWithoutWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/ActivityDueReminderService.php');
		$notify = $this->extractMethod($src, 'notifyOne');
		$this->assertStringContainsString('notifyWorkspaceUsers', $notify);
		$this->assertStringNotContainsString('notifyCrmUsers', $notify);
	}

	public function testAgreementFormsScopeCompaniesAndAcl(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$new = $this->extractMethod($src, 'agreementNew');
		$edit = $this->extractMethod($src, 'agreementEdit');
		$this->assertStringContainsString("assertCapability(\$uid, \$wsId, 'write')", $new);
		$this->assertStringContainsString("search(['workspaceId' => \$wsId]", $new);
		$this->assertStringContainsString('requireCompanyPage', $edit);
		$this->assertStringContainsString("assertUserCanAccessCompany(\$uid, \$wsId, 'write')", $edit);
		$this->assertStringNotContainsString('search([], 500, 0)', $new);
		$this->assertStringNotContainsString('search([], 500, 0)', $edit);
	}

	public function testBriefAndEscalationScopeForeignCompanyIds(): void
	{
		$brief = (string)file_get_contents($this->root . '/lib/Service/BriefService.php');
		$esc = (string)file_get_contents($this->root . '/lib/Service/EscalationService.php');
		$this->assertStringContainsString('assertCompanyInWorkspace', $brief);
		$this->assertStringContainsString('crm_brief_wip/', $brief);
		$confirm = $this->extractMethod($brief, 'confirm');
		$this->assertStringContainsString('if ($companyId > 0)', $confirm);
		$this->assertStringContainsString('$this->assertCompanyInWorkspace($workspaceId, $companyId)', $confirm);
		$this->assertStringContainsString('assertCompanyInWorkspace', $esc);
		$this->assertStringContainsString('assertDealInWorkspace', $esc);
		$this->assertStringContainsString('crm_escal_open/', $esc);
	}

	public function testDealUpdateUsesCompanyWorkspaceCapability(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/DealApiController.php');
		$update = $this->extractMethod($src, 'update');
		$this->assertStringContainsString("requireCompanyCapability((int)\$deal->getCompanyId(), 'write')", $update);
		$this->assertStringNotContainsString("requireWorkspaceCapability('write')", $update);
		$this->assertStringContainsString('updatedAtPrecondition', $update);
	}

	public function testPersonaCompanyWritesFailClosedOnNullWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString("assertUserCanAccessCompany(\$uid, \$wsId, 'write')", $src);
		$this->assertStringNotContainsString("assertCapability(\$uid, \$wsId, 'write')", $src);
	}

	public function testViewerMutationCtasHiddenWithoutWrite(): void
	{
		$companies = (string)file_get_contents($this->root . '/templates/companies.php');
		$agreements = (string)file_get_contents($this->root . '/templates/agreements.php');
		$detail = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$pipeline = (string)file_get_contents($this->root . '/templates/pipeline.php');
		$activities = (string)file_get_contents($this->root . '/templates/activities.php');
		$money = (string)file_get_contents($this->root . '/templates/money.php');
		$this->assertStringContainsString("if (!empty(\$_['canWrite']))", $companies);
		$this->assertStringContainsString("if (!empty(\$_['canWrite']))", $agreements);
		$this->assertStringContainsString("if (!empty(\$_['canWrite']))", $detail);
		$this->assertStringContainsString("if (!empty(\$_['canDelete']))", $detail);
		$this->assertStringContainsString("if (!empty(\$_['canMove']))", $detail);
		$this->assertStringContainsString('data-crm-buying-role', $detail);
		$this->assertStringContainsString("if (!empty(\$_['canWrite'])):", $detail);
		$this->assertStringContainsString('$canWrite = !empty($_[\'canWrite\'])', $pipeline);
		$this->assertStringContainsString('crm-pipeline-new-deal', $pipeline);
		$this->assertStringContainsString('if ($canWrite):', $pipeline);
		$this->assertStringContainsString("draggable=\"<?php p((\$canWrite && !in_array(\$stage, ['won', 'lost'], true)) ? 'true' : 'false'); ?>\"", $pipeline);
		$this->assertStringContainsString('$canWrite = !empty($_[\'canWrite\'])', $activities);
		$this->assertStringContainsString('if ($canWrite):', $activities);
		$this->assertStringContainsString('$canWrite = !empty($_[\'canWrite\'])', $money);
		$this->assertStringContainsString('$canWrite && !empty($row[\'agreementId\'])', $money);
		$this->assertStringContainsString('$canWrite = !empty($_[\'canWrite\'])', $agreements);
		$this->assertStringContainsString('if ($canWrite):', $agreements);
		$this->assertStringContainsString('data-crm-partner-session', $agreements);
		$emails = (string)file_get_contents($this->root . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString("if (!empty(\$_['canWrite']))", $emails);
		$this->assertStringContainsString('Communication above', $emails);
		$detail = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-log-email"', $detail);
		$this->assertStringContainsString(
			"<?php if (!empty(\$_['canWrite'])): ?>\n\t\t\t\t<button type=\"button\" class=\"crm-btn crm-btn--primary crm-btn--sm\" id=\"crm-log-email\"",
			$detail,
		);
		$companyJs = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString("shellCan('write')", $companyJs);
		$pipelineJs = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$this->assertStringContainsString("shellCan('write')", $pipelineJs);
		$shellCan = (string)file_get_contents($this->root . '/js/common/shell-can.js');
		$this->assertStringContainsString('CustomerCheckShellCan', $shellCan);
		$assets = (string)file_get_contents($this->root . '/lib/Service/FrontEndAssetService.php');
		$this->assertStringContainsString("'common/shell-can'", $assets);
		$agreementApi = (string)file_get_contents($this->root . '/lib/Controller/AgreementApiController.php');
		$this->assertStringContainsString("gateAgreement(\$id, 'write')", $agreementApi);
		$this->assertStringNotContainsString("gateAgreement(\$id, 'read');\n\t\t\t\$b = \$this->body();\n\n\t\t\treturn \$this->ok(\$this->delivery->build", $agreementApi);
		$emailApi = (string)file_get_contents($this->root . '/lib/Controller/EmailApiController.php');
		$compose = $this->extractMethod($emailApi, 'composeTemplate');
		$this->assertStringContainsString("assertLoadedCompanyInWorkspace(\$company, 'write')", $compose);
	}

	public function testMoveAndMergeRestampPersonaLedgers(): void
	{
		$move = $this->extractMethod(
			(string)file_get_contents($this->root . '/lib/Service/CompanyService.php'),
			'moveToWorkspace',
		);
		$this->assertStringContainsString('lockRow($id)', $move);
		$this->assertStringContainsString("update('crm_cust_prm')", $move);
		$this->assertStringContainsString("update('crm_acct_plan')", $move);
		$this->assertStringContainsString("set('workspace_id'", $move);
		$merge = (string)file_get_contents($this->root . '/lib/Service/CompanyMergeService.php');
		$this->assertStringContainsString('reassignPersonaLedgers', $merge);
		$this->assertStringContainsString('reassignSharedMail', $merge);
		$this->assertStringContainsString('$this->reassignPersonaLedgers($sourceId, $intoCompanyId, $keeperWs)', $merge);
		$this->assertStringContainsString('$this->reassignSharedMail($sourceId, $intoCompanyId)', $merge);
		$reassign = $this->extractMethod($merge, 'reassignPersonaLedgers');
		$this->assertStringContainsString("update('crm_cust_prm')", $reassign);
		$this->assertStringContainsString("update('crm_acct_plan')", $reassign);
		$this->assertStringContainsString("set('workspace_id'", $reassign);
		$mail = $this->extractMethod($merge, 'reassignSharedMail');
		$this->assertStringContainsString("update('crm_mail_msg')", $mail);
		$this->assertStringContainsString("update('crm_mail_dft')", $mail);
	}

	public function testBriefVerifyUsesStatusCas(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/BriefService.php');
		$verify = $this->extractMethod($src, 'verify');
		$this->assertStringContainsString("in_array(\$status, ['committed', 'carried', 'missed'], true)", $verify);
		$this->assertStringContainsString("eq('status', \$qb->createNamedParameter(\$status))", $verify);
		$this->assertStringContainsString('ConflictException', $verify);
	}

	public function testTriageAssignRequiresOpenStatus(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/MailboxService.php');
		$triage = $this->extractMethod($src, 'triageAssign');
		$this->assertStringContainsString("eq('triage_status', \$qb->createNamedParameter('open'))", $triage);
	}

	public function testPromiseCompleteBindsCompanyId(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/PromiseService.php');
		$complete = $this->extractMethod($src, 'complete');
		$this->assertStringContainsString('int $companyId)', $complete);
		$this->assertStringNotContainsString('int $companyId = 0', $complete);
		$this->assertStringContainsString('if ($companyId <= 0)', $complete);
		$this->assertStringContainsString("eq('company_id'", $complete);
		$cancel = $this->extractMethod($src, 'cancel');
		$this->assertStringContainsString('if ($companyId <= 0)', $cancel);
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('complete($id, $wsId, $evidence, $uid, $companyId)', $ctrl);
		$this->assertStringContainsString('cancel($id, $wsId, $uid, $companyId)', $ctrl);
	}

	public function testActivityAndDealFieldUpdatesLockRow(): void
	{
		$activity = $this->extractMethod(
			(string)file_get_contents($this->root . '/lib/Service/ActivityService.php'),
			'update',
		);
		$this->assertStringContainsString('lockRow($id)', $activity);
		$this->assertStringContainsString('beginTransaction', $activity);
		$deal = $this->extractMethod(
			(string)file_get_contents($this->root . '/lib/Service/DealService.php'),
			'update',
		);
		$this->assertStringContainsString('lockRow($id)', $deal);
		$this->assertTrue(
			str_contains($deal, 'DbTransaction::begin') || str_contains($deal, 'beginTransaction'),
			'deal update must open a DB transaction',
		);
		$actMapper = (string)file_get_contents($this->root . '/lib/Db/ActivityMapper.php');
		$this->assertStringContainsString('function lockRow', $actMapper);
		$dealMapper = (string)file_get_contents($this->root . '/lib/Db/DealMapper.php');
		$this->assertStringContainsString('function lockRow', $dealMapper);
	}

	public function testOrderWebhookTitleIdempotencyScopedToDefaultWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/WebsiteOrderWebhookService.php');
		$this->assertStringContainsString('findOpenByTitle($dealTitle, $orderWorkspaceId)', $src);
		$mapper = (string)file_get_contents($this->root . '/lib/Db/DealMapper.php');
		$this->assertStringContainsString('function findOpenByTitle(string $title, int $workspaceId)', $mapper);
		$this->assertStringContainsString('if ($workspaceId <= 0)', $mapper);
		$this->assertStringContainsString('function updateStageCas', $mapper);
		$this->assertStringNotContainsString('function sumOpenValueByStage', $mapper);
	}

	public function testActivityDoneUsesCas(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/ActivityService.php');
		$setDone = $this->extractMethod($src, 'setDone');
		$this->assertStringContainsString('updateDoneCas', $setDone);
		$this->assertStringContainsString('ConflictException', $setDone);
		$mapper = (string)file_get_contents($this->root . '/lib/Db/ActivityMapper.php');
		$this->assertStringContainsString('function updateDoneCas', $mapper);
	}

	public function testDealStageChangeUsesCas(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/DealService.php');
		$change = $this->extractMethod($src, 'changeStage');
		$this->assertStringContainsString('updateStageCas', $change);
		$this->assertStringContainsString('ConflictException', $change);
	}

	public function testShellExposesFineGrainedCapabilities(): void
	{
		$shell = (string)file_get_contents($this->root . '/lib/Controller/PageShellTrait.php');
		$this->assertStringContainsString("'canBriefConfirm'", $shell);
		$this->assertStringContainsString("'canTeamCoach'", $shell);
		$this->assertStringContainsString("'canBulk'", $shell);
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		$this->assertStringContainsString('data-crm-can-brief', $start);
		$this->assertStringContainsString(
			"data-crm-can-brief=\"<?php p(!empty(\$_['canBriefConfirm']) ? '1' : '0'); ?>\"",
			$start,
		);
		$this->assertStringContainsString('data-crm-can-coach', $start);
		$this->assertStringContainsString('data-crm-can-bulk', $start);
	}

	public function testChaseAndRenewalsScopeWorkspaceAtQuery(): void
	{
		$chase = (string)file_get_contents($this->root . '/lib/Service/ChaseService.php');
		$money = (string)file_get_contents($this->root . '/lib/Service/AgreementRenewalMoney.php');
		$this->assertStringContainsString("'workspaceId' => \$workspaceId", $chase);
		$this->assertStringContainsString('findRenewalCandidates(2000, 0, $workspaceId)', $money);
	}

	public function testAgreementUpdateLocksAgainstCancelRace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/AgreementService.php');
		$update = $this->extractMethod($src, 'update');
		$this->assertTrue(
			str_contains($update, 'DbTransaction::begin') || str_contains($update, 'beginTransaction'),
			'agreement update must open a DB transaction',
		);
		$this->assertStringContainsString('lockRow($id)', $update);
		$this->assertStringContainsString('AGREEMENT_TERMINAL_STATUSES', $update);
	}

	public function testInvoicePaidRefRequiresCompanyPin(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/InvoicePaidSyncService.php');
		$this->assertStringContainsString('scopeInvoiceRefMatches', $src);
		$this->assertStringContainsString('findByPcCustomerId', $src);
		$this->assertStringContainsString('never trust a bare invoice_ref', $src);
	}

	public function testPushToTasksClaimsTodoUriCas(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/ActivityService.php');
		$push = $this->extractMethod($src, 'pushToTasks');
		$this->assertStringContainsString('claimTodoUri', $push);
		$mapper = (string)file_get_contents($this->root . '/lib/Db/ActivityMapper.php');
		$this->assertStringContainsString('function claimTodoUri', $mapper);
	}

	public function testPartnerQuotaOverviewRequiresWorkspace(): void
	{
		$quota = (string)file_get_contents($this->root . '/lib/Service/QuotaService.php');
		$mapper = (string)file_get_contents($this->root . '/lib/Db/AgreementMapper.php');
		$overview = $this->extractMethod($quota, 'partnerQuotaOverview');
		$find = $this->extractMethod($mapper, 'findPartnerAgreements');
		$this->assertStringContainsString('function partnerQuotaOverview(int $workspaceId', $overview);
		$this->assertStringContainsString('$workspaceId <= 0', $overview);
		$this->assertStringContainsString('function findPartnerAgreements(int $workspaceId', $find);
		$this->assertStringContainsString("if (\$workspaceId <= 0) {\n\t\t\treturn [];\n\t\t}", $find);
		$this->assertStringContainsString('restrictToWorkspace($qb, $workspaceId)', $find);
		$this->assertStringContainsString('excludeArchivedCompanies($qb)', $find);
	}

	public function testFindAllActiveRequiresWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/CompanyMapper.php');
		$fn = $this->extractMethod($src, 'findAllActive');
		$this->assertStringContainsString('function findAllActive(int $workspaceId', $fn);
		$this->assertStringContainsString('$workspaceId <= 0', $fn);
		$this->assertStringContainsString("eq('workspace_id'", $fn);
	}

	public function testHoursUpdateEntryLocksRow(): void
	{
		$quota = (string)file_get_contents($this->root . '/lib/Service/QuotaService.php');
		$mapper = (string)file_get_contents($this->root . '/lib/Db/HoursMapper.php');
		$update = $this->extractMethod($quota, 'updateEntry');
		$this->assertStringContainsString('beginTransaction', $update);
		$this->assertStringContainsString('lockRow($entryId)', $update);
		$this->assertStringContainsString('function lockRow', $mapper);
	}

	public function testPersonDeleteLocksCompany(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/PersonService.php');
		$delete = $this->extractMethod($src, 'delete');
		$this->assertStringContainsString('beginTransaction', $delete);
		$this->assertStringContainsString('lockRow($companyId)', $delete);
		$this->assertStringContainsString('assertFresh($person->getUpdatedAt()', $delete);
	}

	public function testAccountPlanUpsertSerialisesCreates(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/AccountPlanService.php');
		$upsert = $this->extractMethod($src, 'upsert');
		$this->assertStringContainsString('beginTransaction', $upsert);
		$this->assertStringContainsString('lockRow($companyId)', $upsert);
		$this->assertStringContainsString('REASON_UNIQUE_CONSTRAINT_VIOLATION', $upsert);
		$this->assertStringContainsString('OptimisticLock::assertFresh', $upsert);
	}

	public function testMoneyDisplayScopesInvoiceRefMatches(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/MoneyService.php');
		$this->assertStringContainsString('function matchScopedInvoiceRef', $src);
		$this->assertStringContainsString('matchScopedInvoiceRef(', $src);
		$this->assertStringNotContainsString('$matchedAgreementId = (int)$matches[0]->getId()', $src);
	}

	public function testMemberImportEndpointsRequireMembersCapabilityAndLock(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$group = $this->extractMethod($ctrl, 'importWorkspaceMembersFromGroup');
		$circle = $this->extractMethod($ctrl, 'importWorkspaceMembersFromCircle');
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'members')", $group);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'members')", $circle);
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		$this->assertStringContainsString('members/import-group', $routes);
		$this->assertStringContainsString('members/import-circle', $routes);
		$add = $this->extractMethod(
			(string)file_get_contents($this->root . '/lib/Service/WorkspaceService.php'),
			'addMember',
		);
		$this->assertStringContainsString('beginTransaction', $add);
		$this->assertStringContainsString('lockRow($workspaceId)', $add);
		$mapper = (string)file_get_contents($this->root . '/lib/Db/WorkspaceMapper.php');
		$this->assertStringContainsString('function lockRow(int $id): bool', $mapper);
		$this->assertStringContainsString('->forUpdate()', $mapper);
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
