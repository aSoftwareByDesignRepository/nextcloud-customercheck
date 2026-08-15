<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\App\IAppManager;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\Files\IRootFolder;
use OCA\CustomerCheck\BackgroundJob\AgreementStatusRecomputeJob;
use OCA\CustomerCheck\BackgroundJob\InvoicePaidPollJob;
use OCA\CustomerCheck\BackgroundJob\RenewalReminderJob;
use OCA\CustomerCheck\BackgroundJob\WeeklyDigestJob;
use OCA\CustomerCheck\Dashboard\TodayWidget;
use OCA\CustomerCheck\Listener\LicenseKeyGeneratedListener;
use OCA\CustomerCheck\Listener\UserDeletedListener;
use OCP\User\Events\UserDeletedEvent;
use OCA\CustomerCheck\Middleware\AppAccessMiddleware;
use OCA\CustomerCheck\Middleware\SessionRateLimitMiddleware;
use OCA\CustomerCheck\Notification\Notifier;
use OCA\CustomerCheck\Repair\BackupBeforeUpdate;
use OCA\CustomerCheck\Repair\BootstrapDefaultWorkspace;
use OCA\CustomerCheck\Repair\EnsureCustomerCheckSchema;
use OCA\CustomerCheck\Repair\UninstallDropTables;
use OCA\CustomerCheck\Search\CompanySearchProvider;
use OCA\CustomerCheck\Search\DealSearchProvider;
use OCA\CustomerCheck\Search\PersonSearchProvider;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\LicenseKeyEventService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\DeliveryPackService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\LeadWebhookService;
use OCA\CustomerCheck\Service\WebsiteOrderWebhookService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCA\CustomerCheck\Service\LinkIdempotencyService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\CustomerCheck\Public\CrmCompanyLinkReadFacade;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\PartnerMailService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\ReminderScheduler;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\TodayService;
use OCA\CustomerCheck\Service\VTodoService;
use OCA\CustomerCheck\Service\WeeklyDigestService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'customercheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(AccessControlService::class, function ($c) {
			return new AccessControlService($c->get(\OCP\IConfig::class), $c->get(\OCP\IGroupManager::class), $c->get(IUserSession::class));
		});
		$context->registerService(AppAccessMiddleware::class, function ($c) {
			return new AppAccessMiddleware($c->get(IUserSession::class), $c->get(AccessControlService::class), $c->get(\OCP\IRequest::class), $c->get(IURLGenerator::class), $c->get(IFactory::class), $c->get(\Psr\Log\LoggerInterface::class));
		});
		$context->registerMiddleware(AppAccessMiddleware::class);
		$context->registerService(SessionRateLimitMiddleware::class, function ($c) {
			return new SessionRateLimitMiddleware(
				$c->get(\OCP\IRequest::class),
				$c->get(IUserSession::class),
				$c->get(RateLimitService::class),
				$c->get(IFactory::class),
			);
		});
		$context->registerMiddleware(SessionRateLimitMiddleware::class);

		$context->registerService(EnsureCustomerCheckSchema::class, fn ($c) => new EnsureCustomerCheckSchema($c->get(\OCP\IDBConnection::class), $c->get(\OCP\IConfig::class)));
		$context->registerService(BootstrapDefaultWorkspace::class, fn ($c) => new BootstrapDefaultWorkspace(
			$c->get(\OCP\IDBConnection::class),
			$c->get(\OCP\IConfig::class),
		));
		$context->registerService(UninstallDropTables::class, fn ($c) => new UninstallDropTables($c->get(\OCP\IDBConnection::class), $c->get(\OCP\IConfig::class), $c->get(IRootFolder::class)));

		$context->registerService(SlugService::class, fn ($c) => new SlugService($c->get(\OCA\CustomerCheck\Db\CompanyMapper::class), $c->get(\OCP\IL10N::class)));
		$context->registerService(TimelineService::class, fn ($c) => new TimelineService($c->get(\OCA\CustomerCheck\Db\TimelineEventMapper::class), $c->get(\OCP\AppFramework\Utility\ITimeFactory::class)));
		$context->registerService(AgreementStatusService::class, fn ($c) => new AgreementStatusService($c->get(\OCP\IL10N::class)));
		$context->registerService(NotificationService::class, fn ($c) => new NotificationService(
			$c->get(\OCP\Notification\IManager::class),
			$c->get(AccessControlService::class),
			$c->get(WorkspaceAccessService::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\Psr\Log\LoggerInterface::class),
			$c->get(MobilePushService::class),
		));
		
		$context->registerService(LinkIdempotencyService::class, fn ($c) => new LinkIdempotencyService(
			$c->get(\OCA\CustomerCheck\Db\LinkIdempotencyMapper::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IL10N::class),
		));
		$context->registerService(IdentityLinkHealthService::class, fn ($c) => new IdentityLinkHealthService(
			$c->get(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$c->get(FacadeProbeService::class),
			$c->get(\OCP\IConfig::class),
			$c->get(\OCP\IL10N::class),
			$c->get(\Psr\Log\LoggerInterface::class),
		));
		$context->registerService(SiblingCustomerWriteService::class, fn ($c) => new SiblingCustomerWriteService(
			$c->get(CompanyService::class),
			$c->get(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$c->get(FacadeProbeService::class),
			$c->get(TimelineService::class),
			$c->get(\OCA\CustomerCheck\Db\PersonMapper::class),
			$c->get(\OCP\IL10N::class),
			$c->get(\Psr\Log\LoggerInterface::class),
			$c->get(LinkIdempotencyService::class),
			$c->get(IdentityLinkHealthService::class),
		));
		$context->registerService(CrmCompanyLinkReadFacade::class, fn ($c) => new CrmCompanyLinkReadFacade(
			$c->get(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$c->get(AccessControlService::class),
			$c->get(IURLGenerator::class),
		));

		$context->registerService(MobileIdempotencyService::class, fn ($c) => new MobileIdempotencyService(
			$c->get(\OCA\CustomerCheck\Db\MobileIdempotencyMapper::class),
			$c->get(ActivityService::class),
			$c->get(\OCP\IDBConnection::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IL10N::class),
		));
		$context->registerService(MobilePushService::class, fn ($c) => new MobilePushService(
			$c->get(\OCA\CustomerCheck\Db\PushTokenMapper::class),
			$c->get(\OCP\Http\Client\IClientService::class),
			$c->get(\OCP\IUserManager::class),
			$c->get(IFactory::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\Psr\Log\LoggerInterface::class),
		));
		$context->registerService(RateLimitService::class, fn ($c) => new RateLimitService(
			$c->get(\OCP\IConfig::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\Lock\ILockingProvider::class),
		));
		$context->registerService(SettingsService::class, fn ($c) => new SettingsService(
			$c->get(\OCP\IConfig::class),
			$c->get(AccessControlService::class),
			$c->get(\OCP\Security\ISecureRandom::class),
			$c->get(IURLGenerator::class),
			$c->get(\OCP\IUserManager::class),
		));
		$context->registerService(WebsiteOrderWebhookService::class, fn ($c) => new WebsiteOrderWebhookService(
			$c->get(SettingsService::class),
			$c->get(RateLimitService::class),
			$c->get(CompanyService::class),
			$c->get(WorkspaceService::class),
			$c->get(SlugService::class),
			$c->get(PersonService::class),
			$c->get(\OCA\CustomerCheck\Db\PersonMapper::class),
			$c->get(\OCA\CustomerCheck\Db\DealMapper::class),
			$c->get(AgreementService::class),
			$c->get(\OCA\CustomerCheck\Db\ActivityMapper::class),
			$c->get(TimelineService::class),
			$c->get(NotificationService::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IL10N::class),
			$c->get(ILockingProvider::class),
		));
		$context->registerService(DirectoryOptionsService::class, fn ($c) => new DirectoryOptionsService(
			$c->get(\OCP\IGroupManager::class),
			$c->get(\OCP\IUserManager::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\CirclesDirectoryService::class, fn ($c) => new \OCA\CustomerCheck\Service\CirclesDirectoryService(
			$c->get(\OCP\App\IAppManager::class),
			$c->get(\Psr\Log\LoggerInterface::class),
		));
		$context->registerService(FacadeProbeService::class, fn ($c) => new FacadeProbeService(
			$c->get(\OCP\App\IAppManager::class),
			$c->get(\Psr\Log\LoggerInterface::class),
			$c->get(IURLGenerator::class),
		));

		$context->registerNotifierService(Notifier::class);
		$context->registerDashboardWidget(TodayWidget::class);
		$context->registerSearchProvider(CompanySearchProvider::class);
		$context->registerSearchProvider(PersonSearchProvider::class);
		$context->registerSearchProvider(DealSearchProvider::class);
		$context->registerEventListener(
			'OCA\\SbdLicenseOps\\Event\\LicenseKeyGeneratedEvent',
			LicenseKeyGeneratedListener::class,
		);

		$context->registerService(LicenseKeyEventService::class, fn ($c) => new LicenseKeyEventService(
			$c->get(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$c->get(\OCA\CustomerCheck\Db\AgreementMapper::class),
			$c->get(AgreementService::class),
			$c->get(\OCA\CustomerCheck\Db\ImportJobMapper::class),
			$c->get(NotificationService::class),
			$c->get(TimelineService::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IL10N::class),
			$c->get(\Psr\Log\LoggerInterface::class),
		));
		$context->registerService(TodayWidget::class, fn ($c) => new TodayWidget(
			$c->get(\OCP\IL10N::class),
			$c->get(IURLGenerator::class),
			$c->get(TodayService::class),
			$c->get(AccessControlService::class),
			$c->get(WorkspaceAccessService::class),
			$c->get(IUserSession::class),
			$c->get(\OCA\CustomerCheck\Service\AppIconService::class),
		));
		$context->registerService(CompanySearchProvider::class, fn ($c) => new CompanySearchProvider(
			$c->get(\OCP\IL10N::class),
			$c->get(IURLGenerator::class),
			$c->get(CompanyService::class),
			$c->get(AccessControlService::class),
			$c->get(WorkspaceAccessService::class),
			$c->get(\OCA\CustomerCheck\Service\AppIconService::class),
		));
		$context->registerService(PersonSearchProvider::class, fn ($c) => new PersonSearchProvider(
			$c->get(\OCP\IL10N::class),
			$c->get(IURLGenerator::class),
			$c->get(PersonService::class),
			$c->get(AccessControlService::class),
			$c->get(WorkspaceAccessService::class),
			$c->get(\OCA\CustomerCheck\Service\AppIconService::class),
		));
		$context->registerService(DealSearchProvider::class, fn ($c) => new DealSearchProvider(
			$c->get(\OCP\IL10N::class),
			$c->get(IURLGenerator::class),
			$c->get(DealService::class),
			$c->get(AccessControlService::class),
			$c->get(WorkspaceAccessService::class),
			$c->get(\OCA\CustomerCheck\Service\AppIconService::class),
		));

		// Jobs resolve via container; ensure constructors are registered
		$context->registerService(RenewalReminderJob::class, fn ($c) => new RenewalReminderJob(
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(ReminderScheduler::class),
			$c->get(\OCA\CustomerCheck\Service\ActivityDueReminderService::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\ActivityDueReminderService::class, fn ($c) => new \OCA\CustomerCheck\Service\ActivityDueReminderService(
			$c->get(\OCA\CustomerCheck\Db\ActivityMapper::class),
			$c->get(\OCA\CustomerCheck\Db\ActivityDueLogMapper::class),
			$c->get(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$c->get(NotificationService::class),
			$c->get(\OCP\IConfig::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\Psr\Log\LoggerInterface::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\ContactsSuggestService::class, function ($c) {
			$contacts = null;
			try {
				$contacts = $c->get(\OCP\Contacts\IManager::class);
			} catch (\Throwable) {
				$contacts = null;
			}

			return new \OCA\CustomerCheck\Service\ContactsSuggestService(
				$c->get(\OCP\App\IAppManager::class),
				$contacts,
				$c->get(\OCP\IUserSession::class),
				$c->get(\OCP\IUserManager::class),
			);
		});
		$context->registerService(WeeklyDigestJob::class, fn ($c) => new WeeklyDigestJob($c->get(\OCP\AppFramework\Utility\ITimeFactory::class), $c->get(WeeklyDigestService::class)));
		$context->registerService(\OCA\CustomerCheck\BackgroundJob\MailboxSyncJob::class, fn ($c) => new \OCA\CustomerCheck\BackgroundJob\MailboxSyncJob(
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCA\CustomerCheck\Service\MailboxSyncService::class),
		));
		$context->registerService(\OCA\CustomerCheck\BackgroundJob\MailRetentionPurgeJob::class, fn ($c) => new \OCA\CustomerCheck\BackgroundJob\MailRetentionPurgeJob(
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCA\CustomerCheck\Service\MailboxSyncService::class),
			$c->get(\OCA\CustomerCheck\Service\MailDraftService::class),
		));
		$context->registerService(\OCA\CustomerCheck\BackgroundJob\PersonaMaintenanceJob::class, fn ($c) => new \OCA\CustomerCheck\BackgroundJob\PersonaMaintenanceJob(
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCA\CustomerCheck\Service\PersonaMaintenanceService::class),
		));
		$context->registerService(\OCA\CustomerCheck\BackgroundJob\ReviewIngressJob::class, fn ($c) => new \OCA\CustomerCheck\BackgroundJob\ReviewIngressJob(
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCA\CustomerCheck\Service\ReviewIngressService::class),
		));
		$context->registerService(\OCA\CustomerCheck\BackgroundJob\EntityRevisionPurgeJob::class, fn ($c) => new \OCA\CustomerCheck\BackgroundJob\EntityRevisionPurgeJob(
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCA\CustomerCheck\Service\EntityRevisionService::class),
		));
		$context->registerService(AgreementStatusRecomputeJob::class, fn ($c) => new AgreementStatusRecomputeJob($c->get(\OCP\AppFramework\Utility\ITimeFactory::class), $c->get(ReminderScheduler::class)));
		$context->registerService(\OCA\CustomerCheck\Service\ImportMatcher::class, fn ($c) => new \OCA\CustomerCheck\Service\ImportMatcher(
			$c->get(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$c->get(\OCA\CustomerCheck\Db\PersonMapper::class),
			$c->get(SlugService::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\ImportService::class, fn ($c) => new \OCA\CustomerCheck\Service\ImportService(
			$c->get(\OCA\CustomerCheck\Service\ImportMatcher::class),
			$c->get(CompanyService::class),
			$c->get(AgreementService::class),
			$c->get(\OCA\CustomerCheck\Db\AgreementMapper::class),
			$c->get(\OCA\CustomerCheck\Db\PersonMapper::class),
			$c->get(AgreementStatusService::class),
			$c->get(TimelineService::class),
			$c->get(\OCA\CustomerCheck\Db\ImportJobMapper::class),
			$c->get(FacadeProbeService::class),
			$c->get(ProductCodeRegistry::class),
			$c->get(\OCP\IDBConnection::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IL10N::class),
			$c->get(\OCA\CustomerCheck\Service\ReviewService::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\InvoicePaidSyncService::class, fn ($c) => new \OCA\CustomerCheck\Service\InvoicePaidSyncService(
			$c->get(\OCA\CustomerCheck\Db\AgreementMapper::class),
			$c->get(\OCA\CustomerCheck\Db\PayWatchMapper::class),
			$c->get(ActivityService::class),
			$c->get(TimelineService::class),
			$c->get(NotificationService::class),
			$c->get(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$c->get(FacadeProbeService::class),
			$c->get(\OCP\IDBConnection::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IL10N::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\MailRelatedService::class, fn ($c) => new \OCA\CustomerCheck\Service\MailRelatedService(
			$c->get(FacadeProbeService::class),
			$c->get(\OCA\CustomerCheck\Db\PersonMapper::class),
			$c->get(EmailTouchService::class),
			$c->get(\OCP\IConfig::class),
			$c->get(IURLGenerator::class),
			$c->get(\OCP\IL10N::class),
		));
		$context->registerService(InvoicePaidPollJob::class, fn ($c) => new InvoicePaidPollJob(
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCA\CustomerCheck\Service\InvoicePaidSyncService::class),
		));
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});

		// ── CUC2 companion licensing (COMPANION-APP §5) ──────────────────
		$context->registerService(\OCA\CustomerCheck\Service\LicenseService::class, fn ($c) => new \OCA\CustomerCheck\Service\LicenseService(
			$c->get(\OCP\IDBConnection::class),
			$c->get(\OCA\CustomerCheck\Db\LicenseStateMapper::class),
			$c->get(\OCA\CustomerCheck\Db\MobileSeatMapper::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IUserManager::class),
			$c->get(ILockingProvider::class),
			$c->get(\OCP\IL10N::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\MobileGateService::class, fn ($c) => new \OCA\CustomerCheck\Service\MobileGateService(
			$c->get(\OCA\CustomerCheck\Service\LicenseService::class),
			$c->get(FacadeProbeService::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\EditConflictService::class, fn ($c) => new \OCA\CustomerCheck\Service\EditConflictService(
			$c->get(\OCP\IDBConnection::class),
			$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			$c->get(\OCP\IL10N::class),
			$c->get(NotificationService::class),
		));
		$context->registerService(\OCA\CustomerCheck\Service\MobileApiService::class, fn ($c) => new \OCA\CustomerCheck\Service\MobileApiService(
			$c->get(TodayService::class),
			$c->get(CompanyService::class),
			$c->get(ActivityService::class),
			$c->get(\OCA\CustomerCheck\Db\AgreementMapper::class),
			$c->get(FacadeProbeService::class),
			$c->get(\OCA\CustomerCheck\Service\ReviewService::class),
			$c->get(\OCA\CustomerCheck\Service\EditConflictService::class),
			$c->get(\OCA\CustomerCheck\Service\DealChecklistService::class),
			$c->get(\OCA\CustomerCheck\Service\OooCoverService::class),
			$c->get(\OCA\CustomerCheck\Service\PersonService::class),
			$c->get(\OCA\CustomerCheck\Service\DealService::class),
		));
		$context->registerService(UserDeletedListener::class, fn ($c) => new UserDeletedListener(
			$c->get(\OCA\CustomerCheck\Db\MobileSeatMapper::class),
			$c->get(\OCP\IConfig::class),
			$c->get(\Psr\Log\LoggerInterface::class),
		));
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void
	{
		// Token seeding must never block navigation registration — a write failure
		// (read-only period, upgrade lock, etc.) previously swallowed the whole boot
		// try/catch and hid CustomerCheck from every user, including Open mode.
		try {
			$this->getContainer()->get(SettingsService::class)->ensureTokensExist();
		} catch (\Throwable) {
		}

		try {
			$container = $this->getContainer();
			$user = $container->get(IUserSession::class)->getUser();
			if ($user === null) {
				return;
			}
			if (!$container->get(AccessControlService::class)->canUseApp($user->getUID())) {
				return;
			}
			$nav = $container->get(INavigationManager::class);
			$url = $container->get(IURLGenerator::class);
			$l10n = $container->get(IFactory::class);
			$appIcons = $container->get(\OCA\CustomerCheck\Service\AppIconService::class);
			$nav->add(function () use ($url, $l10n, $appIcons) {
				return [
					'id' => self::APP_ID,
					'app' => self::APP_ID,
					'order' => 45,
					'href' => $url->linkToRoute('customercheck.page.today'),
					'icon' => $appIcons->headerIconPath(),
					'name' => $l10n->get(self::APP_ID)->t('CustomerCheck'),
				];
			});
		} catch (\Throwable) {
		}
	}
}
