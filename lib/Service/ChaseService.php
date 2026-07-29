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
		private readonly OooCoverService $oooCover,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
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
		$deals = $this->dealMapper->search(['openOnly' => true], 500);
		$out = [];
		foreach ($deals as $deal) {
			$company = null;
			try {
				$company = $this->companyMapper->findById((int)$deal->getCompanyId());
			} catch (\Throwable) {
				continue;
			}
			if ((int)($company->getWorkspaceId() ?? 0) !== $workspaceId) {
				continue;
			}
			$owner = (string)($deal->getOwnerUserId() ?? '');
			// Mine: owned by user / covered primary, OR unowned (claimable).
			if ($owner !== '' && !in_array($owner, $mineOwners, true)) {
				continue;
			}
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
			$covering = $owner !== '' && $owner !== $userId;
			$row['covering'] = $covering;
			$row['coveringPrimary'] = $covering ? $owner : null;
			$row['coveringBadge'] = $covering
				? $this->l10n->t('Covering')
				: null;
			$out[] = $row;
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
