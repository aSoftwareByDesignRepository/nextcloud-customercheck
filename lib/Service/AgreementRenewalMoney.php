<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Db\AgreementMapper;

/** Renewals with known € for Brief cash calendar. */
class AgreementRenewalMoney
{
	public function __construct(
		private readonly AgreementMapper $agreements,
		private readonly AgreementStatusService $status,
	) {
	}

	public function renewalsKnownCentsThisMonth(int $workspaceId, \DateTimeInterface $today): int
	{
		return $this->renewalsKnownCentsForMonth($workspaceId, $today);
	}

	public function renewalsKnownCentsForMonth(int $workspaceId, \DateTimeInterface $monthAnchor): int
	{
		$month = $monthAnchor->format('Y-m');
		$total = 0;
		foreach ($this->agreements->findRenewalCandidates(2000, 0, $workspaceId) as $a) {
			$until = $a->getValidUntil();
			if ($until === null || $until->format('Y-m') !== $month) {
				continue;
			}
			$cents = (int)$a->getAmountCents();
			if ($cents > 0) {
				$total += $cents;
			}
		}
		return $total;
	}
}
