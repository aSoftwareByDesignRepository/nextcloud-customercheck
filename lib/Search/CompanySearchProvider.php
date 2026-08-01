<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Search;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AppIconService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search over company name + slug — product §3.3 / §16.
 *
 * Results are scoped to workspaces the user may access (never cross-book).
 */
class CompanySearchProvider implements IProvider
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly CompanyService $companyService,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly AppIconService $appIconService,
	) {
	}

	public function getId(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10n->t('CustomerCheck companies');
	}

	public function getOrder(string $route, array $routeParameters): int
	{
		return 25;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult
	{
		$uid = $user->getUID();
		if (!$this->accessControl->canUseApp($uid)) {
			return SearchResult::complete($this->getName(), []);
		}

		$term = trim($query->getTerm());
		if (mb_strlen($term) < 2) {
			return SearchResult::complete($this->getName(), []);
		}

		$workspaceIds = $this->workspaces->listAccessibleWorkspaceIds($uid);
		if ($workspaceIds === []) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = max(1, min(25, $query->getLimit()));
		$companies = $this->companyService->search([
			'search' => $term,
			'workspaceIds' => $workspaceIds,
		], $limit, 0);
		$entries = [];
		$icon = $this->appIconService->surfaceIconPath();

		foreach ($companies as $company) {
			$id = (int)$company->getId();
			$entries[] = new SearchResultEntry(
				$icon,
				(string)$company->getName(),
				(string)$company->getSlug(),
				$this->urlGenerator->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $id]),
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}
}
