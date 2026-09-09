<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\PageController;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\AppSettingsSectionCatalog;
use OCA\CustomerCheck\Service\CirclesDirectoryService;
use OCA\CustomerCheck\Service\CommandBoardService;
use OCA\CustomerCheck\Service\CompanyCommandService;
use OCA\CustomerCheck\Service\CompanyHealthService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\ContactsSuggestService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Service\MailRelatedService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Service\WorkspaceSettingsSectionCatalog;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 invoke coverage — PageController public page actions.
 */
final class AtlasPageControllerInvokeCoverageTest extends TestCase
{
	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function wsCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);
		$ws->setName('Book');

		return [
			'workspace' => $ws,
			'role' => 'manager',
			'isAppAdmin' => true,
			'member' => null,
			'needsPicker' => false,
		];
	}

	private function company(int $id = 3, int $ws = 7): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setWorkspaceId($ws);
		$c->setSlug('acme');
		$c->setName('Acme');
		$c->setUpdatedAt(new \DateTime('2026-01-01T12:00:00+00:00'));

		return $c;
	}

	private function agreement(int $id = 4, int $companyId = 3): Agreement
	{
		$a = new Agreement();
		$a->setId($id);
		$a->setCompanyId($companyId);
		$a->setKind('license');
		$a->setProductCode('custom');
		$a->setLabel('Std');
		$a->setLimitsJson('{}');
		$a->setAmountCents(0);
		$a->setInvoiceStatus('none');
		$a->setStatus('active');

		return $a;
	}

	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s, array $p = []): string => $s);
		$l->method('n')->willReturnCallback(static fn (string $s, string $p, int $n): string => $s);
		$l->method('getLanguageCode')->willReturn('en');

		return $l;
	}

	private function urls(): IURLGenerator
	{
		$u = $this->createMock(IURLGenerator::class);
		$u->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => '/r/' . $route,
		);
		$u->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => 'https://nc/r/' . $route,
		);
		$u->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $url): string => str_starts_with($url, 'http') ? $url : 'https://nc' . $url,
		);

		return $u;
	}

	private function controller(?AccessControlService $access = null): PageController
	{
		if ($access === null) {
			$access = $this->createMock(AccessControlService::class);
			$access->method('requireCanUseApp')->willReturn('alice');
			$access->method('currentUserId')->willReturn('alice');
			$access->method('currentUserIdOrEmpty')->willReturn('alice');
			$access->method('isAppAdmin')->willReturn(true);
		}

		$wsAccess = $this->createMock(WorkspaceAccessService::class);
		$wsAccess->method('resolveForUser')->willReturn($this->wsCtx());
		$wsAccess->method('assertCapability');
		$wsAccess->method('assertUserCanAccessCompany');
		$wsAccess->method('listVisibleSummaries')->willReturn([]);

		$company = $this->company();
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$companies->method('search')->willReturn([]);
		$companies->method('count')->willReturn(0);
		$companies->method('mapByIds')->willReturn([]);
		$companies->method('activeDuplicateNameKeys')->willReturn([]);

		$agreements = $this->createMock(AgreementService::class);
		$agreements->method('get')->willReturn($this->agreement());
		$agreements->method('search')->willReturn([]);
		$agreements->method('listForCompany')->willReturn([]);

		$l10n = $this->l10n();
		$agreementStatus = new AgreementStatusService($l10n);
		$labels = new DisplayLabelService($l10n);

		$deals = $this->createMock(DealService::class);
		$deals->method('search')->willReturn([]);
		$deals->method('toArray')->willReturn(['id' => 9, 'companyId' => 3, 'stage' => 'lead', 'ownerUserId' => null]);

		$activities = $this->createMock(ActivityService::class);
		$activities->method('search')->willReturn([]);

		$persons = $this->createMock(PersonService::class);
		$timeline = $this->createMock(TimelineService::class);
		$timeline->method('listForCompany')->willReturn([]);

		$mailRelated = $this->createMock(MailRelatedService::class);
		$mailRelated->method('forCompany')->willReturn(['items' => []]);

		$today = $this->createMock(TodayService::class);
		$today->method('build')->willReturn(['items' => []]);

		$commandBoard = $this->createMock(CommandBoardService::class);
		$commandBoard->method('build')->willReturn(['columns' => []]);

		$companyCommand = $this->createMock(CompanyCommandService::class);
		$companyCommand->method('peopleRoster')->willReturn([]);

		$contacts = $this->createMock(ContactsSuggestService::class);
		$contacts->method('isAvailable')->willReturn(false);

		$health = $this->createMock(CompanyHealthService::class);
		$health->method('enrich')->willReturn([]);

		$money = $this->createMock(MoneyService::class);
		$money->method('overview')->willReturn(['rows' => []]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getForUser')->willReturn([
			'pipelineView' => 'table',
			'access_allowed_user_ids' => [],
			'app_admin_user_ids' => [],
		]);

		$directory = $this->createMock(DirectoryOptionsService::class);
		$directory->method('resolveUsers')->willReturn([]);
		$directory->method('availableGroups')->willReturn([]);

		$circles = $this->createMock(CirclesDirectoryService::class);
		$circles->method('isAvailable')->willReturn(false);
		$circles->method('availableCirclesForUser')->willReturn([]);

		$quota = $this->createMock(QuotaService::class);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('capabilityMatrix')->willReturn([]);
		$facades->method('projectcheckAvailable')->willReturn(false);
		$facades->method('ticketcheckAvailable')->willReturn(false);
		$facades->method('projectcheckWriteAvailable')->willReturn(false);
		$facades->method('ticketcheckWriteAvailable')->willReturn(false);
		$facades->method('maintenancecheckIdentityAvailable')->willReturn(false);
		$facades->method('listProjectCheckCustomers')->willReturn([]);
		$facades->method('listTicketCheckCustomers')->willReturn([]);

		$identity = $this->createMock(IdentityLinkHealthService::class);
		$identity->method('classifyCompany')->willReturn([
			'pc' => ['state' => 'unlinked', 'pcCustomerId' => null, 'label' => '', 'deepLink' => null],
			'tc' => ['state' => 'unlinked', 'tcCustomerId' => null, 'label' => '', 'deepLink' => null],
		]);

		$license = $this->createMock(LicenseService::class);
		$license->method('status')->willReturn(['ok' => true]);
		$license->method('listSeats')->willReturn([]);

		$productCodes = $this->createMock(ProductCodeRegistry::class);
		$productCodes->method('listAll')->willReturn([]);

		$workspaceService = $this->createMock(WorkspaceService::class);
		$workspaceService->method('listMembers')->willReturn([]);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => $d);
		$request->method('getRequestUri')->willReturn('/apps/customercheck/');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-01-15'));

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('en');

		return new PageController(
			'customercheck',
			$request,
			$access,
			$companies,
			$agreements,
			$agreementStatus,
			$deals,
			$activities,
			$persons,
			$timeline,
			$mailRelated,
			$today,
			$commandBoard,
			$companyCommand,
			$contacts,
			$health,
			$money,
			$settings,
			$directory,
			$circles,
			$quota,
			$facades,
			$identity,
			$labels,
			$license,
			$productCodes,
			$wsAccess,
			$workspaceService,
			new WorkspaceSettingsSectionCatalog(),
			new AppSettingsSectionCatalog(),
			$this->urls(),
			$l10n,
			$l10nFactory,
			$time,
		);
	}

	public function testAllPublicPageActionsReturnResponse(): void
	{
		$ctrl = $this->controller();

		$responses = [
			$ctrl->index(),
			$ctrl->today(),
			$ctrl->pulse(),
			$ctrl->brief(),
			$ctrl->myAccounts(),
			$ctrl->team(),
			$ctrl->workload(),
			$ctrl->strategy(),
			$ctrl->mailTriage(),
			$ctrl->salesInbox(),
			$ctrl->review(),
			$ctrl->conflicts(),
			$ctrl->prepareMeeting(3),
			$ctrl->companies(),
			$ctrl->companyNew(),
			$ctrl->companyEdit(3),
			$ctrl->companyDetail(3),
			$ctrl->pipeline(),
			$ctrl->agreements(),
			$ctrl->agreementNew(),
			$ctrl->agreementEdit(4),
			$ctrl->money(),
			$ctrl->activities(),
			$ctrl->import(),
			$ctrl->settings(),
			$ctrl->settingsSection('preferences'),
			$ctrl->settingsSection('not-a-section'),
			$ctrl->appSettings(),
			$ctrl->appSettingsSection('access'),
			$ctrl->supportUs(),
			$ctrl->getTheApp(),
		];

		self::assertCount(31, $responses);
		foreach ($responses as $res) {
			self::assertInstanceOf(Response::class, $res);
		}

		self::assertInstanceOf(TemplateResponse::class, $responses[0]);
		self::assertInstanceOf(RedirectResponse::class, $responses[6]);
		self::assertInstanceOf(RedirectResponse::class, $responses[8]);
		self::assertInstanceOf(RedirectResponse::class, $responses[10]);
		self::assertInstanceOf(RedirectResponse::class, $responses[12]);
		self::assertInstanceOf(RedirectResponse::class, $responses[24]);
		self::assertInstanceOf(NotFoundResponse::class, $responses[26]);
		self::assertInstanceOf(RedirectResponse::class, $responses[27]);

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$denyAccess->method('currentUserId')->willThrowException(new AccessDeniedException('no'));
		$denyAccess->method('currentUserIdOrEmpty')->willReturn('');
		$deny = $this->controller($denyAccess);
		foreach ([
			static fn () => $deny->prepareMeeting(3),
			static fn () => $deny->companyEdit(3),
			static fn () => $deny->companyDetail(3),
			static fn () => $deny->agreementEdit(4),
		] as $i => $call) {
			try {
				$res = $call();
				// Page actions may render TemplateResponse or throw; deny should not be a happy TemplateResponse for gated pages.
				self::assertNotInstanceOf(TemplateResponse::class, $res, 'page authz idx ' . $i);
			} catch (AccessDeniedException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}
	}
}
