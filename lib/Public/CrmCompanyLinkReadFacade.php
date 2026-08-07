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
		$company = $this->companyMapper->findByPcCustomerId($pcCustomerId);
		if ($company === null || $company->getArchivedAt() !== null) {
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
		try {
			$company = $this->companyMapper->findById($companyId);
		} catch (\Throwable) {
			return null;
		}
		if ($company->getArchivedAt() !== null) {
			return null;
		}

		return $this->serializeCompany($company);
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
