<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\MailRelatedService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\TodayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
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
		private readonly MoneyService $money,
		private readonly SettingsService $settings,
		private readonly DirectoryOptionsService $directory,
		private readonly QuotaService $quota,
		private readonly FacadeProbeService $facades,
		private readonly DisplayLabelService $labels,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
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
		$this->registerFrontEndAssets('pages/today');
		$queue = $this->today->build();
		return new TemplateResponse('customercheck', 'today', $this->mergeShellPageParams(
			'today',
			$this->l10n->t('Today'),
			$this->l10n->t('What needs attention right now.'),
			[
				'queue' => $queue,
				'companyCount' => $this->companies->count(['archived' => false]),
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companies(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/companies');
		$search = trim((string)$this->request->getParam('q', ''));
		$status = trim((string)$this->request->getParam('status', ''));
		$archived = $this->request->getParam('archived', '') === '1';
		$filters = ['archived' => $archived];
		if ($search !== '') {
			$filters['search'] = $search;
		}
		if ($status !== '') {
			$filters['status'] = $status;
		}
		$list = $this->companies->search($filters, 200, 0);
		return new TemplateResponse('customercheck', 'companies', $this->mergeShellPageParams(
			'companies',
			$this->l10n->t('Companies'),
			$this->l10n->t('Your customer registry.'),
			[
				'companies' => $list,
				'filterQ' => $search,
				'filterStatus' => $status,
				'filterArchived' => $archived,
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyNew(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/company-form');
		return new TemplateResponse('customercheck', 'company-form', $this->mergeShellPageParams(
			'company-form',
			$this->l10n->t('New company'),
			$this->l10n->t('Create a customer record.'),
			array_merge(['company' => null, 'mode' => 'create'], $this->siblingLinkOptions(null, null)),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyEdit(int $id): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/company-form');
		$company = $this->companies->get($id);
		return new TemplateResponse('customercheck', 'company-form', $this->mergeShellPageParams(
			'company-form',
			$this->l10n->t('Edit company'),
			(string)$company->getName(),
			array_merge(
				['company' => $company, 'mode' => 'edit'],
				$this->siblingLinkOptions($company->getPcCustomerId(), $company->getTcCustomerId()),
			),
		));
	}

	/**
	 * Named sibling-customer pickers — never ask users to type primary keys.
	 *
	 * @return array{
	 *   pcCustomerOptions: list<array{id:int,label:string}>,
	 *   tcCustomerOptions: list<array{id:int,label:string}>,
	 *   pcCustomersAvailable: bool,
	 *   tcCustomersAvailable: bool
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
		];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function companyDetail(int $id): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/company-detail', null, [], ['common/jump-nav']);
		$company = $this->companies->get($id);
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
				'chip' => $chip,
			];
			if ((string)$a->getKind() === 'partner') {
				$row['quota'] = $this->quota->snapshot($a);
			}
			$agreements[] = $row;
		}
		$persons = [];
		foreach ($this->persons->listForCompany($id) as $p) {
			$persons[] = [
				'id' => (int)$p->getId(),
				'name' => (string)$p->getName(),
				'email' => $p->getEmail(),
				'phone' => $p->getPhone(),
				'role' => $p->getRole(),
				'isPrimary' => (bool)$p->getIsPrimary(),
			];
		}
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
			$timeline[] = [
				'id' => (int)$ev->getId(),
				'eventType' => (string)$ev->getEventType(),
				'actor' => (string)$ev->getActor(),
				'createdAt' => $ev->getCreatedAt()?->format(\DateTimeInterface::ATOM),
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

		return new TemplateResponse('customercheck', 'company-detail', $this->mergeShellPageParams(
			'company-detail',
			(string)$company->getName(),
			$this->l10n->t('360° customer view'),
			[
				'company' => $company,
				'agreements' => $agreements,
				'persons' => $persons,
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
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pipeline(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/pipeline');
		$uid = $this->accessControl->currentUserId();
		$settings = $this->settings->getForUser($uid);
		$deals = $this->deals->search(['openOnly' => false], 500);
		$companyIds = array_map(static fn ($d): int => (int)$d->getCompanyId(), $deals);
		$companyMap = $this->companies->mapByIds($companyIds);
		$dealRows = [];
		foreach ($deals as $deal) {
			$row = $this->deals->toArray($deal);
			$company = $companyMap[$row['companyId']] ?? null;
			$row['companyName'] = $company !== null
				? $this->labels->companyOrUnknown($company->getName())
				: $this->labels->unknownCompany();
			$dealRows[] = $row;
		}
		$stageFilter = trim((string)$this->request->getParam('stage', ''));
		if ($stageFilter !== '') {
			$dealRows = array_values(array_filter(
				$dealRows,
				static fn (array $d): bool => ($d['stage'] ?? '') === $stageFilter,
			));
		}
		$companyOptions = [];
		foreach ($this->companies->search([], 500, 0) as $company) {
			$companyOptions[] = [
				'id' => (int)$company->getId(),
				'name' => (string)$company->getName(),
				'slug' => (string)$company->getSlug(),
			];
		}
		return new TemplateResponse('customercheck', 'pipeline', $this->mergeShellPageParams(
			'pipeline',
			$this->l10n->t('Pipeline'),
			$this->l10n->t('Deals as a table or board.'),
			[
				'deals' => $dealRows,
				'pipelineView' => $settings['pipelineView'] ?? 'table',
				'stageFilter' => $stageFilter,
				'companyOptions' => $companyOptions,
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
		$this->registerFrontEndAssets('pages/agreement-form');
		$companies = $this->companies->search([], 500, 0);
		$preselectCompanyId = (int)$this->request->getParam('companyId', 0);
		return new TemplateResponse('customercheck', 'agreement-form', $this->mergeShellPageParams(
			'agreement-form',
			$this->l10n->t('New agreement'),
			'',
			[
				'agreement' => null,
				'mode' => 'create',
				'companies' => $companies,
				'preselectCompanyId' => $preselectCompanyId > 0 ? $preselectCompanyId : null,
			],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function agreementEdit(int $id): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/agreement-form');
		$a = $this->agreements->get($id);
		$companies = $this->companies->search([], 500, 0);
		return new TemplateResponse('customercheck', 'agreement-form', $this->mergeShellPageParams(
			'agreement-form',
			$this->l10n->t('Edit agreement'),
			(string)$a->getLabel(),
			['agreement' => $a, 'mode' => 'edit', 'companies' => $companies],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function money(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/money');
		$state = trim((string)$this->request->getParam('state', ''));
		$overview = $this->money->overview(200, $state !== '' ? $state : null);
		return new TemplateResponse('customercheck', 'money', $this->mergeShellPageParams(
			'money',
			$this->l10n->t('Money'),
			$this->l10n->t('Unpaid, overdue and recently paid invoices.'),
			['overview' => $overview, 'filterState' => $state],
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
		$this->registerFrontEndAssets('pages/import');
		return new TemplateResponse('customercheck', 'import', $this->mergeShellPageParams(
			'import',
			$this->l10n->t('Import'),
			$this->l10n->t('Bring in customers from sales log, ProjectCheck, TicketCheck or CSV.'),
			['capabilities' => $this->facades->capabilityMatrix()],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		$this->registerFrontEndAssets('pages/settings');
		$uid = $this->accessControl->currentUserId();
		$settings = $this->settings->getForUser($uid);
		$canManage = $this->accessControl->isAppAdmin($uid);
		$allowedUserIds = is_array($settings['access_allowed_user_ids'] ?? null)
			? $settings['access_allowed_user_ids']
			: [];
		return new TemplateResponse('customercheck', 'settings', $this->mergeShellPageParams(
			'settings',
			$this->l10n->t('Settings'),
			$this->l10n->t('Access control, reminders and integrations.'),
			[
				'settings' => $settings,
				'capabilities' => $this->facades->capabilityMatrix(),
				'canManage' => $canManage,
				'availableAppAdmins' => $canManage ? $this->directory->availableAppAdmins() : [],
				'availableGroups' => $canManage ? $this->directory->availableGroups() : [],
				'selectedAllowedUsers' => $canManage ? $this->directory->resolveUsers($allowedUserIds) : [],
			],
		));
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
}
