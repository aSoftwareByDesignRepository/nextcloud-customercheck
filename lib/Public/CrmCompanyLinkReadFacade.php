<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Public;

use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Service\AccessControlService;
use OCP\IURLGenerator;

/**
 * Reverse discoverability for ProjectCheck (SHARED-IDENTITY MH-13 / AC-C-15).
 *
 * Server-side only. Never writes PC tables. Never opens cross-app TX.
 */
class CrmCompanyLinkReadFacade
{
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly AccessControlService $accessControl,
		private readonly IURLGenerator $urlGenerator,
		private readonly ?\OCA\CustomerCheck\Service\WorkspaceAccessService $workspaceAccess = null,
		private readonly ?\Psr\Log\LoggerInterface $logger = null,
	) {
	}

	/**
	 * Find CRM company linked to a ProjectCheck customer id.
	 *
	 * @return array{crmCompanyId:int,name:string,slug:string,deepLink:string,pcCustomerId:?int}|null
	 */
	public function findByPcCustomerId(string $actorUid, int $pcCustomerId): ?array
	{
		if ($pcCustomerId <= 0 || $actorUid === '') {
			return null;
		}
		// Actor must be able to open CustomerCheck; reverse link is read-only metadata.
		if (!$this->accessControl->canUseApp($actorUid)) {
			return null;
		}
		$company = $this->scopedCompanyOrNull($actorUid, fn () => $this->companyMapper->findByPcCustomerId($pcCustomerId));
		if ($company === null) {
			return null;
		}

		return $this->serializeCompany($company);
	}

	/**
	 * Read CRM company link ids for MaintenanceCheck AS-05 consistency checks.
	 *
	 * @return array{crmCompanyId:int,name:string,slug:string,deepLink:string,pcCustomerId:?int}|null
	 */
	public function findByCompanyId(string $actorUid, int $companyId): ?array
	{
		if ($companyId <= 0 || $actorUid === '' || !$this->accessControl->canUseApp($actorUid)) {
			return null;
		}
		$company = $this->scopedCompanyOrNull($actorUid, fn () => $this->companyMapper->findById($companyId));
		if ($company === null) {
			return null;
		}

		return $this->serializeCompany($company);
	}

	/**
	 * Picker search for sibling apps (F14): name substring match scoped to
	 * the actor's accessible workspaces — never scans foreign books.
	 * Returns minimal link metadata; archived companies are excluded.
	 *
	 * @return list<array{crmCompanyId:int,name:string,deepLink:string}>
	 */
	public function searchForPicker(string $actorUid, string $query, int $limit = 10): array
	{
		$query = trim($query);
		if ($actorUid === '' || $query === '' || !$this->accessControl->canUseApp($actorUid)) {
			return [];
		}
		$workspaceIds = $this->workspaceAccess !== null
			? $this->workspaceAccess->listAccessibleWorkspaceIds($actorUid)
			: [];
		if ($workspaceIds === []) {
			return [];
		}
		$limit = max(1, min(25, $limit));
		try {
			$rows = $this->companyMapper->search([
				'search' => $query,
				'archived' => false,
				'workspaceIds' => $workspaceIds,
			], $limit);
		} catch (\Throwable $e) {
			$this->logger?->warning('searchForPicker failed for actor ' . $actorUid, ['exception' => $e]);
			return [];
		}
		$out = [];
		foreach ($rows as $company) {
			$id = (int)$company->getId();
			$ws = (int)($company->getWorkspaceId() ?? 0);
			$deepLink = $this->urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $id]);
			if ($ws > 0) {
				$deepLink .= (str_contains($deepLink, '?') ? '&' : '?') . 'workspaceId=' . $ws;
			}
			$out[] = [
				'crmCompanyId' => $id,
				'name' => (string)$company->getName(),
				'deepLink' => $deepLink,
			];
		}
		return $out;
	}

	/**
	 * Fetch a company and verify the actor can reach its workspace.
	 *
	 * Fails closed on every leg: no WorkspaceAccessService wired (scope cannot
	 * be verified), no accessible workspaces, mapper error, archived company,
	 * or a company whose workspace the actor cannot see. Without the
	 * workspace leg an app-access-only actor could resolve names/slugs/deep
	 * links of foreign-workspace companies by guessing pc_customer_id /
	 * company ids — an oracle for workspace contents.
	 *
	 * @param \Closure():(?\OCA\CustomerCheck\Db\Company) $fetch
	 */
	private function scopedCompanyOrNull(string $actorUid, \Closure $fetch): ?\OCA\CustomerCheck\Db\Company
	{
		$workspaceIds = $this->workspaceAccess?->listAccessibleWorkspaceIds($actorUid);
		if ($workspaceIds === null || $workspaceIds === []) {
			return null;
		}
		try {
			$company = $fetch();
		} catch (\Throwable $e) {
			$this->logger?->warning('CrmCompanyLinkReadFacade lookup failed for actor ' . $actorUid, ['exception' => $e]);
			return null;
		}
		if ($company === null || $company->getArchivedAt() !== null) {
			return null;
		}
		if (!in_array((int)($company->getWorkspaceId() ?? 0), $workspaceIds, true)) {
			return null;
		}
		return $company;
	}

	/**
	 * @return array{crmCompanyId:int,name:string,slug:string,deepLink:string,pcCustomerId:?int}
	 */
	private function serializeCompany(\OCA\CustomerCheck\Db\Company $company): array
	{
		$id = (int)$company->getId();
		$ws = (int)($company->getWorkspaceId() ?? 0);
		$deepLink = $this->urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $id]);
		if ($ws > 0) {
			$deepLink .= (str_contains($deepLink, '?') ? '&' : '?') . 'workspaceId=' . $ws;
		}
		$pc = $company->getPcCustomerId();

		return [
			'crmCompanyId' => $id,
			'name' => (string)$company->getName(),
			'slug' => (string)$company->getSlug(),
			'deepLink' => $deepLink,
			'pcCustomerId' => $pc !== null && (int)$pc > 0 ? (int)$pc : null,
		];
	}
}
