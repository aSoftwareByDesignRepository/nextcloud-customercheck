<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\FrontEndAssetService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Support\WorkspaceSwitchUrlBuilder;

trait PageShellTrait
{
	private const WIDE_SHELL_PAGE_IDS = [
		'today', 'pulse', 'brief', 'my-accounts', 'team', 'workload', 'strategy',
		'companies', 'company-detail', 'company-form', 'prepare-meeting', 'pipeline',
		'agreements', 'agreement-form', 'money', 'activities', 'mail-triage', 'import',
		'settings', 'app-settings', 'support-us',
	];

	protected function buildShellParams(string $pageId, string $pageTitle, string $pageHelp = '', string $shellWidth = 'standard'): array
	{
		if ($shellWidth === 'standard' && in_array($pageId, self::WIDE_SHELL_PAGE_IDS, true)) {
			$shellWidth = 'wide';
		}
		if ($pageId === 'access-denied') {
			$shellWidth = 'minimal';
		}
		$userId = '';
		try {
			$userId = $this->accessControl->currentUserIdOrEmpty();
		} catch (\Throwable) {}
		$isAdmin = $userId !== '' && $this->accessControl->isAppAdmin($userId);
		$workspaceName = '';
		$workspaceId = 0;
		$needsPicker = false;
		$workspaces = [];
		$roleLabel = $isAdmin ? $this->l10n->t('App admin') : $this->l10n->t('Member');
		$roleSlug = $isAdmin ? 'app-admin' : 'crm-user';
		$workspaceRole = $isAdmin ? 'manager' : 'viewer';
		$isAppAdminCtx = $isAdmin;
		if ($userId !== '' && isset($this->workspaceAccess)) {
			try {
				$ctx = $this->workspaceAccess->resolveForUser($userId);
				$workspaceId = (int)$ctx['workspace']->getId();
				$workspaceName = (string)$ctx['workspace']->getName();
				$needsPicker = !empty($ctx['needsPicker']);
				$isAppAdminCtx = !empty($ctx['isAppAdmin']) || $isAdmin;
				$workspaceRole = WorkspaceCapability::normalizeRole((string)($ctx['role'] ?? 'viewer'));
				$roleLabel = $this->shellRoleLabel($isAppAdminCtx, $workspaceRole);
				$roleSlug = $isAppAdminCtx ? 'app-admin' : $workspaceRole;
				$workspaces = $this->workspaceAccess->listVisibleSummaries($userId);
			} catch (\Throwable) {
			}
		}
		$canWrite = WorkspaceCapability::allows($workspaceRole, 'write', $isAppAdminCtx);
		$showTeamNav = WorkspaceCapability::canOpenTeamBoard($workspaceRole, $isAppAdminCtx);
		$locale = 'en-US';
		if ($userId !== '') {
			try {
				$user = \OC::$server->get(\OCP\IUserSession::class)->getUser();
				if ($user !== null) {
					$locale = $this->canonicalHtmlLang((string)$this->l10nFactory->getUserLanguage($user));
				}
			} catch (\Throwable) {
			}
		}
		$urls = $this->buildShellUrls();
		$requestUri = isset($this->request) ? (string)$this->request->getRequestUri() : '';
		return [
			'pageId' => $pageId,
			'pageTitle' => $pageTitle,
			'pageHelp' => $pageHelp,
			'quickstart' => (new \OCA\CustomerCheck\Service\PageQuickStartCatalog($this->l10n, $this->urlGenerator))->forPage($pageId),
			'shellWidth' => $shellWidth,
			'roleLabel' => $roleLabel,
			'roleSlug' => $roleSlug,
			'workspaceRole' => $workspaceRole,
			'workspaceId' => $workspaceId,
			'workspaceName' => $workspaceName,
			'workspaces' => $workspaces,
			'needsPicker' => $needsPicker,
			'workspaceSwitchUrls' => WorkspaceSwitchUrlBuilder::buildMap($pageId, $urls, $workspaces, $requestUri),
			'clientHints' => [
				'locale' => $locale,
				'htmlLang' => $locale,
				'timezone' => date_default_timezone_get() ?: 'UTC',
			],
			'urls' => $urls,
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'labels' => $this->labels,
			'canManage' => $isAdmin,
			'canWrite' => $canWrite,
			'showTeamNav' => $showTeamNav,
			'showToolsNav' => true,
		];
	}

