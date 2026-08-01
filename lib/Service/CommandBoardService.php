<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\TicketCompanyLink;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Today Command Board — product §8.
 */
class CommandBoardService
{
	public function __construct(
		private readonly TodayService $todayService,
		private readonly ActivityMapper $activityMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly CompanyMapper $companyMapper,
		private readonly DealMapper $dealMapper,
		private readonly DealService $dealService,
		private readonly FacadeProbeService $facades,
		private readonly AgreementStatusService $statusService,
		private readonly CallbackService $callbacks,
		private readonly TasksDeepLinkBuilder $tasksLinks,
		private readonly ChaseService $chase,
		private readonly PromiseService $promises,
		private readonly BriefService $brief,
		private readonly MailboxService $mailbox,
		private readonly CoachingService $coaching,
		private readonly CoverageService $coverage,
		private readonly AccountPlanService $plans,
		private readonly OooCoverService $oooCover,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly DisplayLabelService $labels,
	) {
	}

	/**
	 * @return array{
	 *   sections: list<array{id: string, title: string, items: list<array<string, mixed>>}>,
	 *   meta: array{empty: bool}
	 * }
	 */
	public function build(?\DateTimeInterface $today = null, ?string $userId = null, ?int $workspaceId = null, string $role = 'member', bool $isAppAdmin = false): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$userId = $userId ?? '';
		$workspaceId = $workspaceId ?? 0;
		$companyNames = [];

		$sections = [];

