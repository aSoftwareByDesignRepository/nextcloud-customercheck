<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Support\TicketCompanyLink;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Business Pulse — product §9.
 */
class PulseService
{
	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly DealMapper $dealMapper,
		private readonly FacadeProbeService $facades,
		private readonly MoneyService $money,
		private readonly AgreementStatusService $statusService,
		private readonly AccountPlanService $plans,
		private readonly DisplayLabelService $labels,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(int $workspaceId, ?\DateTimeInterface $today = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$companies = $this->companyMapper->search(['workspaceId' => $workspaceId, 'status' => 'active'], 500);
		$activeCount = count($companies);

		$moneyDto = $this->money->overview(200, null, $workspaceId);
		$overdueCents = 0;
		foreach ($moneyDto['groups']['overdue'] ?? [] as $row) {
			$overdueCents += max(0, (int)($row['amountCents'] ?? 0));
		}
		$openCents = 0;
		foreach ($moneyDto['groups']['sent'] ?? [] as $row) {
			$openCents += max(0, (int)($row['amountCents'] ?? 0));
		}
		$paid30d = 0;
		foreach ($moneyDto['groups']['paid30d'] ?? [] as $row) {
			$paid30d += max(0, (int)($row['amountCents'] ?? 0));
		}

		$unbilled = PulseLogic::unbilled([
			'pcAvailable' => $this->facades->projectcheckAvailable(),
			'billableKnown' => false,
			'stubOpenHours' => true,
			'openBillableMinutes' => 0,
		]);
		$linkedPcIds = [];
		foreach ($companies as $c) {
			$pcId = (int)($c->getPcCustomerId() ?? 0);
			if ($pcId > 0) {
				$linkedPcIds[$pcId] = $pcId;
			}
		}
		// Prefer real Settlement hours when facade exposes honest billable (v2+).
		if ($this->facades->projectcheckAvailable()) {
			$honest = $this->facades->honestOpenBillableMinutes(array_values($linkedPcIds));
			if (is_array($honest) && !empty($honest['known'])) {
				$unbilled = PulseLogic::unbilled([
					'pcAvailable' => true,
					'billableKnown' => true,
					'stubOpenHours' => false,
					'openBillableMinutes' => (int)($honest['minutes'] ?? 0),
				]);
			}
		}
		$unbilled['hint'] = $this->labels->unbilledHint((string)($unbilled['state'] ?? ''));
		$unbilled['displayValue'] = ($unbilled['state'] ?? '') === 'ok'
			? null
			: '—';

		$waiting = 0;
		if ($this->facades->ticketcheckAvailable()) {
			$tcIndex = TicketCompanyLink::tcIndexForWorkspace($companies);
			$companyIdsInWorkspace = [];
			foreach ($companies as $c) {
				$companyIdsInWorkspace[(int)$c->getId()] = true;
			}
			$inBook = [];
			foreach ($this->facades->listWaitingTickets(50) as $ticket) {
				if (!TicketCompanyLink::belongsToWorkspace($ticket, $tcIndex, $companyIdsInWorkspace)) {
					continue;
				}
				$inBook[] = [
					'waiting' => true,
					'status' => (string)($ticket['status'] ?? 'waiting'),
				];
			}
			$waiting = PulseLogic::countWaiting($inBook);
		}

		$renewals30 = 0;
		$attention = [];
		foreach ($this->agreementMapper->findRenewalCandidates(2000, 0, $workspaceId) as $a) {
			$co = null;
			try {
				$co = $this->companyMapper->findById((int)$a->getCompanyId());
			} catch (\Throwable) {
				continue;
			}
			if ((int)($co->getWorkspaceId() ?? 0) !== $workspaceId) {
				continue;
			}
			$days = $this->statusService->daysUntilExpiry($a->getValidUntil(), $today);
			if ($days !== null && $days <= 30) {
				$renewals30++;
			}
			if ($days !== null && $days <= 7 && count($attention) < 25) {
				$product = trim((string)($a->getProductCode() ?? ''));
				$companyName = (string)$co->getName();
				$attention[] = [
					'kind' => 'renewal_hot',
					'kindLabel' => $this->labels->pulseAttentionKind('renewal_hot'),
					'label' => $product !== ''
						? $companyName . ' · ' . $product
						: $companyName,
					'companyId' => (int)$co->getId(),
					'amountCents' => (int)$a->getAmountCents() ?: null,
					'daysUntil' => $days,
				];
			}
		}

		$pipelineCents = 0;
		foreach ($this->dealMapper->search(['openOnly' => true, 'workspaceId' => $workspaceId], 500) as $d) {
			$pipelineCents += max(0, (int)$d->getValueCents());
		}

		$openProjects = 0;
		$linkDebt = [];
		$caps = $this->facades->capabilityMatrix();
		$pcCapable = !empty($caps['projectcheck']) && $this->facades->projectcheckAvailable();
		foreach ($companies as $c) {
			$pcId = (int)($c->getPcCustomerId() ?? 0);
			if ($pcCapable && $pcId > 0) {
				foreach ($this->facades->listProjectsForCustomer($pcId, 50) as $proj) {
					$status = mb_strtolower((string)($proj['status'] ?? 'active'));
					if (!in_array($status, ['done', 'closed', 'cancelled', 'archived'], true)) {
						$openProjects++;
					}
				}
			}
			$missing = PulseLogic::linkDebt([
				'pcCapable' => !empty($caps['projectcheck']),
				'tcCapable' => !empty($caps['ticketcheck']),
				'filesCapable' => true,
				'pcLinked' => $pcId > 0,
				'tcLinked' => (int)($c->getTcCustomerId() ?? 0) > 0,
				'filesLinked' => (int)($c->getFilesFolderId() ?? 0) > 0,
			]);
			if ($missing !== []) {
				$linkDebt[] = [
					'companyId' => (int)$c->getId(),
					'name' => (string)$c->getName(),
					'missing' => $missing,
				];
			}
		}

		foreach (($moneyDto['groups']['overdue'] ?? []) as $row) {
			if (count($attention) >= 25) {
				break;
			}
			$invoiceLabel = trim((string)($row['label'] ?? $row['companyName'] ?? ''));
			$attention[] = [
				'kind' => 'overdue_invoice',
				'kindLabel' => $this->labels->pulseAttentionKind('overdue_invoice'),
				'label' => $invoiceLabel !== ''
					? $invoiceLabel
					: $this->l10n->t('Overdue invoice'),
				'companyId' => $row['companyId'] ?? null,
				'amountCents' => $row['amountCents'] ?? null,
			];
		}
		try {
			foreach ($this->plans->listForWorkspace($workspaceId) as $plan) {
				if (count($attention) >= 25) {
					break;
				}
				$health = (string)($plan['health'] ?? '');
				if ($health === 'stalled' || $health === 'at_risk') {
					$kind = 'plan_' . $health;
					$goal = trim((string)($plan['goal'] ?? ''));
					$companyName = trim((string)($plan['companyName'] ?? ''));
					$title = $goal !== '' ? $goal : ($companyName !== '' ? $companyName : $this->l10n->t('Account plan'));
					$attention[] = [
						'kind' => $kind,
						'kindLabel' => $this->labels->pulseAttentionKind($kind),
						'label' => $title . ' · ' . $this->labels->planHealth($health),
						'companyId' => $plan['companyId'] ?? null,
						'amountCents' => null,
					];
				}
			}
		} catch (\Throwable) {
			// Plans optional on warm installs mid-migration.
		}

		return [
			'workspaceId' => $workspaceId,
			// No tax/GoBD disclaimer chrome — Pulse is an ops glance; honesty lives in docs/Store ban-list.
			'updatedAt' => $today->format(DATE_ATOM),
			'metrics' => [
				'activeCustomers' => $activeCount,
				'overdueCents' => $overdueCents,
				'openCents' => $openCents,
				'paid30dCents' => $paid30d,
				'unbilled' => $unbilled,
				'openProjects' => $openProjects,
				'waitingTickets' => $waiting,
				'renewals30d' => $renewals30,
				'pipelineCents' => $pipelineCents,
			],
			'attention' => $attention,
			'linkDebt' => array_slice($linkDebt, 0, 50),
			'fedBy' => [
				'projectcheck' => $this->facades->projectcheckAvailable(),
				'invoicecheck' => $this->facades->invoicecheckAvailable(),
				'ticketcheck' => $this->facades->ticketcheckAvailable(),
			],
		];
	}
}
