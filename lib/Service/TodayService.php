<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Today urgency queue — PSD §10.3 / §14.5.
 */
class TodayService
{
	public function __construct(
		private readonly AgreementMapper $agreementMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly CompanyMapper $companyMapper,
		private readonly FacadeProbeService $facades,
		private readonly AgreementStatusService $statusService,
		private readonly DisplayLabelService $labels,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array{items: list<array<string, mixed>>, meta: array{truncated: bool, total: int}}
	 */
	public function build(?\DateTimeInterface $today = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$items = [];
		/** @var array<int, string> $companyNames */
		$companyNames = [];

		foreach ($this->agreementMapper->findRenewalCandidates(2000, 0) as $agreement) {
			$days = $this->statusService->daysUntilExpiry($agreement->getValidUntil(), $today);
			if ($days === null || $days > 60) {
				continue;
			}
			$companyId = (int)$agreement->getCompanyId();
			$urgency = $days <= 7 ? 10 : ($days <= 30 ? 15 : 18);
			$items[] = [
				'kind' => 'agreement_expiry',
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
				$companyId = isset($ticket['companyId']) ? (int)$ticket['companyId'] : 0;
				$tcCustomerId = (int)($ticket['customerId'] ?? 0);
				if ($companyId <= 0 && $tcCustomerId > 0) {
					$linked = $this->companyMapper->findByTcCustomerId($tcCustomerId);
					$companyId = $linked !== null ? (int)$linked->getId() : 0;
					if ($linked !== null) {
						$companyNames[$companyId] = (string)$linked->getName();
					}
				}
				$items[] = [
					'kind' => 'ticket_waiting',
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

		foreach ($this->activityMapper->findOpenDueUpTo(DateMath::toUtcMidnight($today), 500) as $activity) {
			$due = $activity->getDueDate();
			$daysLate = $due !== null ? DateMath::daysBetween($due, $today) : 0;
			$companyId = (int)$activity->getCompanyId();
			$items[] = [
				'kind' => $daysLate > 0 ? 'activity_overdue' : 'activity_due',
				'urgency' => $daysLate > 0 ? 30 : 40,
				'companyId' => $companyId,
				'companyName' => $this->resolveCompanyName($companyId, $companyNames),
				'activityId' => (int)$activity->getId(),
				'label' => (string)$activity->getTitle(),
				'dueDate' => $due?->format('Y-m-d'),
				'type' => (string)$activity->getType(),
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
