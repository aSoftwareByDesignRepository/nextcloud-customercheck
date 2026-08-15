<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Integration\SiblingFacadeContracts;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\DensityCounts;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Company Command DTO (glance) — COMMAND-CENTER-SPEC §10.3.
 */
class CompanyCommandService
{
	public function __construct(
		private readonly CompanyService $companies,
		private readonly PersonService $persons,
		private readonly AgreementMapper $agreementMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly EmailTouchMapper $emailTouchMapper,
		private readonly FacadeProbeService $facades,
		private readonly BillingIntegrityService $billing,
		private readonly NextActionPriority $priority,
		private readonly AgreementStatusService $statusService,
		private readonly DealService $deals,
		private readonly VTodoService $vTodo,
		private readonly CommunicationFeedService $feed,
		private readonly MailRelatedService $mailRelated,
		private readonly CoverageService $coverage,
		private readonly MailboxService $mailbox,
		private readonly WorkspaceMapper $workspaces,
		private readonly IAppManager $appManager,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(int $companyId, string $userId): array
	{
		$company = $this->companies->get($companyId);
		$today = $this->timeFactory->getDateTime();
		$persons = $this->persons->listForCompany($companyId);
		$primary = null;
		foreach ($persons as $p) {
			if ($p->isPrimaryContact()) {
				$primary = $p;
				break;
			}
		}
		if ($primary === null && $persons !== []) {
			$primary = $persons[0];
		}

		$lastDiscussion = $this->resolveLastDiscussion($companyId);
		$billingDto = $this->resolveBilling($company);
		$runway = $this->resolveRunway($companyId, $today);
		$nextAction = $this->resolveNextAction($company, $today, $billingDto, $runway);
		$openCallbacks = $this->countOpenCallbacks($companyId);
		$openTasks = $this->activityMapper->countSearch(['companyId' => $companyId, 'openOnly' => true]);
		$pcId = (int)($company->getPcCustomerId() ?? 0);
		$tcId = (int)($company->getTcCustomerId() ?? 0);
		$openProjects = 0;
		$waitingTickets = 0;
		if ($pcId > 0 && $this->facades->projectcheckAvailable()) {
			foreach ($this->facades->listProjectsForCustomer($pcId, 200) as $proj) {
				$status = mb_strtolower((string)($proj['status'] ?? 'active'));
				if (!in_array($status, ['done', 'closed', 'cancelled', 'archived'], true)) {
					$openProjects++;
				}
			}
		}
		if ($tcId > 0 && $this->facades->ticketcheckAvailable()) {
			$waitingTickets = DensityCounts::waitingTickets(
				$this->facades->listOpenTicketsForCustomer($tcId, 50),
			);
		}

		$caps = $this->facades->capabilityMatrix();
		$tasksCapable = $this->vTodo->isAvailable($userId) || $this->appManager->isEnabledForUser('tasks');

		return [
			'companyId' => (int)$company->getId(),
			'name' => (string)$company->getName(),
			'slug' => (string)$company->getSlug(),
			'status' => (string)$company->getStatus(),
			'primaryPerson' => $primary === null ? null : [
				'id' => (int)$primary->getId(),
				'name' => (string)$primary->getName(),
				'role' => $primary->getRole(),
				'email' => $primary->getEmail(),
				'phone' => $primary->getPhone(),
			],
			'lastDiscussion' => $lastDiscussion,
			'nextAction' => $nextAction ?? [
				'kind' => 'none',
				'label' => $this->l10n->t('No urgent action'),
				'dueDate' => null,
				'target' => null,
			],
			'runway' => $runway,
			'billing' => $billingDto,
			'counts' => [
				'openProjects' => $openProjects,
				'waitingTickets' => $waitingTickets,
				'openCallbacks' => $openCallbacks,
				'openTasks' => $openTasks,
				'filesLinked' => $company->getFilesFolderId() !== null && (int)$company->getFilesFolderId() > 0,
			],
			'capabilities' => [
				'projectcheck' => !empty($caps['projectcheck']),
				'invoicecheck' => !empty($caps['invoicecheck']),
				'ticketcheck' => !empty($caps['ticketcheck']),
				'mail' => !empty($caps['mail']),
				'files' => true,
				'tasks' => $tasksCapable,
				'contacts' => $this->contactsEnabled(),
			],
			'coverage' => $this->coverage->forCompany($companyId, $today),
			'nextTouchOn' => $company->getNextTouchOn()?->format('Y-m-d'),
			'updatedAt' => $company->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}

	/**
	 * Communication feed for a company (merged sources).
	 *
	 * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
	 */
	public function communication(
		int $companyId,
		string $userId,
		int $limit = 25,
		?string $cursor = null,
		?int $personId = null,
		bool $includeSystem = false,
	): array {
		$company = $this->companies->get($companyId);
		$raw = [];

		foreach ($this->emailTouchMapper->findByCompany($companyId, 200) as $touch) {
			$raw[] = [
				'id' => 'email_touch:' . (int)$touch->getId(),
				'kind' => 'email_touch',
				'occurredAt' => $touch->getOccurredAt()?->format(\DateTimeInterface::ATOM) ?? '',
				'direction' => (string)$touch->getDirection(),
				'personId' => $touch->getPersonId(),
				'personName' => null,
				'subject' => (string)$touch->getSubject(),
				'snippet' => $touch->getSnippet(),
				'deepLink' => null,
				'mailMessageId' => $touch->getMailMessageId(),
			];
		}

		try {
			$mail = $this->mailRelated->forCompany($companyId);
			foreach (($mail['related'] ?? []) as $row) {
				if (!is_array($row)) {
					continue;
				}
				$raw[] = [
					'id' => 'mail_related:' . (string)($row['id'] ?? md5((string)($row['subject'] ?? '') . ($row['date'] ?? ''))),
					'kind' => 'mail_related',
					'occurredAt' => (string)($row['date'] ?? $row['occurredAt'] ?? ''),
					'direction' => (string)($row['direction'] ?? 'inbound'),
					'personId' => null,
					'personName' => null,
					'subject' => (string)($row['subject'] ?? ''),
					'snippet' => (string)($row['snippet'] ?? ''),
					'deepLink' => $row['deepLink'] ?? null,
					'mailMessageId' => isset($row['messageId']) ? (string)$row['messageId'] : null,
					'private' => true,
					'privacyBadge' => $this->l10n->t('Private to you'),
				];
			}
		} catch (\Throwable) {
			// Mail degrade — feed still usable.
		}

		foreach ($this->activityMapper->search(['companyId' => $companyId], 200) as $act) {
			$type = (string)$act->getType();
			if (!in_array($type, ['call', 'email', 'meeting'], true)) {
				continue;
			}
			$occurred = $act->getDoneAt() ?? $act->getDueDate() ?? $act->getCreatedAt();
			$raw[] = [
				'id' => 'activity:' . (int)$act->getId(),
				'kind' => 'activity',
				'occurredAt' => $occurred?->format(\DateTimeInterface::ATOM) ?? '',
				'direction' => null,
				'personId' => $act->getPersonId(),
				'personName' => null,
				'subject' => (string)$act->getTitle(),
				'snippet' => $act->getDetails(),
				'deepLink' => null,
				'mailMessageId' => null,
			];
		}

		try {
			foreach ($this->mailbox->matchedForCompany($companyId, 50) as $msg) {
				$canRead = MailAclLogic::canReadMessage(
					$userId,
					[
						'visibility' => (string)($msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),
						'ownerUserId' => $msg['ownerUserId'] ?? null,
						'companyId' => $companyId,
					],
					true, // caller already has company read
					false,
				);
				if (!$canRead) {
					continue;
				}
				$sent = (string)($msg['sentAt'] ?? '');
				$snippet = trim((string)($msg['snippet'] ?? ''));
				$visibility = (string)($msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED);
				$raw[] = [
					'id' => 'shared_mail:' . (int)$msg['id'],
					'kind' => 'shared_mail',
					'occurredAt' => $sent !== '' ? $sent : '',
					'direction' => (string)($msg['direction'] ?? 'inbound'),
					'personId' => null,
					'personName' => null,
					'subject' => (string)($msg['subject'] ?? ''),
					'snippet' => $snippet,
					'from' => (string)($msg['from'] ?? ''),
					// Never embed cached body text in list/feed payloads — fetch via /mail/{id}/preview.
					'preview' => null,
					'hasPreview' => !empty($msg['hasPreview']),
					'crmMailId' => (int)$msg['id'],
					'deepLink' => null,
					'mailMessageId' => isset($msg['messageId']) ? (string)$msg['messageId'] : null,
					'shared' => $visibility === MailAclLogic::VISIBILITY_SHARED,
					'visibility' => $visibility,
					'mailboxLabel' => (string)($msg['mailboxLabel'] ?? 'shared'),
				];
			}
		} catch (\Throwable) {
			// Shared mail optional — feed still usable.
		}

		$persons = [];
		foreach ($this->persons->listForCompany($companyId) as $p) {
			$persons[(int)$p->getId()] = (string)$p->getName();
		}
		foreach ($raw as &$row) {
			$pid = (int)($row['personId'] ?? 0);
			if ($pid > 0 && isset($persons[$pid])) {
				$row['personName'] = $persons[$pid];
			}
		}
		unset($row);

		if ($personId !== null && $personId > 0) {
			$raw = array_values(array_filter(
				$raw,
				static fn (array $r): bool => (int)($r['personId'] ?? 0) === $personId,
			));
		}

		$merged = $this->feed->mergeAndSort($raw, $includeSystem);
		$page = $this->feed->paginate($merged, $limit, $cursor);

		$wsId = (int)($company->getWorkspaceId() ?? 0);
		$disclosure = null;
		$boxes = [];
		try {
			if ($wsId > 0) {
				$boxes = $this->mailbox->listMailboxes($wsId, MailAclLogic::KIND_TEAM);
			}
		} catch (\Throwable) {
			$boxes = [];
		}
		$hasSharedRow = false;
		foreach ($page['items'] as $item) {
			if (!empty($item['shared']) || ($item['kind'] ?? '') === 'shared_mail') {
				$hasSharedRow = true;
				break;
			}
		}
		if ($boxes !== [] || $hasSharedRow) {
			$label = $boxes !== [] ? (string)$boxes[0]['label'] : 'shared';
			$workspaceName = $this->l10n->t('this workspace');
			if ($wsId > 0) {
				try {
					$workspaceName = (string)$this->workspaces->findById($wsId)->getName();
					if (trim($workspaceName) === '') {
						$workspaceName = $this->l10n->t('this workspace');
					}
				} catch (\Throwable) {
					$workspaceName = $this->l10n->t('this workspace');
				}
			}
			$disclosure = $this->mailbox->disclosure($label, $workspaceName);
		}
		$page['disclosure'] = $disclosure;
		$page['privateNote'] = $this->l10n->t('Messages from your Mail accounts only.');

		return $page;
	}

	/**
	 * People roster with last-touch — CC §5.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function peopleRoster(int $companyId): array
	{
		$persons = $this->persons->listForCompany($companyId);
		$touches = [];
		foreach ($this->emailTouchMapper->findByCompany($companyId, 500) as $touch) {
			$touches[] = [
				'personId' => $touch->getPersonId(),
				'occurredAt' => $touch->getOccurredAt()?->format(\DateTimeInterface::ATOM) ?? '',
				'subject' => (string)$touch->getSubject(),
			];
		}
		foreach ($this->activityMapper->search(['companyId' => $companyId], 500) as $act) {
			$occurred = $act->getDoneAt() ?? $act->getCreatedAt();
			$touches[] = [
				'personId' => $act->getPersonId(),
				'occurredAt' => $occurred?->format(\DateTimeInterface::ATOM) ?? '',
				'subject' => (string)$act->getTitle(),
			];
		}
		$last = $this->feed->lastTouchByPerson($touches);
		$out = [];
		foreach ($persons as $p) {
			$pid = (int)$p->getId();
			$lt = $last[$pid] ?? null;
			$out[] = [
				'id' => $pid,
				'name' => (string)$p->getName(),
				'role' => $p->getRole(),
				'buyingRole' => $p->getBuyingRole(),
				'email' => $p->getEmail(),
				'phone' => $p->getPhone(),
				'isPrimary' => $p->isPrimaryContact(),
				'contactsUid' => $p->getContactsUid(),
				'lastTouch' => $lt,
				'lastTouchLabel' => $lt === null
					? $this->l10n->t('No touch yet')
					: (string)($lt['subject'] ?? $lt['at']),
				'updatedAt' => \OCA\CustomerCheck\Support\OptimisticLock::format($p->getUpdatedAt()),
			];
		}

		return $out;
	}

	/** @return array<string, mixed>|null */
	private function resolveLastDiscussion(int $companyId): ?array
	{
		$page = $this->communication($companyId, '', 1);
		$item = $page['items'][0] ?? null;
		if (!is_array($item)) {
			return null;
		}

		return [
			'at' => $item['occurredAt'] ?? null,
			'kind' => $item['kind'] ?? null,
			'direction' => $item['direction'] ?? null,
			'summary' => $item['subject'] ?? null,
			'personId' => $item['personId'] ?? null,
		];
	}

	/** @return array<string, mixed> */
	private function resolveBilling(\OCA\CustomerCheck\Db\Company $company): array
	{
		$companyId = (int)$company->getId();
		$pcId = (int)($company->getPcCustomerId() ?? 0);
		$pcAvailable = $this->facades->projectcheckAvailable();
		$icAvailable = $this->facades->invoicecheckAvailable();
		$pcLinked = $pcId > 0 && $pcAvailable;
		$icLinked = $pcId > 0 && $icAvailable;
		$icOverdue = 0;
		$icOpen = 0;
		$billable = null;
		$billableKnown = false;
		$uninvoiced = null;
		$uninvoicedKnown = false;

		$licenseOverdue = 0;
		$licenseOpen = 0;
		$licensePaid = 0;
		$licenseNone = 0;
		$licenseTotal = 0;
		foreach ($this->agreementMapper->findByCompany($companyId) as $ag) {
			$status = (string)$ag->getStatus();
			if (in_array($status, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			$licenseTotal++;
			$bucket = $this->billing->classifyAgreementInvoiceStatus((string)$ag->getInvoiceStatus());
			if ($bucket === 'overdue') {
				$licenseOverdue++;
			} elseif ($bucket === 'open') {
				$licenseOpen++;
			} elseif ($bucket === 'paid') {
				$licensePaid++;
			} else {
				$licenseNone++;
			}
		}

		if ($icLinked) {
			foreach ($this->facades->listInvoicesForPcCustomer($pcId, 200) as $row) {
				$bucket = $this->billing->classifyInvoiceRow($row);
				if ($bucket === 'overdue') {
					$icOverdue++;
				} elseif ($bucket === 'open') {
					$icOpen++;
				}
			}
		}

		if ($pcLinked) {
			try {
				if (class_exists(SiblingFacadeContracts::PROJECT_CHECK_FACADE)) {
					$facade = \OC::$server->get(SiblingFacadeContracts::PROJECT_CHECK_FACADE);
					if (is_object($facade) && method_exists($facade, 'sumOpenBillableMinutes')) {
						$billable = max(0, (int)$facade->sumOpenBillableMinutes($pcId));
						$billableKnown = true;
					}
				}
				$projects = $this->facades->listProjectsForCustomer($pcId, 200);
				$hasUninvoicedField = false;
				$count = 0;
				foreach ($projects as $proj) {
					if (array_key_exists('uninvoiced', $proj)) {
						$hasUninvoicedField = true;
						if (!empty($proj['uninvoiced'])) {
							$count++;
						}
					}
				}
				if ($hasUninvoicedField) {
					$uninvoiced = $count;
					$uninvoicedKnown = true;
				}
			} catch (\Throwable) {
				// degrade
			}
		}

		$merged = $this->billing->mergeInvoiceCounts(
			$icLinked,
			$icOverdue,
			$icOpen,
			$licenseOverdue,
			$licenseOpen,
		);
		$licenseLinked = ($licenseOverdue + $licenseOpen + $licensePaid) > 0
			|| in_array($merged['sourceMode'], ['licenses', 'licenses_fallback'], true);

		$evaluated = $this->billing->evaluate([
			'pcLinked' => $pcLinked || $icLinked,
			'icLinked' => $icLinked,
			'licenseLinked' => $licenseLinked,
			'overdueCount' => $merged['overdueCount'],
			'openCount' => $merged['openCount'],
			'openBillableMinutes' => $billable,
			'billableKnown' => $billableKnown,
			'uninvoicedProjects' => $uninvoiced,
			'uninvoicedKnown' => $uninvoicedKnown,
		]);

		$evaluated['sourceMode'] = $merged['sourceMode'];
		$evaluated['sources'] = [
			'projectCheckAvailable' => $pcAvailable,
			'invoiceCheckAvailable' => $icAvailable,
			'projectCheckLinked' => $pcLinked,
			'invoiceCheckLinked' => $icLinked,
			'licenses' => $licenseTotal > 0,
		];
		$evaluated['licenses'] = [
			'total' => $licenseTotal,
			'overdue' => $licenseOverdue,
			'sent' => $licenseOpen,
			'paid' => $licensePaid,
			'none' => $licenseNone,
			'unpaid' => $licenseOverdue + $licenseOpen,
		];

		return $evaluated;
	}

	/** @return array{validUntil: ?string, daysLeft: ?int, agreementId: ?int} */
	private function resolveRunway(int $companyId, \DateTimeInterface $today): array
	{
		$best = null;
		$bestDays = null;
		$bestId = null;
		foreach ($this->agreementMapper->findByCompany($companyId) as $ag) {
			$status = (string)$ag->getStatus();
			if (in_array($status, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			$until = $ag->getValidUntil();
			if ($until === null) {
				continue;
			}
			$days = $this->statusService->daysUntilExpiry($until, $today);
			if ($days === null) {
				continue;
			}
			if ($bestDays === null || $days < $bestDays) {
				$bestDays = $days;
				$best = $until->format('Y-m-d');
				$bestId = (int)$ag->getId();
			}
		}

		return [
			'validUntil' => $best,
			'daysLeft' => $bestDays,
			'agreementId' => $bestId,
		];
	}

	/**
	 * @param array<string, mixed> $billing
	 * @param array{validUntil: ?string, daysLeft: ?int, agreementId: ?int} $runway
	 * @return array<string, mixed>|null
	 */
	private function resolveNextAction(
		\OCA\CustomerCheck\Db\Company $company,
		\DateTimeInterface $today,
		array $billing,
		array $runway,
	): ?array {
		$companyId = (int)$company->getId();
		$candidates = [];

		foreach ($this->activityMapper->findOpenByCompany($companyId) as $act) {
			$due = $act->getDueDate();
			if ($due === null) {
				continue;
			}
			$days = DateMath::daysBetween($today, $due);
			$isCb = $act->isCallback() || (string)$act->getType() === 'call';
			if ($days < 0) {
				$candidates[] = [
					'kind' => $isCb ? 'callback_overdue' : 'activity_overdue',
					'label' => (string)$act->getTitle(),
					'dueDate' => $due->format('Y-m-d'),
					'targetType' => 'activity',
					'targetId' => (int)$act->getId(),
					'preferCall' => $isCb,
				];
			} elseif ($days === 0) {
				$candidates[] = [
					'kind' => 'activity_due_today',
					'label' => (string)$act->getTitle(),
					'dueDate' => $due->format('Y-m-d'),
					'targetType' => 'activity',
					'targetId' => (int)$act->getId(),
					'preferCall' => $isCb,
				];
			}
		}

		if ($runway['daysLeft'] !== null && $runway['daysLeft'] <= 7 && $runway['agreementId'] !== null) {
			$candidates[] = [
				'kind' => 'renewal_urgent',
				'label' => $this->l10n->t('Renewal in %s days', [(string)$runway['daysLeft']]),
				'dueDate' => $runway['validUntil'],
				'targetType' => 'agreement',
				'targetId' => (int)$runway['agreementId'],
			];
		}

		if (($billing['overdueCount'] ?? 0) >= 1) {
			$candidates[] = [
				'kind' => 'invoice_overdue',
				'label' => $this->l10n->t('Overdue invoices'),
				'dueDate' => null,
				'targetType' => 'billing',
				'targetId' => $companyId,
			];
		}

		foreach ($this->deals->search(['companyId' => $companyId, 'openOnly' => true], 50) as $deal) {
			if ($this->deals->isStale($deal, $today)) {
				$candidates[] = [
					'kind' => 'deal_stale',
					'label' => (string)$deal->getTitle(),
					'dueDate' => $deal->getStageChangedAt()?->format('Y-m-d'),
					'targetType' => 'deal',
					'targetId' => (int)$deal->getId(),
				];
			}
		}

		$tcId = (int)($company->getTcCustomerId() ?? 0);
		if ($tcId > 0 && $this->facades->ticketcheckAvailable()) {
			$openTickets = $this->facades->listOpenTicketsForCustomer($tcId, 50);
			if (DensityCounts::waitingTickets($openTickets) > 0) {
				$candidates[] = [
					'kind' => 'ticket_waiting',
					'label' => $this->l10n->t('Tickets waiting'),
					'dueDate' => null,
					'targetType' => 'tickets',
					'targetId' => $companyId,
				];
			}
		}

		return $this->priority->pick($candidates, $today);
	}

	private function countOpenCallbacks(int $companyId): int
	{
		$n = 0;
		foreach ($this->activityMapper->findOpenByCompany($companyId) as $act) {
			if ($act->isCallback() || (string)$act->getType() === 'call') {
				$n++;
			}
		}

		return $n;
	}

	private function contactsEnabled(): bool
	{
		return $this->appManager->isEnabledForUser('contacts');
	}
}