		if ($workspaceId > 0 && $userId !== '') {
			$chaseRows = $this->chase->myChase($userId, $workspaceId, $today);
			$labels = $this->labels;
			$sections[] = [
				'id' => 'my_chase',
				'title' => $this->l10n->t('My Chase'),
				'items' => array_map(static function (array $d) use ($labels): array {
					$reasonCode = (string)($d['primaryReason'] ?? '');
					$reason = $reasonCode !== '' ? $labels->chaseReason($reasonCode) : '';
					$owner = trim((string)($d['ownerUserId'] ?? ''));
					return [
						'companyId' => $d['companyId'],
						'companyName' => $d['companyName'] ?? '',
						'why' => trim((string)($d['title'] ?? '')),
						'reasonLabel' => $reason,
						'dueDate' => $d['expectedClose'] ?? $d['quoteSentOn'] ?? null,
						// Unowned webhook/manual leads → Claim (§18.7); owned → Open.
						'cta' => $owner === '' ? 'claim' : 'open',
						'dealId' => $d['id'],
						'updatedAt' => $d['updatedAt'] ?? null,
						'covering' => !empty($d['covering']),
						'coveringPrimary' => $d['coveringPrimary'] ?? null,
						'coveringBadge' => $d['coveringBadge'] ?? null,
					];
				}, array_slice($chaseRows, 0, 25)),
			];

			try {
				$brief = $this->brief->build($workspaceId, $today);
				$mineOwners = $this->oooCover->mineOwnerIds($workspaceId, $userId, $today);
				$mine = array_values(array_filter(
					$brief['commitments'] ?? [],
					static fn (array $c): bool => ($c['status'] ?? '') === 'committed'
						&& in_array((string)($c['ownerUserId'] ?? ''), $mineOwners, true),
				));
				$sections[] = [
					'id' => 'ceo_commitments',
					'title' => $this->l10n->t('CEO commitments (this week)'),
					'items' => array_map(static function (array $c) use ($userId): array {
						$owner = (string)($c['ownerUserId'] ?? '');
						$covering = $owner !== '' && $owner !== $userId;
						return [
							'companyId' => $c['companyId'] ?? null,
							'companyName' => '',
							'why' => (string)$c['title'],
							'dueDate' => $c['dueOn'] ?? null,
							'cta' => 'open',
							'commitmentId' => $c['id'],
							'covering' => $covering,
							'coveringPrimary' => $covering ? $owner : null,
						];
					}, $mine),
				];
			} catch (\Throwable) {
				// Brief tables may not exist yet during upgrade
			}

			$touches = [];
			$mineOwners = $this->oooCover->mineOwnerIds($workspaceId, $userId, $today);
			foreach ($this->companyMapper->search(['workspaceId' => $workspaceId], 200) as $c) {
				$next = $c->getNextTouchOn();
				if ($next === null) {
					continue;
				}
				if ($next->format('Y-m-d') > $today->format('Y-m-d')) {
					continue;
				}
				$owner = (string)($c->getAccountOwnerUserId() ?? '');
				if ($owner === '' || !in_array($owner, $mineOwners, true)) {
					continue;
				}
				$covering = $owner !== $userId;
				$touches[] = [
					'companyId' => (int)$c->getId(),
					'companyName' => (string)$c->getName(),
					'why' => $this->l10n->t('Next touch due'),
					'dueDate' => $next->format('Y-m-d'),
					'cta' => 'open',
					'covering' => $covering,
					'coveringPrimary' => $covering ? $owner : null,
					'coveringBadge' => $covering ? $this->l10n->t('Covering') : null,
				];
			}
			$sections[] = [
				'id' => 'kam_touches',
				'title' => $this->l10n->t('KAM touches'),
				'items' => array_slice($touches, 0, 25),
			];

			$sections[] = [
				'id' => 'customer_promises',
				'title' => $this->l10n->t('Customer promises'),
				'items' => array_map(static fn (array $p): array => [
					'companyId' => $p['companyId'],
					'companyName' => '',
					'why' => (string)$p['title'],
					'dueDate' => $p['dueOn'] ?? null,
					'cta' => 'open',
					'promiseId' => $p['id'],
				], $this->promises->dueSoon($workspaceId, $today, 7)),
			];

			$planItems = [];
			try {
				foreach ($this->plans->listForWorkspace($workspaceId) as $plan) {
					$health = (string)($plan['health'] ?? '');
					$review = $plan['nextReviewOn'] ?? null;
					$dueReview = is_string($review) && $review !== '' && $review <= $today->format('Y-m-d');
					if ($health === 'stalled' || $health === 'at_risk' || $dueReview) {
						$healthLabel = $this->labels->planHealth($health !== '' ? $health : 'unknown');
						$goal = (string)($plan['goal'] ?? $this->l10n->t('Account plan'));
						$planItems[] = [
							'companyId' => $plan['companyId'] ?? null,
							'companyName' => (string)($plan['companyName'] ?? ''),
							'why' => $goal . ' · ' . $healthLabel,
							'dueDate' => $review,
							'cta' => 'open',
							'planId' => $plan['id'] ?? null,
						];
					}
				}
			} catch (\Throwable) {
			}
			$sections[] = [
				'id' => 'plans_review',
				'title' => $this->l10n->t('Plans to review / stalled'),
				'items' => array_slice($planItems, 0, 25),
			];

			$sections[] = [
				'id' => 'unmatched_mail',
				'title' => $this->l10n->t('Unmatched shared mail'),
				'items' => array_map(static fn (array $m): array => [
					'companyId' => null,
					'companyName' => '',
					'why' => (string)($m['subject'] ?: $m['from']),
					'dueDate' => $m['sentAt'] ?? null,
					'cta' => 'triage',
					'mailId' => $m['id'],
					'shared' => true,
				], $this->mailbox->unmatched($workspaceId, 25)),
			];

			if (WorkspaceCapability::canOpenTeamBoard($role, $isAppAdmin) && WorkspaceCapability::teamSeesAllRows($role, $isAppAdmin)) {
				try {
					$board = $this->coaching->board($workspaceId, $userId, $role, $isAppAdmin);
					$stuck = array_values(array_filter($board['rows'], static fn (array $r): bool => !empty($r['stuck'])));
					$sections[] = [
						'id' => 'team_stuck',
						'title' => $this->l10n->t('Team stuck'),
						'items' => array_map(function (array $r): array {
							$name = trim((string)($r['displayName'] ?? ''));
							if ($name === '') {
								$name = (string)($r['userId'] ?? '');
							}
							return [
								'companyId' => null,
								'companyName' => '',
								'why' => $this->l10n->t('%s · %d stuck · %d chase', [$name, (int)$r['stuckScore'], (int)$r['chase']]),
								'dueDate' => null,
								'cta' => 'coach',
								'userId' => $r['userId'],
							];
						}, array_slice($stuck, 0, 25)),
					];
				} catch (\Throwable) {
				}
			}
		}