	private function shellRoleLabel(bool $isAppAdmin, string $workspaceRole): string
	{
		if ($isAppAdmin) {
			return $this->l10n->t('App admin');
		}

		return match ($workspaceRole) {
			'manager' => $this->l10n->t('Manager'),
			'member' => $this->l10n->t('Member'),
			'viewer' => $this->l10n->t('Viewer'),
			default => $this->l10n->t('Member'),
		};
	}

	private function canonicalHtmlLang(string $locale): string
	{
		$locale = strtolower(trim(str_replace('_', '-', $locale)));
		if ($locale === '') {
			return 'en-US';
		}
		if (preg_match('/^[a-z]{2}-[a-z]{2}$/', $locale) === 1) {
			[$lang, $region] = explode('-', $locale, 2);
			return strtolower($lang) . '-' . strtoupper($region);
		}

		return match ($locale) {
			'de' => 'de-DE',
			'en' => 'en-US',
			default => 'en-US',
		};
	}

	/**
	 * @param bool $preserveHelp When true, keep the passed $help (e.g. dynamic entity label).
	 *                           Otherwise PageHelpCatalog is authoritative so provenance stays visible
	 *                           even after Quick start tips are dismissed.
	 */
	protected function mergeShellPageParams(string $pageId, string $title, string $help = '', array $pageData = [], bool $preserveHelp = false): array
	{
		if (!$preserveHelp) {
			$catalogHelp = (new \OCA\CustomerCheck\Service\PageHelpCatalog($this->l10n))->forPage($pageId);
			if ($catalogHelp !== null && $catalogHelp !== '') {
				$help = $catalogHelp;
			}
		}

		return array_merge($this->buildShellParams($pageId, $title, $help), $pageData);
	}

	protected function registerFrontEndAssets(string $pageScript = '', ?string $pageStyle = null, array $extraStyles = [], array $extraScripts = []): void
	{
		FrontEndAssetService::registerPage($pageScript, $pageStyle, $extraStyles, $extraScripts);
	}

	/** @return array<string,string> */
	protected function buildShellUrls(): array
	{
		$g = $this->urlGenerator;
		return [
			'today' => $g->linkToRoute('customercheck.page.today'),
			'pulse' => $g->linkToRoute('customercheck.page.pulse'),
			'brief' => $g->linkToRoute('customercheck.page.brief'),
			'myAccounts' => $g->linkToRoute('customercheck.page.myAccounts'),
			'team' => $g->linkToRoute('customercheck.page.team'),
			'workload' => $g->linkToRoute('customercheck.page.workload'),
			'strategy' => $g->linkToRoute('customercheck.page.strategy'),
			'companies' => $g->linkToRoute('customercheck.page.companies'),
			'companyNew' => $g->linkToRoute('customercheck.page.companyNew'),
			'pipeline' => $g->linkToRoute('customercheck.page.pipeline'),
			'agreements' => $g->linkToRoute('customercheck.page.agreements'),
			'money' => $g->linkToRoute('customercheck.page.money'),
			'activities' => $g->linkToRoute('customercheck.page.activities'),
			'mailTriage' => $g->linkToRoute('customercheck.page.mailTriage'),
			'import' => $g->linkToRoute('customercheck.page.import'),
			'settings' => $g->linkToRoute('customercheck.page.settings'),
			'appSettings' => $g->linkToRoute('customercheck.page.appSettings'),
			'supportUs' => $g->linkToRoute('customercheck.page.supportUs'),
			'apiBase' => $g->linkToRoute('customercheck.today_api.index'),
		];
	}
}
