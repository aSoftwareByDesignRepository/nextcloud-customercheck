<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Companies list health columns — COMMAND-CENTER-SPEC §9.4 / AC-CC-15.
 */
class CompanyHealthService
{
	public function __construct(
		private readonly AgreementMapper $agreements,
		private readonly ActivityMapper $activities,
		private readonly EmailTouchMapper $emailTouches,
		private readonly BillingIntegrityService $billing,
		private readonly FacadeProbeService $facades,
		private readonly AgreementStatusService $status,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param Company[] $companies
	 * @return array<int, array{
	 *   lastTouch: ?string,
	 *   nextExpiry: ?string,
	 *   moneyState: string,
	 *   openCallbacks: int,
	 *   inConversation: bool
	 * }>
	 */
	public function enrich(array $companies): array
	{
		$today = $this->timeFactory->getDateTime();
		$lookback = DateMath::addDays($today, -Constants::CONVERSATION_LOOKBACK_DAYS);
		$out = [];
		foreach ($companies as $company) {
			$id = (int)$company->getId();
			$out[$id] = [
				'lastTouch' => $this->lastTouch($id),
				'nextExpiry' => $this->nextExpiry($id, $today),
				'moneyState' => $this->moneyState($company),
				'openCallbacks' => $this->openCallbacks($id),
				'inConversation' => $this->inConversation($id, $lookback),
			];
		}

		return $out;
	}

	private function lastTouch(int $companyId): ?string
	{
		$best = null;
		foreach ($this->emailTouches->findByCompany($companyId, 1) as $touch) {
			$best = $touch->getOccurredAt()?->format(\DateTimeInterface::ATOM);
		}
		foreach ($this->activities->search(['companyId' => $companyId], 5) as $act) {
			$at = $act->getDoneAt() ?? $act->getCreatedAt();
			$iso = $at?->format(\DateTimeInterface::ATOM);
			if ($iso !== null && ($best === null || strcmp($iso, $best) > 0)) {
				$best = $iso;
			}
		}

		return $best;
	}

	private function nextExpiry(int $companyId, \DateTimeInterface $today): ?string
	{
		$bestDays = null;
		$best = null;
		foreach ($this->agreements->findByCompany($companyId) as $ag) {
			if (in_array((string)$ag->getStatus(), Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			$until = $ag->getValidUntil();
			if ($until === null) {
				continue;
			}
			$days = $this->status->daysUntilExpiry($until, $today);
			if ($days === null) {
				continue;
			}
			if ($bestDays === null || $days < $bestDays) {
				$bestDays = $days;
				$best = $until->format('Y-m-d');
			}
		}

		return $best;
	}

	private function moneyState(Company $company): string
	{
		$pcId = (int)($company->getPcCustomerId() ?? 0);
		$ic = $pcId > 0 && $this->facades->invoicecheckAvailable();
		$pc = $pcId > 0 && $this->facades->projectcheckAvailable();
		if (!$ic && !$pc) {
			return 'unknown';
		}
		$overdue = 0;
		$open = 0;
		if ($ic) {
			foreach ($this->facades->listInvoicesForPcCustomer($pcId, 100) as $row) {
				$bucket = $this->billing->classifyInvoiceRow($row);
				if ($bucket === 'overdue') {
					$overdue++;
				} elseif ($bucket === 'open') {
					$open++;
				}
			}
		}
		$r = $this->billing->evaluate([
			'pcLinked' => $pc || $ic,
			'icLinked' => $ic,
			'overdueCount' => $overdue,
			'openCount' => $open,
			'billableKnown' => false,
			'uninvoicedKnown' => false,
		]);

		return $r['state'];
	}

	private function openCallbacks(int $companyId): int
	{
		$n = 0;
		foreach ($this->activities->findOpenByCompany($companyId) as $act) {
			if ($act->isCallback() || (string)$act->getType() === 'call') {
				$n++;
			}
		}

		return $n;
	}

	private function inConversation(int $companyId, \DateTimeInterface $since): bool
	{
		foreach ($this->emailTouches->findByCompany($companyId, 20) as $touch) {
			$at = $touch->getOccurredAt();
			if ($at !== null && $at >= $since) {
				return true;
			}
		}
		foreach ($this->activities->findOpenByCompany($companyId) as $act) {
			if (in_array((string)$act->getType(), ['call', 'email', 'meeting'], true)) {
				return true;
			}
		}

		return false;
	}
}