		// Legacy urgency groups (renewals, invoices, activities, waiting, stale)
		$legacy = $this->legacySections($today, $companyNames, $workspaceId > 0 ? $workspaceId : null);
		$sections = array_merge($sections, $legacy);

		$visible = [];
		foreach ($sections as $sec) {
			if (($sec['items'] ?? []) === []) {
				continue;
			}
			$visible[] = $sec;
		}

		return [
			'sections' => $visible,
			'meta' => ['empty' => $visible === []],
		];
	}

	/**
	 * @param array<int, string> $companyNames
	 * @return list<array{id: string, title: string, items: list<array<string, mixed>>}>
	 */
	private function legacySections(\DateTimeInterface $today, array &$companyNames, ?int $workspaceId = null): array
	{
		$sections = [
			'overdue_callbacks' => [
				'id' => 'overdue_callbacks',
				'title' => $this->l10n->t('Overdue callbacks'),
				'items' => [],
			],
			'renewals_7' => [
				'id' => 'renewals_7',
				'title' => $this->l10n->t('Renewals within 7 days'),
				'items' => [],
			],
			'overdue_invoices' => [
				'id' => 'overdue_invoices',
				'title' => $this->l10n->t('Overdue invoices'),
				'items' => [],
			],
			'due_today' => [
				'id' => 'due_today',
				'title' => $this->l10n->t('Due today'),
				'items' => [],
			],
			'open_tasks_soon' => [
				'id' => 'open_tasks_soon',
				'title' => $this->l10n->t('Open tasks due soon'),
				'items' => [],
			],
			'waiting' => [
				'id' => 'waiting',
				'title' => $this->l10n->t('Waiting on us'),
				'items' => [],
			],
			'stale_deals' => [
				'id' => 'stale_deals',
				'title' => $this->l10n->t('Stale deals'),
				'items' => [],
			],
			'renewals_30' => [
				'id' => 'renewals_30',
				'title' => $this->l10n->t('Coming up (8–30 days)'),
				'items' => [],
			],
		];

		$todayYmd = $today->format('Y-m-d');
		$plus7 = DateMath::addDays($today, 7);
		$wsFilter = ($workspaceId !== null && $workspaceId > 0) ? $workspaceId : null;

		foreach ($this->activityMapper->findOpenDueUpTo($plus7, 500, $wsFilter) as $activity) {
			$due = $activity->getDueDate();
			$dueYmd = $due?->format('Y-m-d');
			$companyId = (int)$activity->getCompanyId();
			$row = [
				'companyId' => $companyId,
				'companyName' => $this->name($companyId, $companyNames),
				'why' => (string)$activity->getTitle(),
				'dueDate' => $dueYmd,
				'cta' => 'done',
				'activityId' => (int)$activity->getId(),
				'updatedAt' => $activity->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			];
			if ((int)($activity->getIsCallback() ?? 0) === 1 && $dueYmd !== null && $dueYmd < $todayYmd) {
				$sections['overdue_callbacks']['items'][] = $row;
			} elseif ($dueYmd === $todayYmd) {
				$sections['due_today']['items'][] = $row;
			} else {
				$sections['open_tasks_soon']['items'][] = $row;
			}
		}

		foreach ($this->agreementMapper->findRenewalCandidates(2000, 0, $wsFilter) as $agreement) {
			$days = $this->statusService->daysUntilExpiry($agreement->getValidUntil(), $today);
			if ($days === null || $days > 30) {
				continue;
			}
			$companyId = (int)$agreement->getCompanyId();
			$row = [
				'companyId' => $companyId,
				'companyName' => $this->name($companyId, $companyNames),
				'why' => (string)$agreement->getLabel(),
				'dueDate' => $agreement->getValidUntil()?->format('Y-m-d'),
				'cta' => 'open',
				'agreementId' => (int)$agreement->getId(),
				'daysUntil' => $days,
			];
			if ($days <= 7) {
				$sections['renewals_7']['items'][] = $row;
			} else {
				$sections['renewals_30']['items'][] = $row;
			}
		}

		if ($this->facades->invoicecheckAvailable()) {
			foreach ($this->facades->listOverdueInvoices(50) as $inv) {
				$companyId = isset($inv['companyId']) ? (int)$inv['companyId'] : 0;
				if ($wsFilter !== null && ($companyId <= 0 || !$this->companyInWorkspace($companyId, $wsFilter, $companyNames))) {
					continue;
				}
				$sections['overdue_invoices']['items'][] = [
					'companyId' => $companyId > 0 ? $companyId : null,
					'companyName' => $companyId > 0
						? $this->name($companyId, $companyNames)
						: (string)($inv['companyName'] ?? ''),
					'why' => (string)($inv['label'] ?? $inv['invoiceRef'] ?? 'Overdue'),
					'dueDate' => $inv['dueDate'] ?? null,
					'cta' => 'open',
					'amountCents' => $inv['amountCents'] ?? null,
				];
			}
		}

		if ($this->facades->ticketcheckAvailable()) {
			foreach ($this->facades->listWaitingTickets(50) as $ticket) {
				$companyId = TicketCompanyLink::resolveCompanyId($ticket, $this->companyMapper, $companyNames);
				if ($wsFilter !== null && ($companyId <= 0 || !$this->companyInWorkspace($companyId, $wsFilter, $companyNames))) {
					continue;
				}
				$sections['waiting']['items'][] = [
					'companyId' => $companyId > 0 ? $companyId : null,
					'companyName' => $companyId > 0 ? $this->name($companyId, $companyNames) : '',
					'why' => (string)($ticket['subject'] ?? $this->l10n->t('Waiting ticket')),
					'dueDate' => null,
					'cta' => 'open',
					'deepLink' => $ticket['deepLink'] ?? null,
					'ticketId' => $ticket['id'] ?? null,
				];
			}
		}

		$staleDays = $this->dealService->staleDealDays();
		$staleCutoff = DateMath::addDays($today, -$staleDays);
		foreach ($this->dealMapper->findOpenStaleBefore($staleCutoff, 200, $wsFilter) as $deal) {
			if (!$this->dealService->isStale($deal, $today)) {
				continue;
			}
			$companyId = (int)$deal->getCompanyId();
			$sections['stale_deals']['items'][] = [
				'companyId' => $companyId,
				'companyName' => $this->name($companyId, $companyNames),
				'why' => (string)$deal->getTitle(),
				'dueDate' => $deal->getStageChangedAt()?->format('Y-m-d'),
				'cta' => 'open',
				'dealId' => (int)$deal->getId(),
			];
		}

		return array_values($sections);
	}

	/** @param array<int, string> $companyNames */
	private function companyInWorkspace(int $companyId, int $workspaceId, array &$companyNames): bool
	{
		if ($companyId <= 0 || $workspaceId <= 0) {
			return false;
		}
		try {
			$c = $this->companyMapper->findById($companyId);
			if ((int)($c->getWorkspaceId() ?? 0) !== $workspaceId) {
				return false;
			}
			$companyNames[$companyId] = (string)$c->getName();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/** @param array<int, string> $companyNames */
	private function name(int $companyId, array &$companyNames): string
	{
		if (isset($companyNames[$companyId])) {
			return $companyNames[$companyId];
		}
		try {
			$c = $this->companyMapper->findById($companyId);
			$companyNames[$companyId] = (string)$c->getName();
		} catch (\Throwable) {
			$companyNames[$companyId] = '';
		}
		return $companyNames[$companyId];
	}
}
