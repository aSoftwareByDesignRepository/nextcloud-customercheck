<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Service\AppSettingsSectionCatalog;
use OCA\CustomerCheck\Service\FrontEndAssetService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceSettingsSectionCatalog;
use OCA\CustomerCheck\Support\ListFragmentRequest;
use OCA\CustomerCheck\Support\WorkspaceSwitchUrlBuilder;
use OCP\AppFramework\Http\TemplateResponse;

trait PageShellTrait
{
	private const WIDE_SHELL_PAGE_IDS = [
		'today', 'pulse', 'brief', 'my-accounts', 'team', 'workload', 'strategy',
		'companies', 'company-detail', 'company-form', 'prepare-meeting', 'pipeline',
		'agreements', 'agreement-form', 'money', 'activities', 'mail-triage', 'sales-inbox', 'review',
		'conflicts', 'import',
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
				$needsPicker = false;
				$isAppAdminCtx = !empty($ctx['isAppAdmin']) || $isAdmin;
				$workspaceRole = WorkspaceCapability::normalizeRole((string)($ctx['role'] ?? 'viewer'));
				$roleLabel = $this->shellRoleLabel($isAppAdminCtx, $workspaceRole);
				$roleSlug = $isAppAdminCtx ? 'app-admin' : $workspaceRole;
				$workspaces = \OCA\CustomerCheck\Constants::MULTI_WORKSPACE_ENABLED
					? $this->workspaceAccess->listVisibleSummaries($userId)
					: [];
			} catch (\Throwable) {
			}
		}
		$canWrite = WorkspaceCapability::allows($workspaceRole, 'write', $isAppAdminCtx);
		$canDelete = WorkspaceCapability::allows($workspaceRole, 'delete', $isAppAdminCtx);
		$canMove = WorkspaceCapability::allows($workspaceRole, 'move', $isAppAdminCtx);
		$canBriefConfirm = WorkspaceCapability::allows($workspaceRole, 'brief.confirm', $isAppAdminCtx);
		$canTeamCoach = WorkspaceCapability::allows($workspaceRole, 'team.coach', $isAppAdminCtx);
		$canBulk = WorkspaceCapability::allows($workspaceRole, 'bulk', $isAppAdminCtx);
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
		$wsQuery = $workspaceId > 0 ? ['workspaceId' => $workspaceId] : [];
		$canManageWorkspace = WorkspaceCapability::allows($workspaceRole, 'members', $isAppAdminCtx);
		$settingsSectionUrls = [];
		$wsCatalog = new WorkspaceSettingsSectionCatalog();
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $sectionId) {
			if (!$wsCatalog->isVisible($sectionId, $canManageWorkspace)) {
				continue;
			}
			$settingsSectionUrls[$sectionId] = $this->urlGenerator->linkToRoute(
				'customercheck.page.settingsSection',
				array_merge(['section' => $sectionId], $wsQuery),
			);
		}
		$appSettingsSectionUrls = [];
		if ($isAppAdminCtx) {
			foreach (AppSettingsSectionCatalog::SECTIONS as $sectionId) {
				$appSettingsSectionUrls[$sectionId] = $this->urlGenerator->linkToRoute(
					'customercheck.page.appSettingsSection',
					array_merge(['section' => $sectionId], $wsQuery),
				);
			}
		}
		$urls['settingsSections'] = $settingsSectionUrls;
		$urls['appSettingsSections'] = $appSettingsSectionUrls;
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
			'canManageWorkspace' => $canManageWorkspace,
			'canWrite' => $canWrite,
			'canDelete' => $canDelete,
			'canMove' => $canMove,
			'canBriefConfirm' => $canBriefConfirm,
			'canTeamCoach' => $canTeamCoach,
			'canBulk' => $canBulk,
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

	protected function wantsListFragment(): bool
	{
		return ListFragmentRequest::wants($this->request ?? null);
	}

	protected function registerListPageAssets(string $pageScript): void
	{
		if ($this->wantsListFragment()) {
			return;
		}
		$this->registerFrontEndAssets($pageScript);
	}

	/**
	 * Island params: same ACL (canWrite/canDelete) as the full page, without
	 * nav catalogs, workspace switcher maps, or settings URL grids.
	 *
	 * @return array<string, mixed>
	 */
	protected function buildFragmentShellParams(): array
	{
		$userId = '';
		try {
			$userId = $this->accessControl->currentUserIdOrEmpty();
		} catch (\Throwable) {
		}
		$isAdmin = $userId !== '' && $this->accessControl->isAppAdmin($userId);
		$workspaceRole = $isAdmin ? 'manager' : 'viewer';
		$isAppAdminCtx = $isAdmin;
		$workspaceId = 0;
		if ($userId !== '' && isset($this->workspaceAccess)) {
			try {
				$ctx = $this->workspaceAccess->resolveForUser($userId);
				$workspaceId = (int)$ctx['workspace']->getId();
				$isAppAdminCtx = !empty($ctx['isAppAdmin']) || $isAdmin;
				$workspaceRole = WorkspaceCapability::normalizeRole((string)($ctx['role'] ?? 'viewer'));
			} catch (\Throwable) {
			}
		}
		$g = $this->urlGenerator;

		return [
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'labels' => $this->labels,
			'canWrite' => WorkspaceCapability::allows($workspaceRole, 'write', $isAppAdminCtx),
			'canDelete' => WorkspaceCapability::allows($workspaceRole, 'delete', $isAppAdminCtx),
			'workspaceId' => $workspaceId,
			'urls' => [
				'companyNew' => $g->linkToRoute('customercheck.page.companyNew'),
				'import' => $g->linkToRoute('customercheck.page.import'),
				'companies' => $g->linkToRoute('customercheck.page.companies'),
				'today' => $g->linkToRoute('customercheck.page.today'),
				'agreements' => $g->linkToRoute('customercheck.page.agreements'),
			],
			'crmListFragment' => true,
		];
	}

	/**
	 * @param array<string, mixed> $pageData
	 * @return array<string, mixed>
	 */
	protected function mergeListPageParams(string $pageId, string $title, string $help = '', array $pageData = []): array
	{
		if ($this->wantsListFragment()) {
			return array_merge($this->buildFragmentShellParams(), $pageData);
		}

		return $this->mergeShellPageParams($pageId, $title, $help, $pageData);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	protected function listPageResponse(string $template, array $params): TemplateResponse
	{
		$fragment = $this->wantsListFragment();
		if ($fragment) {
			$params['crmListFragment'] = true;
		}
		$response = new TemplateResponse(
			'customercheck',
			$template,
			$params,
			$fragment ? TemplateResponse::RENDER_AS_BLANK : TemplateResponse::RENDER_AS_USER,
		);
		if ($fragment) {
			$response->addHeader('Cache-Control', 'no-store, private');
			$response->addHeader('Vary', ListFragmentRequest::varyValue());
			$response->addHeader(ListFragmentRequest::HEADER, '1');
			$response->addHeader('X-Content-Type-Options', 'nosniff');
		}

		return $response;
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
			'salesInbox' => $g->linkToRoute('customercheck.page.salesInbox'),
			'review' => $g->linkToRoute('customercheck.page.review'),
			'conflicts' => $g->linkToRoute('customercheck.page.conflicts'),
			'import' => $g->linkToRoute('customercheck.page.import'),
			'settings' => $g->linkToRoute('customercheck.page.settings'),
			'appSettings' => $g->linkToRoute('customercheck.page.appSettings'),
			'supportUs' => $g->linkToRoute('customercheck.page.supportUs'),
			'apiBase' => $g->linkToRoute('customercheck.today_api.index'),
		];
	}
}
