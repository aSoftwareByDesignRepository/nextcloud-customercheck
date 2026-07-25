<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Search;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AppIconService;
use OCA\CustomerCheck\Service\DealService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search over deal titles — PSD §7.5 / S8 / S9.
 *
 * Non–CRM users receive an empty result set (never an error).
 */
class DealSearchProvider implements IProvider
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly DealService $dealService,
		private readonly AccessControlService $accessControl,
		private readonly AppIconService $appIconService,
	) {
	}

	public function getId(): string
	{
		return Application::APP_ID . '-deals';
	}

	public function getName(): string
	{
		return $this->l10n->t('CustomerCheck deals');
	}

	public function getOrder(string $route, array $routeParameters): int
	{
		return 27;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult
	{
		if (!$this->accessControl->canUseApp($user->getUID())) {
			return SearchResult::complete($this->getName(), []);
		}

		$term = trim($query->getTerm());
		if (mb_strlen($term) < 2) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = max(1, min(25, $query->getLimit()));
		$deals = $this->dealService->search(['search' => $term], $limit, 0);
		$entries = [];
		$icon = $this->appIconService->surfaceIconPath();

		foreach ($deals as $deal) {
			$companyId = (int)$deal->getCompanyId();
			$entries[] = new SearchResultEntry(
				$icon,
				(string)$deal->getTitle(),
				(string)$deal->getStage(),
				$this->urlGenerator->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId]),
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}
}
