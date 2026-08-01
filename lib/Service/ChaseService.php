<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * My Chase queue — product §11 / §13.4 (OOO covering).
 */
class ChaseService
{
	public function __construct(
		private readonly DealMapper $dealMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly CompanyMapper $companyMapper,
		private readonly DealService $dealService,
		private readonly WorkspaceService $workspaces,
		private readonly OooCoverService $oooCover,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * One-pass chase index for a workspace — keyed by deal owner uid.
	 * Unowned open chase deals land under '' (claimable).
	 *
	 * Used by Team Coaching so the board is O(deals) not O(members × deals).
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function chaseByOwnerForWorkspace(
		int $workspaceId,
		?\DateTimeInterface $today = null,
	): array {
		$today = $today ?? $this->timeFactory->getDateTime();
		$stuckChaseDays = max(1, (int)$this->workspaces->getSettings($workspaceId)['stuckChaseDays']);
		$deals = $this->dealMapper->search(['openOnly' => true, 'workspaceId' => $workspaceId], 500);
		$byOwner = [];
		foreach ($deals as $deal) {
			try {
				$company = $this->companyMapper->findById((int)$deal->getCompanyId());
			} catch (\Throwable) {
				continue;
			}
			// Defense in depth — mapper already scoped; keep filter if join drifts.
			if ((int)($company->getWorkspaceId() ?? 0) !== $workspaceId) {
				continue;
			}
			$owner = (string)($deal->getOwnerUserId() ?? '');
			$openDated = $this->hasOpenDatedActivity((int)$deal->getId());
			$healthy = ChaseLogic::isHealthy($openDated, $deal->getNextActionSkipUntil(), $today);
			$payload = [
				'stage' => (string)$deal->getStage(),
				'ownerUserId' => $deal->getOwnerUserId(),
				'leadSlaDueAt' => $deal->getLeadSlaDueAt(),
				'leadFirstTouchAt' => $deal->getLeadFirstTouchAt(),
				'expectedClose' => $deal->getExpectedClose(),
				'quoteSentOn' => $deal->getQuoteSentOn(),
				'stageChangedAt' => $deal->getStageChangedAt(),
				'healthy' => $healthy,
				'staleDays' => $this->dealService->staleDealDays(),
				'stuckChaseDays' => $stuckChaseDays,
			];
			$reasons = ChaseLogic::reasons($payload, $today);
			if ($reasons === []) {
				continue;
			}
			$row = $this->dealService->toArray($deal);
			$row['companyName'] = (string)$company->getName();
			$row['companySlug'] = (string)$company->getSlug();
			$row['chaseReasons'] = $reasons;
			$row['primaryReason'] = $reasons[0];
			$row['healthy'] = $healthy;
			$byOwner[$owner][] = $row;
		}

		return $byOwner;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function myChase(
		string $userId,
		int $workspaceId,
		?\DateTimeInterface $today = null,
		bool $includeOooCover = true,
	): array {
		$today = $today ?? $this->timeFactory->getDateTime();
		$mineOwners = $includeOooCover
			? $this->oooCover->mineOwnerIds($workspaceId, $userId, $today)
			: [trim($userId)];
		$byOwner = $this->chaseByOwnerForWorkspace($workspaceId, $today);
		$out = [];
		foreach ($mineOwners as $ownerUid) {
			foreach ($byOwner[$ownerUid] ?? [] as $row) {
				$owner = (string)($row['ownerUserId'] ?? '');
				$covering = $owner !== '' && $owner !== $userId;
				$row['covering'] = $covering;
				$row['coveringPrimary'] = $covering ? $owner : null;
				$row['coveringBadge'] = $covering
					? $this->l10n->t('Covering')
					: null;
				$out[] = $row;
			}
		}
		// Unowned claimable chase (owner '') — only on the personal queue when includeOooCover
		// semantics treat them as mine; match prior behaviour: empty owner always included.
		if ($includeOooCover || in_array(trim($userId), $mineOwners, true)) {
			foreach ($byOwner[''] ?? [] as $row) {
				$row['covering'] = false;
				$row['coveringPrimary'] = null;
				$row['coveringBadge'] = null;
				$out[] = $row;
			}
		}
		usort($out, static function (array $a, array $b): int {
			$order = array_flip(ChaseLogic::REASON_SEVERITY);
			$pa = $order[$a['primaryReason']] ?? 99;
			$pb = $order[$b['primaryReason']] ?? 99;
			if ($pa !== $pb) {
				return $pa <=> $pb;
			}
			return strcmp((string)($a['expectedClose'] ?? '9999'), (string)($b['expectedClose'] ?? '9999'));
		});
		return $out;
	}

	private function hasOpenDatedActivity(int $dealId): bool
	{
		foreach ($this->activityMapper->search(['dealId' => $dealId, 'openOnly' => true], 50) as $a) {
			if ($a->getDueDate() !== null) {
				return true;
			}
		}
		return false;
	}
}
