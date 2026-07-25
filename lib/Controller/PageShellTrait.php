<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\FrontEndAssetService;

trait PageShellTrait
{
	private const WIDE_SHELL_PAGE_IDS = [
		'today', 'companies', 'company-detail', 'company-form', 'pipeline',
		'agreements', 'agreement-form', 'money', 'activities', 'import',
		'settings', 'support-us',
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
		return [
			'pageId' => $pageId,
			'pageTitle' => $pageTitle,
			'pageHelp' => $pageHelp,
			'shellWidth' => $shellWidth,
			'roleLabel' => $isAdmin ? 'App admin' : 'CRM user',
			'roleSlug' => $isAdmin ? 'app-admin' : 'crm-user',
			'clientHints' => [
				'locale' => 'en',
				'htmlLang' => 'en',
				'timezone' => date_default_timezone_get() ?: 'UTC',
			],
			'urls' => $this->buildShellUrls(),
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'labels' => $this->labels,
			'canManage' => $isAdmin,
			'showToolsNav' => true,
		];
	}

	protected function mergeShellPageParams(string $pageId, string $title, string $help, array $pageData = []): array
	{
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
			'companies' => $g->linkToRoute('customercheck.page.companies'),
			'companyNew' => $g->linkToRoute('customercheck.page.companyNew'),
			'pipeline' => $g->linkToRoute('customercheck.page.pipeline'),
			'agreements' => $g->linkToRoute('customercheck.page.agreements'),
			'money' => $g->linkToRoute('customercheck.page.money'),
			'activities' => $g->linkToRoute('customercheck.page.activities'),
			'import' => $g->linkToRoute('customercheck.page.import'),
			'settings' => $g->linkToRoute('customercheck.page.settings'),
			'supportUs' => $g->linkToRoute('customercheck.page.supportUs'),
			'apiBase' => $g->linkToRoute('customercheck.today_api.index'),
		];
	}
}
