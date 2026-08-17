<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\AppSettingsSectionCatalog;
use OCA\CustomerCheck\Service\CommandBoardService;
use OCA\CustomerCheck\Service\CompanyCommandService;
use OCA\CustomerCheck\Service\CompanyHealthService;
use OCA\CustomerCheck\Service\ContactsSuggestService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CirclesDirectoryService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Service\MailRelatedService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Service\WorkspaceSettingsSectionCatalog;
use OCA\CustomerCheck\Support\DensityCounts;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller
{
	use PageShellTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly CompanyService $companies,
		private readonly AgreementService $agreements,
		private readonly AgreementStatusService $agreementStatus,
		private readonly DealService $deals,
		private readonly ActivityService $activities,
		private readonly PersonService $persons,
		private readonly TimelineService $timeline,
		private readonly MailRelatedService $mailRelated,
		private readonly TodayService $today,
		private readonly CommandBoardService $commandBoard,
		private readonly CompanyCommandService $companyCommand,
		private readonly ContactsSuggestService $contactsSuggest,
		private readonly CompanyHealthService $companyHealth,
		private readonly MoneyService $money,
		private readonly SettingsService $settings,
		private readonly DirectoryOptionsService $directory,
		private readonly CirclesDirectoryService $circlesDirectory,
		private readonly QuotaService $quota,
		private readonly FacadeProbeService $facades,
		private readonly IdentityLinkHealthService $identityHealth,
		private readonly DisplayLabelService $labels,
		private readonly LicenseService $license,
		private readonly ProductCodeRegistry $productCodes,
		private readonly WorkspaceAccessService $workspaceAccess,
		private readonly WorkspaceService $workspaceService,
		private readonly WorkspaceSettingsSectionCatalog $workspaceSettingsSections,
		private readonly AppSettingsSectionCatalog $appSettingsSections,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly IFactory $l10nFactory,
		private readonly ITimeFactory $timeFactory,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return $this->today();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function today(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/today', null, [], ['common/deal-checklist-ux']);
		$uid = $this->accessControl->currentUserIdOrEmpty();
		$wsId = 0;
		$role = 'member';
		$isAdmin = false;
		if ($uid !== '') {
			try {
				$ctx = $this->workspaceAccess->resolveForUser($uid);
				$wsId = (int)$ctx['workspace']->getId();
				$role = $ctx['role'];
				$isAdmin = $ctx['isAppAdmin'];
			} catch (\Throwable) {
			}
		}
		$queue = $this->today->build(null, $wsId > 0 ? $wsId : null);
		$board = $this->commandBoard->build(null, $uid !== '' ? $uid : null, $wsId > 0 ? $wsId : null, $role, $isAdmin);
		$companyFilters = ['archived' => false];
		if ($wsId > 0) {
			$companyFilters['workspaceId'] = $wsId;
		}
		return new TemplateResponse('customercheck', 'today', $this->mergeShellPageParams(
			'today',
			$this->l10n->t('Today'),
			$this->l10n->t('What needs attention right now.'),
			[
				'queue' => $queue,
				'commandBoard' => $board,
				'companyCount' => $this->companies->count($companyFilters),
				'workspaceId' => $wsId,
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pulse(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now']);
		return new TemplateResponse('customercheck', 'pulse', $this->mergeShellPageParams(
			'pulse',
			$this->l10n->t('Overview'),
			$this->l10n->t('Companies, money, renewals and missing links — what needs attention.'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function brief(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now']);
		return new TemplateResponse('customercheck', 'brief', $this->mergeShellPageParams(
			'brief',
			$this->l10n->t('This week'),
			$this->l10n->t('Commit and verify this week’s owned promises.'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myAccounts(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now']);
		return new TemplateResponse('customercheck', 'my-accounts', $this->mergeShellPageParams(
			'my-accounts',
			$this->l10n->t('My customers'),
			$this->l10n->t('Companies you own or plan.'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function team(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now']);
		return new TemplateResponse('customercheck', 'team', $this->mergeShellPageParams(
			'team',
			$this->l10n->t('Team'),
			$this->l10n->t('See who needs help. Cover absences. Move work safely.'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workload(): RedirectResponse
	{
		$params = [];
		$ws = $this->request->getParam('workspaceId');
		if ($ws !== null && $ws !== '') {
			$params['workspaceId'] = $ws;
		}
		$url = $this->urlGenerator->linkToRoute('customercheck.page.team', $params);
		return new RedirectResponse($url . '#capacity');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function strategy(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now']);
		return new TemplateResponse('customercheck', 'strategy', $this->mergeShellPageParams(
			'strategy',
			$this->l10n->t('Plans'),
			$this->l10n->t('Goals and health for every customer with a plan.'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mailTriage(): RedirectResponse
	{
		return new RedirectResponse(
			$this->inboxAssignUrl('mail'),
			Http::STATUS_MOVED_PERMANENTLY,
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function salesInbox(): TemplateResponse
	{
		$this->registerFrontEndAssets(
			'pages/sales-inbox',
			null,
			[],
			['common/mail-sync-now', 'common/folder-picker', 'common/rich-mail-composer', 'common/shared-mail-send', 'common/user-picker-nav', 'common/inbox-assign-map', 'common/inbox-page-core'],
		);

		return new TemplateResponse('customercheck', 'sales-inbox', $this->mergeShellPageParams(
			'sales-inbox',
			$this->l10n->t('Inbox'),
			$this->l10n->t('Your mail, the team inbox, and items that still need a company — one place.'),
		));
	}

	/**
	 * Legacy Assign URL — permanently merged into Inbox → Unassigned.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function review(): RedirectResponse
	{
		$kind = trim((string)$this->request->getParam('kind', ''));
		if ($kind === 'shared_mail' || $kind === 'personal_mail_shared_unmatched') {
			$kind = 'mail';
		}

		return new RedirectResponse(
			$this->inboxAssignUrl($kind !== '' ? $kind : 'mail'),
			Http::STATUS_MOVED_PERMANENTLY,
		);
	}

	/**
	 * Absolute Inbox URL with Unassigned tab (+ optional Assign source kind).
	 */
	private function inboxAssignUrl(string $kind = 'mail'): string
	{
		$params = [];
		$ws = (int)$this->request->getParam('workspaceId', 0);
		if ($ws > 0) {
			$params['workspaceId'] = $ws;
		}
		$url = $this->urlGenerator->linkToRouteAbsolute('customercheck.page.salesInbox', $params);
		$sep = str_contains($url, '?') ? '&' : '?';
		$url .= $sep . 'tab=unmatched';
		$kind = trim($kind);
		if ($kind !== '') {
			$url .= '&kind=' . rawurlencode($kind);
		}

		return $url;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function conflicts(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now']);

		return new TemplateResponse('customercheck', 'conflicts', $this->mergeShellPageParams(
			'conflicts',
			$this->l10n->t('Conflicts'),
			$this->l10n->t('Parallel edits are detected with a three-way compare — not last-write-wins. Every resolution needs a reason.'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function prepareMeeting(int $id): TemplateResponse
	{
		$this->requireCompanyPage($id);
		$this->registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now']);
		return new TemplateResponse('customercheck', 'prepare-meeting', $this->mergeShellPageParams(
			'prepare-meeting',
			$this->l10n->t('Prepare meeting'),
			$this->l10n->t('One stack for the next call or QBR.'),
			['companyId' => $id],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companies(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/companies');
		$wsId = $this->resolvedWorkspaceId();
		$search = \OCA\CustomerCheck\Support\CompanyListSearch::normalize(
			(string)$this->request->getParam('q', '')
		);
		$status = trim((string)$this->request->getParam('status', ''));
		$archived = $this->request->getParam('archived', '') === '1';
		$filters = ['archived' => $archived, 'workspaceId' => $wsId];
		if ($search !== '') {
			$filters['search'] = $search;
			$filters['statusSearchCodes'] = \OCA\CustomerCheck\Support\CompanyListSearch::matchingStatusCodes(
				$search,
				fn (string $code): string => $this->labels->companyStatus($code),
			);
			$ownerRoster = [];
			try {
				foreach ($this->workspaceService->listMembers($wsId) as $member) {
					$uid = trim((string)$member->getUserId());
					if ($uid !== '') {
						$ownerRoster[] = ['id' => $uid];
					}
				}
			} catch (\Throwable) {
				$ownerRoster = [];
			}
			if ($ownerRoster !== []) {
				$resolved = $this->directory->resolveUsers(array_column($ownerRoster, 'id'));
				$filters['ownerUserIds'] = \OCA\CustomerCheck\Support\CompanyListSearch::matchingOwnerIds(
					$search,
					$resolved
				);
			}
			if (\OCA\CustomerCheck\Support\CompanyListSearch::wantsOpenCallbacks($search, $this->l10n)) {
				$filters['matchOpenCallbacks'] = true;
			}
		}
		if ($status !== '') {
			$filters['status'] = $status;
		}
		$list = $this->companies->search($filters, 200, 0);
		$health = $this->companyHealth->enrich($list);
		$duplicateNameKeys = [];
		if (!$archived && $wsId > 0) {
			$duplicateNameKeys = $this->companies->activeDuplicateNameKeys($wsId);
		}
		$ownerUids = [];
		foreach ($list as $company) {
			$oid = trim((string)($company->getAccountOwnerUserId() ?? ''));
			if ($oid !== '') {
				$ownerUids[] = $oid;
			}
		}
		$ownerDisplayNames = [];
		foreach ($this->directory->resolveUsers(array_values(array_unique($ownerUids))) as $u) {
			$ownerDisplayNames[$u['id']] = (string)($u['displayName'] ?? $u['id']);
		}
		return new TemplateResponse('customercheck', 'companies', $this->mergeShellPageParams(
			'companies',
			$this->l10n->t('Companies'),
			$this->l10n->t('Your customer registry.'),
			[
				'companies' => $list,
				'health' => $health,
				'duplicateNameKeys' => $duplicateNameKeys,
				'ownerDisplayNames' => $ownerDisplayNames,
				'filterQ' => $search,
				'filterStatus' => $status,
				'filterArchived' => $archived,
				'workspaceId' => $wsId,
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyNew(): TemplateResponse
	{
		$uid = $this->accessControl->requireCanUseApp();
		$wsId = $this->resolvedWorkspaceId();
		$this->workspaceAccess->assertCapability($uid, $wsId, 'write');
		$this->registerFrontEndAssets('pages/company-form');
		return new TemplateResponse('customercheck', 'company-form', $this->mergeShellPageParams(
			'company-form',
			$this->l10n->t('New company'),
			$this->l10n->t('Create a customer record.'),
			array_merge(
				['company' => null, 'mode' => 'create'],
				$this->siblingLinkOptions(null, null),
				$this->workspaceMemberOptions(),
			),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyEdit(int $id): TemplateResponse
	{
		$uid = $this->accessControl->requireCanUseApp();
		$this->registerFrontEndAssets('pages/company-form');
		$company = $this->requireCompanyPage($id);
		$wsId = (int)($company->getWorkspaceId() ?? 0);
		$this->workspaceAccess->assertUserCanAccessCompany($uid, $wsId, 'write');
		return new TemplateResponse('customercheck', 'company-form', $this->mergeShellPageParams(
			'company-form',
			$this->l10n->t('Edit company'),
			(string)$company->getName(),
			array_merge(
				['company' => $company, 'mode' => 'edit'],
				$this->siblingLinkOptions($company->getPcCustomerId(), $company->getTcCustomerId()),
				$this->workspaceMemberOptions(),
			),
		));
	}

	/**
	 * Workspace members for account-owner pickers (user id + display label).
	 *
	 * @return array{workspaceMemberOptions: list<array{id:string,label:string}>, currentUserId: string}
	 */
	private function workspaceMemberOptions(): array
	{
		$uid = $this->accessControl->currentUserIdOrEmpty();
		$options = [];
		$wsId = $this->resolvedWorkspaceId();
		if ($wsId > 0) {
			foreach ($this->workspaceService->listMembers($wsId) as $m) {
				$userId = trim((string)$m->getUserId());
				if ($userId === '') {
					continue;
				}
				$options[] = [
					'id' => $userId,
					'label' => $userId,
				];
			}
		}
		if ($uid !== '' && !in_array($uid, array_column($options, 'id'), true)) {
			array_unshift($options, ['id' => $uid, 'label' => $uid]);
		}
		$resolved = [];
		foreach ($this->directory->resolveUsers(array_column($options, 'id')) as $u) {
			$resolved[$u['id']] = (string)($u['displayName'] ?? $u['id']);
		}
		foreach ($options as &$opt) {
			$opt['label'] = $resolved[$opt['id']] ?? $opt['id'];
		}
		unset($opt);
		usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return [
			'workspaceMemberOptions' => $options,
			'currentUserId' => $uid,
		];
	}

	/**
	 * Named sibling-customer pickers — never ask users to type primary keys.
	 *
	 * @return array{
	 *   pcCustomerOptions: list<array{id:int,label:string}>,
	 *   tcCustomerOptions: list<array{id:int,label:string}>,
	 *   pcCustomersAvailable: bool,
	 *   tcCustomersAvailable: bool,
	 *   pcWriteAvailable: bool,
	 *   tcWriteAvailable: bool
	 * }
	 */
	private function siblingLinkOptions(?int $pcLinked, ?int $tcLinked): array
	{
		$pcAvailable = $this->facades->projectcheckAvailable();
		$tcAvailable = $this->facades->ticketcheckAvailable();
		$pcOptions = $pcAvailable
			? $this->labels->siblingCustomerOptions($this->facades->listProjectCheckCustomers(2000))
			: [];
		$tcOptions = $tcAvailable
			? $this->labels->siblingCustomerOptions($this->facades->listTicketCheckCustomers(2000))
			: [];
		return [
			'pcCustomerOptions' => $this->labels->ensureLinkedSiblingOption($pcOptions, $pcLinked),
			'tcCustomerOptions' => $this->labels->ensureLinkedSiblingOption($tcOptions, $tcLinked),
			'pcCustomersAvailable' => $pcAvailable,
			'tcCustomersAvailable' => $tcAvailable,
			'pcWriteAvailable' => $this->facades->projectcheckWriteAvailable(),
			'tcWriteAvailable' => $this->facades->ticketcheckWriteAvailable(),
		];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyDetail(int $id): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/company-detail', null, ['common/identity-links'], ['common/company360-focus', 'common/folder-picker', 'common/glance-summary', 'common/company360-present', 'common/deal-checklist-ux', 'common/mail-sync-now', 'common/rich-mail-composer', 'common/shared-mail-send', 'pages/persona']);
		$company = $this->requireCompanyPage($id);
		$now = $this->timeFactory->getDateTime();
		$agreements = [];
		foreach ($this->agreements->listForCompany($id) as $a) {
			$chip = $this->agreementStatus->expiryBadge($a, $now);
			$row = [
				'id' => (int)$a->getId(),
				'label' => (string)$a->getLabel(),
				'kind' => (string)$a->getKind(),
				'productCode' => (string)$a->getProductCode(),
				'status' => (string)$a->getStatus(),
				'invoiceStatus' => (string)$a->getInvoiceStatus(),
				'invoiceRef' => $a->getInvoiceRef(),
				'amountCents' => (int)$a->getAmountCents(),
				'validUntil' => $a->getValidUntil()?->format('Y-m-d'),
				'salesLogId' => $a->getSalesLogId(),
				'keyFingerprint' => $a->getKeyFingerprint(),
				'updatedAt' => $a->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
				'chip' => $chip,
			];
			if ((string)$a->getKind() === 'partner') {
				$row['quota'] = $this->quota->snapshot($a);
			}
			$agreements[] = $row;
		}
		$persons = $this->companyCommand->peopleRoster($id);
		$contactsAvailable = $this->contactsSuggest->isAvailable();
		$contactsHomeUrl = $this->urlGenerator->getAbsoluteURL('/apps/contacts/');
		$deals = array_map(fn ($d) => $this->deals->toArray($d), $this->deals->search(['companyId' => $id], 100));
		$activities = [];
		foreach ($this->activities->search(['companyId' => $id, 'openOnly' => true], 100) as $act) {
			$activities[] = [
				'id' => (int)$act->getId(),
				'title' => (string)$act->getTitle(),
				'type' => (string)$act->getType(),
				'dueDate' => $act->getDueDate()?->format('Y-m-d'),
				'origin' => (string)$act->getOrigin(),
				'dealId' => $act->getDealId(),
				'todoUri' => $act->getTodoUri(),
				'updatedAt' => $act->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			];
		}
		$timeline = [];
		foreach ($this->timeline->listForCompany($id, 50) as $ev) {
			$createdAt = $ev->getCreatedAt();
			$type = (string)$ev->getEventType();
			$actor = (string)$ev->getActor();
			$timeline[] = [
				'id' => (int)$ev->getId(),
				'eventType' => $type,
				'eventTypeLabel' => $this->labels->timelineEventType($type),
				'actor' => $actor,
				'actorLabel' => $this->labels->timelineActor($actor),
				'createdAt' => $createdAt?->format(\DateTimeInterface::ATOM),
				'createdAtLabel' => $this->labels->timelineWhen($createdAt),
				'payload' => json_decode((string)$ev->getPayloadJson(), true) ?: [],
			];
		}
		$mail = $this->mailRelated->forCompany($id);
		$capabilities = $this->facades->capabilityMatrix();
		$tcCustomerId = (int)($company->getTcCustomerId() ?? 0);
		$tickets = [];
		$ticketError = null;
		if (!empty($capabilities['ticketcheck'])) {
			if ($tcCustomerId > 0) {
				try {
					$tickets = $this->facades->listOpenTicketsForCustomer($tcCustomerId, 50);
				} catch (\Throwable) {
					$ticketError = true;
					$tickets = [];
				}
			}
		}

		$pcCustomerId = (int)($company->getPcCustomerId() ?? 0);
		$invoices = [];
		$invoiceError = null;
		if (!empty($capabilities['invoicecheck'])) {
			if ($pcCustomerId > 0) {
				try {
					$invoices = $this->facades->listInvoicesForPcCustomer($pcCustomerId, 50);
				} catch (\Throwable) {
					$invoiceError = true;
					$invoices = [];
				}
			}
		}
		$projects = [];
		$projectError = null;
		if (!empty($capabilities['projectcheck'])) {
			if ($pcCustomerId > 0) {
				try {
					$projects = $this->facades->listProjectsForCustomer($pcCustomerId, 50);
				} catch (\Throwable) {
					$projectError = true;
					$projects = [];
				}
			}
		}

		$projects = array_map(function (array $proj): array {
			$id = (int)($proj['id'] ?? 0);
			if ($id > 0 && empty($proj['deepLink'])) {
				try {
					$proj['deepLink'] = $this->urlGenerator->linkToRouteAbsolute('projectcheck.project.show', ['id' => $id]);
				} catch (\Throwable) {
					$proj['deepLink'] = null;
				}
			}
			return $proj;
		}, $projects);

		$openProjectCount = DensityCounts::openProjects($projects);
		$unpaidInvoiceCount = DensityCounts::unpaidInvoices($invoices);
		$openTicketCount = DensityCounts::openTickets($tickets);
		$waitingTicketCount = DensityCounts::waitingTickets($tickets);

		$density = [
			'openProjects' => $openProjectCount,
			'unpaidInvoices' => $unpaidInvoiceCount,
			'openTickets' => $openTicketCount,
			'waitingTickets' => $waitingTicketCount,
			'projectcheck' => !empty($capabilities['projectcheck']),
			'invoicecheck' => !empty($capabilities['invoicecheck']),
			'ticketcheck' => !empty($capabilities['ticketcheck']),
			'projectsHref' => '#crm-projects',
			'invoicesHref' => '#crm-invoices',
			'ticketsHref' => '#crm-tickets',
		];
		$identityLinks = $this->identityHealth->classifyCompany($company);
		// When PC/TC target is broken, do not pretend projects/tickets loaded for a ghost id.
		if (($identityLinks['pc']['state'] ?? '') === IdentityLinkHealthService::STATE_BROKEN) {
			$projects = [];
			$projectError = false;
			$invoices = [];
			$invoiceError = false;
			$density['openProjects'] = 0;
			$density['unpaidInvoices'] = 0;
			$density['projectsHref'] = '#crm-identity-links';
		}
		if (($identityLinks['tc']['state'] ?? '') === IdentityLinkHealthService::STATE_BROKEN) {
			$tickets = [];
			$ticketError = false;
			$density['openTickets'] = 0;
			$density['waitingTickets'] = 0;
			$density['ticketsHref'] = '#crm-identity-links';
		}
		// Sibling deep links when the app is usable and we have a linked id
		// (otherwise stay on-page so muted panels remain reachable).
		try {
			if ($density['projectcheck'] && $pcCustomerId > 0) {
				$density['projectsHref'] = $this->urlGenerator->linkToRoute(
					'projectcheck.customer.show',
					['id' => $pcCustomerId],
				);
			}
		} catch (\Throwable) {
			// Route unavailable — keep on-page anchor.
		}
		try {
			if ($density['invoicecheck']) {
				$firstUnpaid = DensityCounts::firstUnpaidInvoiceId($invoices);
				if ($firstUnpaid !== null) {
					$density['invoicesHref'] = $this->urlGenerator->linkToRoute(
						'invoicecheck.page.show',
						['id' => $firstUnpaid],
					);
				} elseif ($unpaidInvoiceCount > 0) {
					$density['invoicesHref'] = $this->urlGenerator->linkToRoute(
						'invoicecheck.page.receivables',
						['bucket' => 'overdue'],
					);
				} else {
					// Zero unpaid: stay on this page — never dump granny on an empty overdue list.
					$density['invoicesHref'] = '#crm-invoices';
				}
			}
		} catch (\Throwable) {
		}
		$density = \OCA\CustomerCheck\Support\GlanceDensityPresenter::normalizeInvoiceHref($density);
		try {
			if ($density['ticketcheck'] && $tcCustomerId > 0) {
				$density['ticketsHref'] = $this->urlGenerator->linkToRoute(
					'ticketcheck.customer.show',
					['id' => $tcCustomerId],
				);
			}
		} catch (\Throwable) {
		}

		return new TemplateResponse('customercheck', 'company-detail', $this->mergeShellPageParams(
			'company-detail',
			(string)$company->getName(),
			$this->l10n->t('360° customer view'),
			[
				'company' => $company,
				'agreements' => $agreements,
				'persons' => $persons,
				'contactsAvailable' => $contactsAvailable,
				'contactsHomeUrl' => $contactsHomeUrl,
				'deals' => $deals,
				'activities' => $activities,
				'timeline' => $timeline,
				'mail' => $mail,
				'tickets' => $tickets,
				'ticketError' => $ticketError,
				'tcCustomerId' => $tcCustomerId,
				'invoices' => $invoices,
				'invoiceError' => $invoiceError,
				'projects' => $projects,
				'projectError' => $projectError,
				'pcCustomerId' => $pcCustomerId,
				'capabilities' => $capabilities,
				'density' => $density,
				'identityLinks' => $identityLinks,
				'pcWriteAvailable' => !empty($capabilities['projectcheckWrite']),
				'tcWriteAvailable' => !empty($capabilities['ticketcheckWrite']),
				'mnIdentityAvailable' => $this->facades->maintenancecheckIdentityAvailable(),
				'companyUpdatedAt' => \OCA\CustomerCheck\Support\OptimisticLock::format($company->getUpdatedAt()),
				'buyingRoleOptions' => $this->labels->buyingRoleOptions(),
				'nextTouchOn' => $company->getNextTouchOn()?->format('Y-m-d'),
				'prepareMeetingUrl' => $this->urlGenerator->linkToRoute(
					'customercheck.page.prepareMeeting',
					['id' => (int)$company->getId()],
				) . '?workspaceId=' . (int)($company->getWorkspaceId() ?? 0),
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pipeline(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/pipeline', null, [], ['common/folder-picker']);
		$uid = $this->accessControl->currentUserId();
		$wsId = $this->resolvedWorkspaceId();
		$settings = $this->settings->getForUser($uid);
		$deals = $this->deals->search(['openOnly' => false, 'workspaceId' => $wsId], 500);
		$companyIds = array_map(static fn ($d): int => (int)$d->getCompanyId(), $deals);
		$companyMap = $this->companies->mapByIds($companyIds);
		$dealRows = [];
		$ownerUids = [];
		foreach ($deals as $deal) {
			$row = $this->deals->toArray($deal);
			$company = $companyMap[$row['companyId']] ?? null;
			$row['companyName'] = $company !== null
				? $this->labels->companyOrUnknown($company->getName())
				: $this->labels->unknownCompany();
			$row['companyFilesFolderId'] = $company !== null && method_exists($company, 'getFilesFolderId')
				? (int)($company->getFilesFolderId() ?? 0)
				: 0;
			$ownerUid = trim((string)($row['ownerUserId'] ?? ''));
			if ($ownerUid !== '') {
				$ownerUids[] = $ownerUid;
			}
			$dealRows[] = $row;
		}
		$ownerLabels = [];
		foreach ($this->directory->resolveUsers(array_values(array_unique($ownerUids))) as $u) {
			$ownerLabels[$u['id']] = $u['displayName'];
		}
		foreach ($dealRows as &$dealRow) {
			$uid = trim((string)($dealRow['ownerUserId'] ?? ''));
			$dealRow['ownerDisplayName'] = $uid !== ''
				? ($ownerLabels[$uid] ?? $uid)
				: '';
		}
		unset($dealRow);
		$stageFilter = trim((string)$this->request->getParam('stage', ''));
		if ($stageFilter !== '') {
			$dealRows = array_values(array_filter(
				$dealRows,
				static fn (array $d): bool => ($d['stage'] ?? '') === $stageFilter,
			));
		}
		$periodFilter = trim((string)$this->request->getParam('period', ''));
		$periodFromParam = trim((string)$this->request->getParam('from', ''));
		$periodToParam = trim((string)$this->request->getParam('to', ''));
		$fromS = '';
		$toS = '';
		if ($periodFilter === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodFromParam) === 1
			&& preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodToParam) === 1
			&& $periodFromParam <= $periodToParam) {
			$fromS = $periodFromParam;
			$toS = $periodToParam;
		} elseif (in_array($periodFilter, ['month', 'quarter', 'year'], true)) {
			$now = new \DateTimeImmutable('today');
			$from = match ($periodFilter) {
				'month' => $now->modify('first day of this month'),
				'quarter' => $now->modify('first day of this month')->modify('-' . (((int)$now->format('n') - 1) % 3) . ' months'),
				'year' => $now->setDate((int)$now->format('Y'), 1, 1),
			};
			$to = match ($periodFilter) {
				'month' => $now->modify('last day of this month'),
				'quarter' => $from->modify('+3 months')->modify('-1 day'),
				'year' => $now->setDate((int)$now->format('Y'), 12, 31),
			};
			$fromS = $from->format('Y-m-d');
			$toS = $to->format('Y-m-d');
		}
		if ($fromS !== '' && $toS !== '') {
			$dealRows = array_values(array_filter(
				$dealRows,
				static function (array $d) use ($fromS, $toS): bool {
					$close = (string)($d['expectedClose'] ?? $d['updatedAt'] ?? '');
					if ($close === '') {
						return false;
					}
					$day = substr($close, 0, 10);
					return $day >= $fromS && $day <= $toS;
				},
			));
		}
		$companyCount = $this->companies->count(['workspaceId' => $wsId]);
		return new TemplateResponse('customercheck', 'pipeline', $this->mergeShellPageParams(
			'pipeline',
			$this->l10n->t('Deals'),
			$this->l10n->t('All deals — table or board. Each deal has an owner.'),
			[
				'deals' => $dealRows,
				'pipelineView' => $settings['pipelineView'] ?? 'table',
				'stageFilter' => $stageFilter,
				'periodFilter' => $periodFilter,
				'periodFrom' => $periodFromParam,
				'periodTo' => $periodToParam,
				'hasCompanies' => $companyCount > 0,
				'forecastDisclaimer' => $this->l10n->t('Planning labels — not expected cash.'),
				'lossReasonCodes' => \OCA\CustomerCheck\Constants::LOSS_REASON_CODES,
				'forecastBuckets' => \OCA\CustomerCheck\Constants::FORECAST_BUCKETS,
				'lossReasonCodeOptions' => $this->labels->lossReasonCodeOptions(),
				'forecastBucketOptions' => $this->labels->forecastBucketOptions(),
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function agreements(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/agreements');
		$kind = trim((string)$this->request->getParam('kind', ''));
		$status = trim((string)$this->request->getParam('status', ''));
		$expiry = trim((string)$this->request->getParam('expiry', ''));
		$filters = [];
		if ($kind !== '') {
			$filters['kind'] = $kind;
		}
		if ($status !== '') {
			$filters['status'] = $status;
		}
		$now = $this->timeFactory->getDateTime();
		if ($expiry === '60') {
			$filters['expiringBefore'] = (new \DateTimeImmutable($now->format('Y-m-d')))->modify('+60 days');
		} elseif ($expiry === '7') {
			$filters['expiringBefore'] = (new \DateTimeImmutable($now->format('Y-m-d')))->modify('+7 days');
		}
		$list = [];
		$companyIds = [];
		$wsId = $this->resolvedWorkspaceId();
		$filters['workspaceId'] = $wsId;
		foreach ($this->agreements->search($filters, 200) as $a) {
			$companyIds[] = (int)$a->getCompanyId();
			$list[] = [
				'id' => (int)$a->getId(),
				'companyId' => (int)$a->getCompanyId(),
				'label' => (string)$a->getLabel(),
				'kind' => (string)$a->getKind(),
				'productCode' => (string)$a->getProductCode(),
				'status' => (string)$a->getStatus(),
				'invoiceStatus' => (string)$a->getInvoiceStatus(),
				'amountCents' => (int)$a->getAmountCents(),
				'validUntil' => $a->getValidUntil()?->format('Y-m-d'),
				'chip' => $this->agreementStatus->expiryBadge($a, $now),
			];
		}
		$companyMap = $this->companies->mapByIds($companyIds);
		foreach ($list as &$row) {
			$company = $companyMap[$row['companyId']] ?? null;
			$row['companyName'] = $company !== null
				? $this->labels->companyOrUnknown($company->getName())
				: $this->labels->unknownCompany();
		}
		unset($row);
		return new TemplateResponse('customercheck', 'agreements', $this->mergeShellPageParams(
			'agreements',
			$this->l10n->t('Agreements'),
			$this->l10n->t('Licenses, Partner packages and one-time items.'),
			[
				'agreements' => $list,
				'filterKind' => $kind,
				'filterStatus' => $status,
				'filterExpiry' => $expiry,
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function agreementNew(): TemplateResponse
	{
		$uid = $this->accessControl->requireCanUseApp();
		$wsId = $this->resolvedWorkspaceId();
		$this->workspaceAccess->assertCapability($uid, $wsId, 'write');
		$this->registerFrontEndAssets('pages/agreement-form');
		// Never list foreign-book companies in the picker (§3.3 / §21.1).
		$companies = $this->companies->search(['workspaceId' => $wsId], 500, 0);
		$preselectCompanyId = (int)$this->request->getParam('companyId', 0);
		if ($preselectCompanyId > 0) {
			$this->requireCompanyPage($preselectCompanyId);
		}
		return new TemplateResponse('customercheck', 'agreement-form', $this->mergeShellPageParams(
			'agreement-form',
			$this->l10n->t('New agreement'),
			$this->l10n->t('Add a license or partner package for a company.'),
			[
				'agreement' => null,
				'mode' => 'create',
				'companies' => $companies,
				'preselectCompanyId' => $preselectCompanyId > 0 ? $preselectCompanyId : null,
				'productCodes' => $this->productCodes->listAll(),
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function agreementEdit(int $id): TemplateResponse
	{
		$uid = $this->accessControl->requireCanUseApp();
		$this->registerFrontEndAssets('pages/agreement-form');
		$a = $this->agreements->get($id);
		$company = $this->requireCompanyPage((int)$a->getCompanyId());
		$wsId = (int)($company->getWorkspaceId() ?? 0);
		$this->workspaceAccess->assertUserCanAccessCompany($uid, $wsId, 'write');
		$companies = $this->companies->search(['workspaceId' => $wsId], 500, 0);
		return new TemplateResponse('customercheck', 'agreement-form', $this->mergeShellPageParams(
			'agreement-form',
			$this->l10n->t('Edit agreement'),
			(string)$a->getLabel(),
			[
				'agreement' => $a,
				'mode' => 'edit',
				'companies' => $companies,
				'productCodes' => $this->productCodes->listAll(),
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function money(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/money');
		$state = trim((string)$this->request->getParam('state', ''));
		// Workspace scope is mandatory — never blend foreign-book € (§3.3 / §15.3 / §20).
		$overview = $this->money->overview(
			200,
			$state !== '' ? $state : null,
			$this->resolvedWorkspaceId(),
		);
		return new TemplateResponse('customercheck', 'money', $this->mergeShellPageParams(
			'money',
			$this->l10n->t('Money'),
			$this->l10n->t('Overdue, unpaid, paid and unknown invoice states.'),
			[
				'overview' => $overview,
				'filterState' => $state,
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function activities(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/activities');
		$type = trim((string)$this->request->getParam('type', ''));
		$scope = trim((string)$this->request->getParam('scope', 'open'));
		$filters = [];
		if ($type !== '') {
			$filters['type'] = $type;
		}
		if ($scope === 'done') {
			$filters['doneOnly'] = true;
		} elseif ($scope !== 'all') {
			$filters['openOnly'] = true;
		}
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		$groups = ['overdue' => [], 'today' => [], 'later' => [], 'done' => []];
		$rows = [];
		$companyIds = [];
		$filters['workspaceId'] = $this->resolvedWorkspaceId();
		foreach ($this->activities->search($filters, 200) as $act) {
			$companyIds[] = (int)$act->getCompanyId();
			$rows[] = [
				'id' => (int)$act->getId(),
				'companyId' => (int)$act->getCompanyId(),
				'title' => (string)$act->getTitle(),
				'type' => (string)$act->getType(),
				'dueDate' => $act->getDueDate()?->format('Y-m-d'),
				'origin' => (string)$act->getOrigin(),
				'doneAt' => $act->getDoneAt()?->format(\DateTimeInterface::ATOM),
				'updatedAt' => $act->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			];
		}
		$companyMap = $this->companies->mapByIds($companyIds);
		foreach ($rows as $row) {
			$company = $companyMap[$row['companyId']] ?? null;
			$row['companyName'] = $company !== null
				? $this->labels->companyOrUnknown($company->getName())
				: $this->labels->unknownCompany();
			if ($row['doneAt'] !== null) {
				$groups['done'][] = $row;
				continue;
			}
			$due = $row['dueDate'] ?? null;
			if ($due !== null && $due < $today) {
				$groups['overdue'][] = $row;
			} elseif ($due === $today) {
				$groups['today'][] = $row;
			} else {
				$groups['later'][] = $row;
			}
		}
		$list = array_merge($groups['overdue'], $groups['today'], $groups['later'], $groups['done']);
		return new TemplateResponse('customercheck', 'activities', $this->mergeShellPageParams(
			'activities',
			$this->l10n->t('Activities'),
			$this->l10n->t('Calls, emails, meetings and to-dos.'),
			[
				'activities' => $list,
				'groups' => $groups,
				'filterType' => $type,
				'filterScope' => $scope,
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function import(): TemplateResponse
	{
		$uid = $this->accessControl->requireCanUseApp();
		$ctx = $this->workspaceAccess->resolveForUser($uid);
		$wsId = (int)$ctx['workspace']->getId();
		$this->workspaceAccess->assertCapability($uid, $wsId, 'write');
		$this->registerFrontEndAssets('pages/import');
		return new TemplateResponse('customercheck', 'import', $this->mergeShellPageParams(
			'import',
			$this->l10n->t('Import'),
			$this->l10n->t('Bring in customers from CSV, ProjectCheck, TicketCheck, or a license sales log when available.'),
			['capabilities' => $this->facades->capabilityMatrix()],
		));
	}

	/**
	 * Workspace settings index — redirects to the role-aware default sub-page
	 * while preserving `?workspaceId=`.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		$uid = $this->accessControl->currentUserId();
		$canManage = $this->accessControl->isAppAdmin($uid);
		$canManageWorkspace = $this->resolveCanManageWorkspace($uid, $canManage);
		$params = [
			'section' => $this->workspaceSettingsSections->defaultSection($canManageWorkspace),
		];
		$wsQuery = $this->workspaceQueryParams();
		if ($wsQuery !== []) {
			$params = array_merge($params, $wsQuery);
		}

		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'customercheck.page.settingsSection',
			$params,
		));
	}

	/**
	 * One Workspace settings sub-page per former mega-page section.
	 *
	 * The route requirement already restricts {section} to the allowlist; the
	 * catalog check below is defense in depth. Manager-only sections redirect
	 * to the role-aware default — never render privileged partials to viewers.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): TemplateResponse|RedirectResponse|NotFoundResponse
	{
		$section = strtolower(trim($section));
		if (!$this->workspaceSettingsSections->isSection($section)) {
			return new NotFoundResponse();
		}

		$uid = $this->accessControl->currentUserId();
		$canManage = $this->accessControl->isAppAdmin($uid);
		$canManageWorkspace = $this->resolveCanManageWorkspace($uid, $canManage);
		if (!$this->workspaceSettingsSections->isVisible($section, $canManageWorkspace)) {
			$params = [
				'section' => $this->workspaceSettingsSections->defaultSection($canManageWorkspace),
			];
			$wsQuery = $this->workspaceQueryParams();
			if ($wsQuery !== []) {
				$params = array_merge($params, $wsQuery);
			}

			return new RedirectResponse($this->urlGenerator->linkToRoute(
				'customercheck.page.settingsSection',
				$params,
			));
		}

		$this->registerFrontEndAssets('pages/settings', null, [], ['common/folder-picker', 'common/signature-logo-core', 'common/personal-mail-settings-core', 'common/rich-mail-composer', 'common/mailbox-settings-present', 'workspace-settings-legacy-redirect']);
		$settings = $this->settings->getForUser($uid);
		$settingsSectionLabels = [];
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $sectionId) {
			if (!$this->workspaceSettingsSections->isVisible($sectionId, $canManageWorkspace)) {
				continue;
			}
			$settingsSectionLabels[$sectionId] = $this->workspaceSettingsSections->navLabel($this->l10n, $sectionId);
		}

		$shell = $this->mergeShellPageParams(
			'settings',
			$this->workspaceSettingsSections->label($this->l10n, $section),
			$this->workspaceSettingsSections->help($this->l10n, $section),
			[
				'settings' => $settings,
				'capabilities' => $this->facades->capabilityMatrix(),
				'canManage' => $canManage,
				'canManageWorkspace' => $canManageWorkspace,
				'settingsSection' => $section,
				'settingsSectionLabels' => $settingsSectionLabels,
				'breadcrumbParent' => [
					'label' => $section === 'my-email'
						? $this->l10n->t('My email')
						: $this->l10n->t('Team & mailbox'),
					'url' => $this->urlGenerator->linkToRoute(
						'customercheck.page.settings',
						$this->workspaceQueryParams(),
					),
				],
				'availableGroups' => $canManageWorkspace ? $this->directory->availableGroups() : [],
				'circlesAvailable' => $this->circlesDirectory->isAvailable(),
				'availableCircles' => ($canManageWorkspace && $this->circlesDirectory->isAvailable())
					? $this->circlesDirectory->availableCirclesForUser($uid)
					: [],
			],
			true,
		);

		return new TemplateResponse('customercheck', 'settings', $shell);
	}

	/**
	 * App settings index — redirects to the default sub-page while preserving
	 * `?workspaceId=`. Non-admins still land on the denied shell via access.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function appSettings(): RedirectResponse
	{
		$params = ['section' => AppSettingsSectionCatalog::DEFAULT_SECTION];
		$wsQuery = $this->workspaceQueryParams();
		if ($wsQuery !== []) {
			$params = array_merge($params, $wsQuery);
		}

		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'customercheck.page.appSettingsSection',
			$params,
		));
	}

	/**
	 * One App settings sub-page per former mega-page section.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function appSettingsSection(string $section): TemplateResponse|RedirectResponse|NotFoundResponse
	{
		$section = strtolower(trim($section));
		if (!$this->appSettingsSections->isSection($section)) {
			return new NotFoundResponse();
		}

		$this->registerFrontEndAssets('pages/settings', null, [], ['app-settings-legacy-redirect']);
		$uid = $this->accessControl->currentUserId();
		$settings = $this->settings->getForUser($uid);
		$canManage = $this->accessControl->isAppAdmin($uid);
		$canManageWorkspace = $this->resolveCanManageWorkspace($uid, $canManage);
		$allowedUserIds = is_array($settings['access_allowed_user_ids'] ?? null)
			? $settings['access_allowed_user_ids']
			: [];
		$appAdminUserIds = is_array($settings['app_admin_user_ids'] ?? null)
			? $settings['app_admin_user_ids']
			: [];

		$appSettingsSectionLabels = [];
		if ($canManage) {
			foreach (AppSettingsSectionCatalog::SECTIONS as $sectionId) {
				$appSettingsSectionLabels[$sectionId] = $this->appSettingsSections->navLabel($this->l10n, $sectionId);
			}
		}

		$shell = $this->mergeShellPageParams(
			'app-settings',
			$this->appSettingsSections->label($this->l10n, $section),
			$this->appSettingsSections->help($this->l10n, $section),
			[
				'settings' => $settings,
				'capabilities' => $this->facades->capabilityMatrix(),
				'canManage' => $canManage,
				'canManageWorkspace' => $canManageWorkspace,
				'settingsSection' => $section,
				'appSettingsSectionLabels' => $appSettingsSectionLabels,
				'breadcrumbParent' => [
					'label' => $this->l10n->t('App settings'),
					'url' => $this->urlGenerator->linkToRoute(
						'customercheck.page.appSettings',
						$this->workspaceQueryParams(),
					),
				],
				'availableGroups' => $canManage ? $this->directory->availableGroups() : [],
				'selectedAllowedUsers' => $canManage ? $this->directory->resolveUsers($allowedUserIds) : [],
				'selectedAppAdmins' => $canManage ? $this->directory->resolveUsers($appAdminUserIds) : [],
				'license' => $canManage ? $this->license->status() : null,
				'licenseSeats' => $canManage ? $this->license->listSeats(200, 0) : null,
				'personalMailOAuthRedirectUri' => $canManage
					? $this->urlGenerator->linkToRouteAbsolute('customercheck.persona_api.personalMailOAuthCallback')
					: '',
			],
			true,
		);

		return new TemplateResponse('customercheck', 'app-settings', $shell);
	}

	/**
	 * @return array{workspaceId?: int}
	 */
	private function workspaceQueryParams(): array
	{
		$rawId = $this->request->getParam('workspaceId');
		$wid = is_numeric($rawId) ? (int)$rawId : 0;
		if ($wid > 0) {
			return ['workspaceId' => $wid];
		}
		try {
			$uid = $this->accessControl->currentUserIdOrEmpty();
			if ($uid === '') {
				return [];
			}
			$ctx = $this->workspaceAccess->resolveForUser($uid);
			$resolved = (int)$ctx['workspace']->getId();

			return $resolved > 0 ? ['workspaceId' => $resolved] : [];
		} catch (\Throwable) {
			return [];
		}
	}

	private function resolveCanManageWorkspace(string $uid, bool $canManage): bool
	{
		$workspaceRole = $canManage ? 'manager' : 'viewer';
		try {
			$ctx = $this->workspaceAccess->resolveForUser($uid);
			$workspaceRole = WorkspaceCapability::normalizeRole((string)($ctx['role'] ?? 'viewer'));
			if (!empty($ctx['isAppAdmin'])) {
				$canManage = true;
			}
		} catch (\Throwable) {
		}

		return WorkspaceCapability::allows($workspaceRole, 'members', $canManage);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function supportUs(): TemplateResponse
	{
		$this->registerFrontEndAssets('', 'admin-support-us');
		return new TemplateResponse('customercheck', 'support-us', $this->mergeShellPageParams(
			'support-us',
			$this->l10n->t('Support & us'),
			$this->l10n->t('Optional partner care — the app stays free under AGPL.'),
			[],
		));
	}

	/**
	 * Resolve the caller's active workspace id (query → last-used → sole membership).
	 */
	private function resolvedWorkspaceId(): int
	{
		$uid = $this->accessControl->currentUserIdOrEmpty();
		if ($uid === '') {
			return 0;
		}
		$ctx = $this->workspaceAccess->resolveForUser($uid);
		$this->workspaceAccess->assertCapability($uid, (int)$ctx['workspace']->getId(), 'read');

		return (int)$ctx['workspace']->getId();
	}

	/**
	 * Page-level company gate — cross-workspace → NotFoundException (HTTP 404).
	 */
	private function requireCompanyPage(int $id): \OCA\CustomerCheck\Db\Company
	{
		$company = $this->companies->get($id);
		$uid = $this->accessControl->currentUserId();
		$wsId = (int)($company->getWorkspaceId() ?? 0);
		$this->workspaceAccess->assertUserCanAccessCompany($uid, $wsId, 'read');

		return $company;
	}
}
