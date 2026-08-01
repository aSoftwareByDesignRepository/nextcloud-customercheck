<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Person;
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
		private readonly QuotaService $quota,
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
		$todayYmd = $today->format('Y-m-d');
		$company = $this->companies->get($companyId);
		$people = [];
		foreach ($this->personMapper->findByCompany($companyId) as $p) {
			$people[] = $this->personToCoverageRow($p);
		}
		$hasPartner = false;
		$partnerCode = null;
		$partnerHoursHot = false;
		$renewalHot = false;
		$horizon = (clone \DateTimeImmutable::createFromInterface($today))
			->modify('+' . (int)Constants::RENEWAL_WINDOW_DAYS . ' days')
			->format('Y-m-d');
		foreach ($this->agreementMapper->findByCompany($companyId) as $a) {
			$status = (string)$a->getStatus();
			if (in_array($status, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			if ((string)$a->getKind() === 'partner') {
				$hasPartner = true;
				$partnerCode = (string)$a->getProductCode();
				if ($this->quota->snapshot($a)['alert']) {
					$partnerHoursHot = true;
				}
			}
			$until = $a->getValidUntil();
			if ($until !== null) {
				$ymd = $until->format('Y-m-d');
				if ($ymd >= $todayYmd && $ymd <= $horizon) {
					$renewalHot = true;
				}
			}
		}
		$tier = CoverageLogic::resolveKamTier(
			(string)$company->getKamTier(),
			$company->isKamTierLocked(),
			$hasPartner,
		);
		$touchOverdue = false;
		$next = $company->getNextTouchOn();
		if ($next !== null && $next->format('Y-m-d') < $todayYmd) {
			$touchOverdue = true;
		}
		$promiseOverdue = $this->hasOpenPromiseOverdue($companyId, $todayYmd);
		$eval = CoverageLogic::evaluate(
			$tier,
			$people,
			$touchOverdue,
			$promiseOverdue,
			$partnerHoursHot,
			$renewalHot,
		);
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
		$companies = $this->companyMapper->search(['workspaceId' => $workspaceId], 500);
		if ($companies === []) {
			return [];
		}

		$companyIds = [];
		foreach ($companies as $c) {
			$companyIds[] = (int)$c->getId();
		}
		$planOwners = $this->planOwnersForCompanies($companyIds);
		$matched = [];
		foreach ($companies as $c) {
			$cid = (int)$c->getId();
			$owner = (string)($c->getAccountOwnerUserId() ?? '');
			$planOwner = $planOwners[$cid] ?? null;
			$matchedVia = null;
			if ($owner !== '' && in_array($owner, $mineOwners, true)) {
				$matchedVia = 'account';
			} elseif ($planOwner !== null && in_array($planOwner, $mineOwners, true)) {
				$matchedVia = 'plan';
			} else {
				continue;
			}
			$matched[] = [$c, $matchedVia, $owner, $planOwner];
		}
		if ($matched === []) {
			return [];
		}

		$matchedIds = [];
		$matchedCompanies = [];
		foreach ($matched as [$c]) {
			$cid = (int)$c->getId();
			$matchedIds[] = $cid;
			$matchedCompanies[] = $c;
		}
		$covById = $this->evaluateCompaniesBatch($matchedCompanies, $workspaceId);

		$out = [];
		foreach ($matched as [$c, $matchedVia, $owner, $planOwner]) {
			$cid = (int)$c->getId();
			$cov = $covById[$cid] ?? [
				'kamTier' => (string)$c->getKamTier(),
				'gap' => false,
				'covered' => false,
				'flags' => [],
				'flagLabels' => [],
				'nextTouchOn' => $c->getNextTouchOn()?->format('Y-m-d'),
			];
			$coveringPrimary = null;
			if ($matchedVia === 'account' && $owner !== $userId) {
				$coveringPrimary = $owner;
			} elseif ($matchedVia === 'plan' && $planOwner !== $userId) {
				$coveringPrimary = $planOwner;
			}
			$out[] = [
				'id' => $cid,
				'name' => (string)$c->getName(),
				'slug' => (string)$c->getSlug(),
				'status' => (string)$c->getStatus(),
				'kamTier' => $cov['kamTier'],
				'gap' => $cov['gap'],
				'covered' => $cov['covered'],
				'flags' => $cov['flags'],
				'flagLabels' => $cov['flagLabels'] ?? [],
				'nextTouchOn' => $cov['nextTouchOn'],
				'accountOwnerUserId' => $owner !== '' ? $owner : null,
				'covering' => $coveringPrimary !== null,
				'coveringPrimary' => $coveringPrimary,
				'coveringBadge' => $coveringPrimary !== null ? $this->l10n->t('Covering') : null,
			];
		}
		return $out;
	}

	/**
	 * One-pass coverage eval for many companies (My Accounts). Never N× forCompany.
	 *
	 * @param list<\OCA\CustomerCheck\Db\Company> $companies
	 * @return array<int, array<string, mixed>>
	 */
	private function evaluateCompaniesBatch(array $companies, int $workspaceId): array
	{
		$today = $this->timeFactory->getDateTime();
		$todayYmd = $today->format('Y-m-d');
		$horizon = (clone \DateTimeImmutable::createFromInterface($today))
			->modify('+' . (int)Constants::RENEWAL_WINDOW_DAYS . ' days')
			->format('Y-m-d');

		$companyIds = [];
		foreach ($companies as $c) {
			$companyIds[] = (int)$c->getId();
		}
		$peopleByCompany = $this->personMapper->findGroupedByCompanyIds($companyIds);
		$promiseOverdueIds = $this->openPromiseOverdueCompanyIds($companyIds, $todayYmd);

		$partnerByCompany = [];
		$partnerCodeByCompany = [];
		$renewalHotByCompany = [];
		$partnerAgreementIdsByCompany = [];
		foreach ($this->agreementMapper->search(['workspaceId' => $workspaceId], 2000) as $a) {
			$cid = (int)$a->getCompanyId();
			if (!in_array($cid, $companyIds, true)) {
				continue;
			}
			$status = (string)$a->getStatus();
			if (in_array($status, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			if ((string)$a->getKind() === 'partner') {
				$partnerByCompany[$cid] = true;
				$partnerCodeByCompany[$cid] = (string)$a->getProductCode();
				$partnerAgreementIdsByCompany[$cid][] = $a;
			}
			$until = $a->getValidUntil();
			if ($until !== null) {
				$ymd = $until->format('Y-m-d');
				if ($ymd >= $todayYmd && $ymd <= $horizon) {
					$renewalHotByCompany[$cid] = true;
				}
			}
		}

		$partnerHoursHot = [];
		foreach ($partnerAgreementIdsByCompany as $cid => $list) {
			foreach ($list as $a) {
				if ($this->quota->snapshot($a)['alert']) {
					$partnerHoursHot[$cid] = true;
					break;
				}
			}
		}

		$out = [];
		foreach ($companies as $c) {
			$cid = (int)$c->getId();
			$people = [];
			foreach ($peopleByCompany[$cid] ?? [] as $p) {
				$people[] = $this->personToCoverageRow($p);
			}
			$tier = CoverageLogic::resolveKamTier(
				(string)$c->getKamTier(),
				$c->isKamTierLocked(),
				isset($partnerByCompany[$cid]),
			);
			$next = $c->getNextTouchOn();
			$touchOverdue = $next !== null && $next->format('Y-m-d') < $todayYmd;
			$eval = CoverageLogic::evaluate(
				$tier,
				$people,
				$touchOverdue,
				isset($promiseOverdueIds[$cid]),
				isset($partnerHoursHot[$cid]),
				isset($renewalHotByCompany[$cid]),
			);
			$flagLabels = [];
			foreach ($eval['flags'] as $flag) {
				$flagLabels[] = $this->labels->coverageFlag((string)$flag);
			}
			$out[$cid] = [
				'covered' => $eval['covered'],
				'gap' => $eval['gap'],
				'missing' => $eval['missing'],
				'flags' => $eval['flags'],
				'flagLabels' => $flagLabels,
				'kamTier' => $tier,
				'nextTouchOn' => $next?->format('Y-m-d'),
				'touchIntervalDays' => (int)($c->getTouchIntervalDays()
					?? CoverageLogic::defaultTouchIntervalDays($tier, $partnerCodeByCompany[$cid] ?? null)),
			];
		}

		return $out;
	}

	/**
	 * Owner → count of key/partner accounts with buying-role Coverage gaps (§12.2 / §13.2).
	 * One pass over companies + people — never N× myAccounts / forCompany.
	 *
	 * @return array<string, int>
	 */
	public function gapCountsByAccountOwner(int $workspaceId): array
	{
		$companies = $this->companyMapper->search(['workspaceId' => $workspaceId], 500);
		if ($companies === []) {
			return [];
		}

		$companyIds = [];
		foreach ($companies as $c) {
			$companyIds[] = (int)$c->getId();
		}
		$peopleByCompany = $this->personMapper->findGroupedByCompanyIds($companyIds);

		$partnerCompanyIds = [];
		foreach ($this->agreementMapper->search(['workspaceId' => $workspaceId, 'kind' => 'partner'], 2000) as $a) {
			if (in_array((string)$a->getStatus(), Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			$partnerCompanyIds[(int)$a->getCompanyId()] = true;
		}

		$counts = [];
		foreach ($companies as $c) {
			$owner = (string)($c->getAccountOwnerUserId() ?? '');
			if ($owner === '') {
				continue;
			}
			$cid = (int)$c->getId();
			$people = [];
			foreach ($peopleByCompany[$cid] ?? [] as $p) {
				$people[] = $this->personToCoverageRow($p);
			}
			$tier = CoverageLogic::resolveKamTier(
				(string)$c->getKamTier(),
				$c->isKamTierLocked(),
				isset($partnerCompanyIds[$cid]),
			);
			$eval = CoverageLogic::evaluate($tier, $people, false);
			if (!$eval['gap']) {
				continue;
			}
			$counts[$owner] = ($counts[$owner] ?? 0) + 1;
		}

		return $counts;
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

	/** @return array{buying_role: ?string, status: string} */
	private function personToCoverageRow(Person $p): array
	{
		return [
			'buying_role' => $p->getBuyingRole(),
			'status' => method_exists($p, 'getStatus') ? (string)$p->getStatus() : 'active',
		];
	}

	private function hasOpenPromiseOverdue(int $companyId, string $todayYmd): bool
	{
		return isset($this->openPromiseOverdueCompanyIds([$companyId], $todayYmd)[$companyId]);
	}

	/**
	 * @param list<int> $companyIds
	 * @return array<int, true>
	 */
	private function openPromiseOverdueCompanyIds(array $companyIds, string $todayYmd): array
	{
		if ($companyIds === [] || !$this->db->tableExists('crm_cust_prm')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')
			->from('crm_cust_prm')
			->where($qb->expr()->in('company_id', $qb->createNamedParameter($companyIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->isNotNull('due_on'))
			->andWhere($qb->expr()->lt('due_on', $qb->createNamedParameter($todayYmd)))
			->groupBy('company_id');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[(int)$row['company_id']] = true;
		}
		$r->closeCursor();

		return $out;
	}

	/**
	 * @param list<int> $companyIds
	 * @return array<int, string> companyId → plan owner uid
	 */
	private function planOwnersForCompanies(array $companyIds): array
	{
		if ($companyIds === [] || !$this->db->tableExists('crm_acct_plan')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id', 'owner_user_id')
			->from('crm_acct_plan')
			->where($qb->expr()->in('company_id', $qb->createNamedParameter($companyIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$uid = trim((string)($row['owner_user_id'] ?? ''));
			if ($uid !== '') {
				$out[(int)$row['company_id']] = $uid;
			}
		}
		$r->closeCursor();

		return $out;
	}
}
