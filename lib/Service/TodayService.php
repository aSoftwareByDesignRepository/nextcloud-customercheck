<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCA\CustomerCheck\Support\TicketCompanyLink;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Today urgency queue — PSD §10.3 / §14.5.
 *
 * Sort: lower urgency number first, then dueDate ASC.
 * Bands: agreement_expiry 10–18, ticket_waiting 20, activity_overdue 30,
 * activity_due 40, deal_stale 50.
 */
class TodayService
{
	public function __construct(
		private readonly AgreementMapper $agreementMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly CompanyMapper $companyMapper,
		private readonly DealMapper $dealMapper,
		private readonly DealService $dealService,
		private readonly FacadeProbeService $facades,
		private readonly AgreementStatusService $statusService,
		private readonly DisplayLabelService $labels,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Build the Today urgency queue.
	 *
	 * When `$workspaceId` is set, every source is restricted to that book
	 * (product §3.3 / §21.1 — never blend workspaces). Tickets without a
	 * company in the workspace are dropped (no ambiguous cross-book rows).
	 *
	 * @return array{items: list<array<string, mixed>>, meta: array{truncated: bool, total: int}}
	 */
	public function build(?\DateTimeInterface $today = null, ?int $workspaceId = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$wsFilter = ($workspaceId !== null && $workspaceId > 0) ? $workspaceId : null;
		$items = [];
		/** @var array<int, string> $companyNames */
		$companyNames = [];

		// Windowed, soonest-first — never starve near expiries behind low ids.
		$floor = DateMath::addDays($today, -14);
		$horizon = DateMath::addDays($today, 60);
		foreach ($this->agreementMapper->findTodayRenewalCandidates($floor, $horizon, 2000, $wsFilter) as $agreement) {
			$days = $this->statusService->daysUntilExpiry($agreement->getValidUntil(), $today);
			if ($days === null || $days > 60 || $days < -14) {
				continue;
			}
			$companyId = (int)$agreement->getCompanyId();
			$urgency = $days <= 7 ? 10 : ($days <= 30 ? 15 : 18);
			$items[] = [
				'kind' => 'agreement_expiry',
				'kindLabel' => $this->labels->todayKind('agreement_expiry'),
				'urgency' => $urgency,
				'companyId' => $companyId,
				'companyName' => $this->resolveCompanyName($companyId, $companyNames),
				'agreementId' => (int)$agreement->getId(),
				'label' => (string)$agreement->getLabel(),
				'dueDate' => $agreement->getValidUntil()?->format('Y-m-d'),
				'daysUntil' => $days,
				'inGrace' => $this->statusService->isInGrace($agreement->getValidUntil(), $today),
			];
		}

		if ($this->facades->ticketcheckAvailable()) {
			foreach ($this->facades->listWaitingTickets(50) as $ticket) {
				$companyId = TicketCompanyLink::resolveCompanyId($ticket, $this->companyMapper, $companyNames);
				// Workspace-scoped Today: skip tickets we cannot place in this book.
				if ($wsFilter !== null) {
					if ($companyId <= 0 || !$this->companyBelongsToWorkspace($companyId, $wsFilter, $companyNames)) {
						continue;
					}
				}
				$items[] = [
					'kind' => 'ticket_waiting',
					'kindLabel' => $this->labels->todayKind('ticket_waiting'),
					'urgency' => 20,
					'companyId' => $companyId > 0 ? $companyId : null,
					'companyName' => $companyId > 0
						? $this->resolveCompanyName($companyId, $companyNames)
						: '',
					'ticketId' => $ticket['id'] ?? null,
					'label' => (string)($ticket['subject'] ?? $this->l10n->t('Waiting ticket')),
					'deepLink' => $ticket['deepLink'] ?? null,
				];
			}
		}

		foreach ($this->activityMapper->findOpenDueUpTo(DateMath::toUtcMidnight($today), 500, $wsFilter) as $activity) {
			$due = $activity->getDueDate();
			$daysLate = $due !== null ? DateMath::daysBetween($due, $today) : 0;
			$companyId = (int)$activity->getCompanyId();
			$kind = $daysLate > 0 ? 'activity_overdue' : 'activity_due';
			$items[] = [
				'kind' => $kind,
				'kindLabel' => $this->labels->todayKind($kind),
				'urgency' => $daysLate > 0 ? 30 : 40,
				'companyId' => $companyId,
				'companyName' => $this->resolveCompanyName($companyId, $companyNames),
				'activityId' => (int)$activity->getId(),
				'updatedAt' => OptimisticLock::format($activity->getUpdatedAt()),
				'label' => (string)$activity->getTitle(),
				'dueDate' => $due?->format('Y-m-d'),
				'type' => (string)$activity->getType(),
			];
		}

		$staleDays = $this->dealService->staleDealDays();
		$staleCutoff = DateMath::addDays($today, -$staleDays);
		foreach ($this->dealMapper->findOpenStaleBefore($staleCutoff, 200, $wsFilter) as $deal) {
			// Double-check via DealService so settings/edge cases stay single-sourced.
			if (!$this->dealService->isStale($deal, $today)) {
				continue;
			}
			$companyId = (int)$deal->getCompanyId();
			$changed = $deal->getStageChangedAt();
			$items[] = [
				'kind' => 'deal_stale',
				'kindLabel' => $this->labels->todayKind('deal_stale'),
				'urgency' => 50,
				'companyId' => $companyId,
				'companyName' => $this->resolveCompanyName($companyId, $companyNames),
				'dealId' => (int)$deal->getId(),
				'label' => (string)$deal->getTitle(),
				'dueDate' => $changed?->format('Y-m-d'),
				'stage' => (string)$deal->getStage(),
			];
		}

		usort($items, static function (array $a, array $b): int {
			$u = ($a['urgency'] <=> $b['urgency']);
			if ($u !== 0) {
				return $u;
			}
			return strcmp((string)($a['dueDate'] ?? ''), (string)($b['dueDate'] ?? ''));
		});

		$total = count($items);
		$truncated = $total > Constants::TODAY_MAX_ITEMS;
		if ($truncated) {
			$items = array_slice($items, 0, Constants::TODAY_MAX_ITEMS);
		}

		return [
			'items' => array_values($items),
			'meta' => ['truncated' => $truncated, 'total' => $total],
		];
	}

	/**
	 * @param array<int, string> $cache
	 */
	private function companyBelongsToWorkspace(int $companyId, int $workspaceId, array &$cache): bool
	{
		if ($companyId <= 0 || $workspaceId <= 0) {
			return false;
		}
		try {
			$company = $this->companyMapper->findById($companyId);
			$cache[$companyId] = $this->labels->companyOrUnknown($company->getName());
			return (int)($company->getWorkspaceId() ?? 0) === $workspaceId;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @param array<int, string> $cache
	 */
	private function resolveCompanyName(int $companyId, array &$cache): string
	{
		if ($companyId <= 0) {
			return $this->labels->unknownCompany();
		}
		if (isset($cache[$companyId])) {
			return $cache[$companyId];
		}
		try {
			$company = $this->companyMapper->findById($companyId);
			$cache[$companyId] = $this->labels->companyOrUnknown($company->getName());
		} catch (\Throwable) {
			$cache[$companyId] = $this->labels->unknownCompany();
		}
		return $cache[$companyId];
	}
}
