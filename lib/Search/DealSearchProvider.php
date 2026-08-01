<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Search;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AppIconService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search over deal titles — product §3.3 / §16.
 *
 * Results are scoped to workspaces the user may access (never cross-book).
 * Stage sublines use plain-language labels (§19).
 */
class DealSearchProvider implements IProvider
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly DealService $dealService,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
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
		$deals = $this->dealService->search([
			'search' => $term,
			'workspaceIds' => $workspaceIds,
		], $limit, 0);
		$entries = [];
		$icon = $this->appIconService->surfaceIconPath();

		foreach ($deals as $deal) {
			$companyId = (int)$deal->getCompanyId();
			$entries[] = new SearchResultEntry(
				$icon,
				(string)$deal->getTitle(),
				$this->stageLabel((string)$deal->getStage()),
				$this->urlGenerator->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId]),
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}

	private function stageLabel(string $stage): string
	{
		return match ($stage) {
			'lead' => $this->l10n->t('Lead'),
			'contacted' => $this->l10n->t('Contacted'),
			'quoted' => $this->l10n->t('Quoted'),
			'negotiation' => $this->l10n->t('Negotiation'),
			'won' => $this->l10n->t('Won'),
			'lost' => $this->l10n->t('Lost'),
			default => $stage !== '' && in_array($stage, Constants::DEAL_STAGES, true)
				? $stage
				: $this->l10n->t('Deal'),
		};
	}
}
