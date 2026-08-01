<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Search;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AppIconService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search over contact name + email — product §3.3 / §16.
 *
 * Results are scoped to workspaces the user may access (never cross-book).
 */
class PersonSearchProvider implements IProvider
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly PersonService $personService,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly AppIconService $appIconService,
	) {
	}

	public function getId(): string
	{
		return Application::APP_ID . '-persons';
	}

	public function getName(): string
	{
		return $this->l10n->t('CustomerCheck contacts');
	}

	public function getOrder(string $route, array $routeParameters): int
	{
		return 26;
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
		$persons = $this->personService->search([
			'search' => $term,
			'workspaceIds' => $workspaceIds,
		], $limit, 0);
		$entries = [];
		$icon = $this->appIconService->surfaceIconPath();

		foreach ($persons as $person) {
			$companyId = (int)$person->getCompanyId();
			$subline = (string)($person->getEmail() ?? $person->getRole() ?? '');
			$entries[] = new SearchResultEntry(
				$icon,
				(string)$person->getName(),
				$subline,
				$this->urlGenerator->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId]),
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}
}
