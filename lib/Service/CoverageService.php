<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Account coverage + My Accounts + touched — product §12.
 */
class CoverageService
{
	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly PersonMapper $personMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly CompanyService $companies,
		private readonly OooCoverService $oooCover,
		private readonly DisplayLabelService $labels,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array{
	 *   covered: bool,
	 *   gap: bool,
	 *   missing: list<string>,
	 *   missingLabels: list<string>,
	 *   flags: list<string>,
	 *   flagLabels: list<string>,
	 *   kamTier: string,
	 *   touchIntervalDays?: int,
	 *   nextTouchOn?: ?string
	 * }
	 */
	public function forCompany(int $companyId, ?\DateTimeInterface $today = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$company = $this->companies->get($companyId);
		$people = [];
		foreach ($this->personMapper->findByCompany($companyId) as $p) {
			$people[] = [
				'buying_role' => $p->getBuyingRole(),
				'status' => method_exists($p, 'getStatus') ? (string)$p->getStatus() : 'active',
			];
		}
		$hasPartner = false;
		$partnerCode = null;
		foreach ($this->agreementMapper->findByCompany($companyId) as $a) {
			if ((string)$a->getKind() === 'partner' && !in_array((string)$a->getStatus(), Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				$hasPartner = true;
				$partnerCode = (string)$a->getProductCode();
				break;
			}
		}
		$tier = CoverageLogic::resolveKamTier(
			(string)$company->getKamTier(),
			$company->isKamTierLocked(),
			$hasPartner,
		);
		$touchOverdue = false;
		$next = $company->getNextTouchOn();
		if ($next !== null && $next->format('Y-m-d') < $today->format('Y-m-d')) {
			$touchOverdue = true;
		}
		$eval = CoverageLogic::evaluate($tier, $people, $touchOverdue);
		$eval['kamTier'] = $tier;
		$eval['touchIntervalDays'] = (int)($company->getTouchIntervalDays()
			?? CoverageLogic::defaultTouchIntervalDays($tier, $partnerCode));
		$eval['nextTouchOn'] = $next?->format('Y-m-d');
		$missingLabels = [];
		foreach ($eval['missing'] as $slot) {
			$missingLabels[] = $this->labels->coverageMissing((string)$slot);
		}
		$flagLabels = [];
		foreach ($eval['flags'] as $flag) {
			$flagLabels[] = $this->labels->coverageFlag((string)$flag);
		}
		$eval['missingLabels'] = $missingLabels;
		$eval['flagLabels'] = $flagLabels;
		return $eval;
	}

	/**
	 * @param bool $includeOooCover when false (Team board), only direct ownership — never inflate stuck via cover
	 * @return list<array<string, mixed>>
	 */
	public function myAccounts(string $userId, int $workspaceId, bool $includeOooCover = true): array
	{
		$mineOwners = $includeOooCover
			? $this->oooCover->mineOwnerIds($workspaceId, $userId)
			: [trim($userId)];
		$out = [];
		foreach ($this->companyMapper->search(['workspaceId' => $workspaceId], 500) as $c) {
			$owner = (string)($c->getAccountOwnerUserId() ?? '');
			$planOwner = $this->planOwnerForCompany((int)$c->getId());
			$matchedVia = null;
			if ($owner !== '' && in_array($owner, $mineOwners, true)) {
				$matchedVia = 'account';
			} elseif ($planOwner !== null && in_array($planOwner, $mineOwners, true)) {
				$matchedVia = 'plan';
			} else {
				continue;
			}
			$cov = $this->forCompany((int)$c->getId());
			$coveringPrimary = null;
			if ($matchedVia === 'account' && $owner !== $userId) {
				$coveringPrimary = $owner;
			} elseif ($matchedVia === 'plan' && $planOwner !== $userId) {
				$coveringPrimary = $planOwner;
			}
			$out[] = [
				'id' => (int)$c->getId(),
				'name' => (string)$c->getName(),
				'slug' => (string)$c->getSlug(),
				'status' => (string)$c->getStatus(),
				'kamTier' => $cov['kamTier'],
				'gap' => $cov['gap'],
				'covered' => $cov['covered'],
				'flags' => $cov['flags'],
				'nextTouchOn' => $cov['nextTouchOn'],
				'accountOwnerUserId' => $owner !== '' ? $owner : null,
				'covering' => $coveringPrimary !== null,
				'coveringPrimary' => $coveringPrimary,
				'coveringBadge' => $coveringPrimary !== null ? $this->l10n->t('Covering') : null,
			];
		}
		return $out;
	}

	public function markTouched(int $companyId, string $actor): void
	{
		$company = $this->companies->get($companyId);
		$cov = $this->forCompany($companyId);
		$interval = max(1, (int)$cov['touchIntervalDays']);
		$today = $this->timeFactory->getDateTime();
		$next = DateMath::addDays($today, $interval);
		$company->setNextTouchOn(new \DateTime($next->format('Y-m-d')));
		if ($company->getTouchIntervalDays() === null) {
			$company->setTouchIntervalDays($interval);
		}
		$company->setUpdatedAt($today);
		$this->companyMapper->update($company);
		unset($actor);
	}

	public function assertSingleEconomicBuyer(int $companyId, ?string $buyingRole, ?int $excludePersonId = null): void
	{
		if ($buyingRole !== 'economic_buyer') {
			return;
		}
		foreach ($this->personMapper->findByCompany($companyId) as $p) {
			if ($excludePersonId !== null && (int)$p->getId() === $excludePersonId) {
				continue;
			}
			if ((string)($p->getBuyingRole() ?? '') === 'economic_buyer'
				&& (string)($p->getStatus() ?? 'active') === 'active') {
				throw ValidationException::field(
					Constants::ERR_BUYER_DUP,
					'buyingRole',
					$this->l10n->t('This company already has an active budget decision-maker.'),
				);
			}
		}
	}

	private function planOwnerForCompany(int $companyId): ?string
	{
		if (!$this->db->tableExists('crm_acct_plan')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner_user_id')
			->from('crm_acct_plan')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		$uid = trim((string)($row['owner_user_id'] ?? ''));
		return $uid !== '' ? $uid : null;
	}
}
