<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\LeadWebhookService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\ReminderScheduler;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCA\CustomerCheck\Service\WeeklyDigestService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\IConfig;
use Test\TestCase;

/**
 * End-to-end domain workflows against the real DB (PSD §11 S1–S5, S9, S13).
 */
class DomainWorkflowIntegrationTest extends TestCase
{
	private string $suffix;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
		$this->suffix = substr(bin2hex(random_bytes(4)), 0, 8);
	}

	public function testCompanySlugTakenAndPersonPrimaryInvariant(): void
	{
		/** @var CompanyService $companies */
		$companies = \OC::$server->get(CompanyService::class);
		/** @var PersonService $persons */
		$persons = \OC::$server->get(PersonService::class);

		$slug = 'integ-' . $this->suffix;
		$company = $companies->create([
			'name' => 'Integ ' . $this->suffix,
			'slug' => $slug,
			'lang' => 'de',
			'country' => 'DE',
			'status' => 'lead',
		], 'admin');

		try {
			$companies->create([
				'name' => 'Other',
				'slug' => $slug,
				'lang' => 'de',
				'country' => 'DE',
			], 'admin');
			$this->fail('Expected SLUG_TAKEN');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_SLUG_TAKEN, $e->getErrorCode());
		}

		$a = $persons->create((int)$company->getId(), [
			'name' => 'Alice',
			'email' => 'alice-' . $this->suffix . '@example.com',
			'isPrimary' => true,
		], 'admin');
		$b = $persons->create((int)$company->getId(), [
			'name' => 'Bob',
			'email' => 'bob-' . $this->suffix . '@example.com',
			'isPrimary' => true,
		], 'admin');

		$a = $persons->get((int)$a->getId());
		$b = $persons->get((int)$b->getId());
		$this->assertFalse($a->isPrimaryContact());
		$this->assertTrue($b->isPrimaryContact());
	}

	public function testAgreementStatusBoundariesAndIllegalCancel(): void
	{
		/** @var CompanyService $companies */
		$companies = \OC::$server->get(CompanyService::class);
		/** @var AgreementService $agreements */
		$agreements = \OC::$server->get(AgreementService::class);
		/** @var AgreementStatusService $status */
		$status = \OC::$server->get(AgreementStatusService::class);

		$company = $companies->create([
			'name' => 'Status Co ' . $this->suffix,
			'slug' => 'status-' . $this->suffix,
			'lang' => 'de',
			'country' => 'DE',
		], 'admin');

		$today = new \DateTimeImmutable('today');
		$agreement = $agreements->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'license',
			'productCode' => 'azc',
			'label' => 'AZC boundary',
			'startsAt' => $today->modify('-30 days')->format('Y-m-d'),
			'validUntil' => $today->modify('+60 days')->format('Y-m-d'),
			'amountCents' => 10000,
			'status' => 'active',
			'limits' => ['mobileSeats' => 5, 'terminalDevices' => 1, 'bundle' => false],
		], 'admin');

		$this->assertSame('expiring', $status->deriveStatus($agreement, $today));

		$far = $agreements->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'license',
			'productCode' => 'azc',
			'label' => 'AZC far',
			'startsAt' => $today->format('Y-m-d'),
			'validUntil' => $today->modify('+61 days')->format('Y-m-d'),
			'amountCents' => 10000,
			'status' => 'active',
			'limits' => ['mobileSeats' => 5, 'terminalDevices' => 1, 'bundle' => false],
		], 'admin');
		$this->assertSame('active', $status->deriveStatus($far, $today));

		try {
			$agreements->cancel((int)$agreement->getId(), '', 'admin', null);
			$this->fail('Expected CANCEL_REASON_REQUIRED');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_CANCEL_REASON_REQUIRED, $e->getErrorCode());
		}

		$fresh = $agreements->get((int)$agreement->getId());
		$cancelled = $agreements->cancel(
			(int)$fresh->getId(),
			'no renewal',
			'admin',
			OptimisticLock::format($fresh->getUpdatedAt()),
		);
		$this->assertSame('cancelled', (string)$cancelled->getStatus());

		try {
			$status->assertTransitionAllowed('cancelled', 'active');
			$this->fail('Expected AGREEMENT_ILLEGAL_TRANSITION');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_AGREEMENT_ILLEGAL_TRANSITION, $e->getErrorCode());
		}
	}

	public function testReminderLedgerExactlyOnceAndStageSequences(): void
	{
		/** @var CompanyService $companies */
		$companies = \OC::$server->get(CompanyService::class);
		/** @var AgreementService $agreements */
		$agreements = \OC::$server->get(AgreementService::class);
		/** @var ReminderScheduler $reminders */
		$reminders = \OC::$server->get(ReminderScheduler::class);
		/** @var DealService $deals */
		$deals = \OC::$server->get(DealService::class);
		/** @var StageSequenceService $sequences */
		$sequences = \OC::$server->get(StageSequenceService::class);
		/** @var ActivityService $activities */
		$activities = \OC::$server->get(ActivityService::class);

		$today = new \DateTimeImmutable('today');
		$company = $companies->create([
			'name' => 'Remind Co ' . $this->suffix,
			'slug' => 'remind-' . $this->suffix,
			'lang' => 'de',
			'country' => 'DE',
		], 'admin');
		$agreement = $agreements->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'license',
			'productCode' => 'azc',
			'label' => 'AZC remind',
			'startsAt' => $today->modify('-10 days')->format('Y-m-d'),
			'validUntil' => $today->modify('+30 days')->format('Y-m-d'),
			'amountCents' => 50000,
			'status' => 'active',
			'limits' => ['mobileSeats' => 10, 'terminalDevices' => 1, 'bundle' => true],
		], 'admin');

		$first = $reminders->processAgreement($agreement, $today);
		$second = $reminders->processAgreement($agreement, $today);
		$this->assertNotNull($first['fired'], '−30 boundary must fire once');
		$this->assertNull($second['fired'], 'ledger unique key must block the second send');

		$deal = $deals->create([
			'companyId' => (int)$company->getId(),
			'title' => 'Quote ' . $this->suffix,
			'stage' => 'lead',
			'valueCents' => 50000,
			'products' => 'AZC 10',
		], 'admin');
		$result = $deals->changeStage(
			(int)$deal->getId(),
			'quoted',
			null,
			$deal->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			'admin',
		);
		$this->assertContains(Constants::SEQUENCE_QUOTED, $result['sequencesFired']);
		$this->assertTrue($sequences->hasFired((int)$deal->getId(), Constants::SEQUENCE_QUOTED));

		$again = $deals->changeStage(
			(int)$deal->getId(),
			'negotiation',
			null,
			$result['deal']->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			'admin',
		);
		$back = $deals->changeStage(
			(int)$again['deal']->getId(),
			'quoted',
			null,
			$again['deal']->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			'admin',
		);
		$this->assertSame([], $back['sequencesFired']);

		$open = $activities->search(['companyId' => (int)$company->getId(), 'openOnly' => true], 50);
		$followUps = array_values(array_filter(
			$open,
			static fn ($a): bool => (int)$a->getDealId() === (int)$deal->getId()
				&& (string)$a->getOrigin() === 'sequence_quoted',
		));
		$this->assertCount(3, $followUps, 'Quoted stage must create exactly three sequence follow-ups (locale-independent origin)');
		$dueOffsets = array_map(static function ($a) use ($today): int {
			$due = $a->getDueDate();
			$dueImm = $due instanceof \DateTimeImmutable
				? $due
				: \DateTimeImmutable::createFromMutable($due);
			return (int)$today->diff($dueImm)->days;
		}, $followUps);
		sort($dueOffsets);
		$this->assertSame([3, 7, 14], $dueOffsets);
	}

	public function testHoursQuarterAndWrongKind(): void
	{
		/** @var CompanyService $companies */
		$companies = \OC::$server->get(CompanyService::class);
		/** @var AgreementService $agreements */
		$agreements = \OC::$server->get(AgreementService::class);
		/** @var QuotaService $quota */
		$quota = \OC::$server->get(QuotaService::class);

		$today = new \DateTimeImmutable('today');
		$company = $companies->create([
			'name' => 'Hours Co ' . $this->suffix,
			'slug' => 'hours-' . $this->suffix,
			'lang' => 'de',
			'country' => 'DE',
		], 'admin');
		$license = $agreements->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'license',
			'productCode' => 'azc',
			'label' => 'AZC',
			'startsAt' => $today->format('Y-m-d'),
			'validUntil' => $today->modify('+365 days')->format('Y-m-d'),
			'amountCents' => 1000,
			'status' => 'active',
			'limits' => ['mobileSeats' => 1, 'terminalDevices' => 0, 'bundle' => false],
		], 'admin');
		try {
			$quota->logHours($license, $today, 60, 'support', 'admin');
			$this->fail('Expected HOURS_WRONG_KIND');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_HOURS_WRONG_KIND, $e->getErrorCode());
		}

		$partner = $agreements->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'partner',
			'productCode' => 'cp-std',
			'label' => 'CP STD',
			'startsAt' => $today->format('Y-m-d'),
			'validUntil' => $today->modify('+365 days')->format('Y-m-d'),
			'amountCents' => 1000,
			'status' => 'active',
			'limits' => ['hoursQuota' => 10],
		], 'admin');
		try {
			$quota->logHours($partner, $today, 20, 'bad', 'admin');
			$this->fail('Expected HOURS_NOT_QUARTER');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_HOURS_NOT_QUARTER, $e->getErrorCode());
		}

		$quota->logHours($partner, $today, 465, 'almost 80%', 'admin'); // 7.75h
		$quota->logHours($partner, $today, 30, 'cross 80%', 'admin'); // 8.25h
		$snap = $quota->snapshot($partner);
		$this->assertTrue($snap['alert']);
		$firedTwice = $quota->maybeFireQuotaAlert($partner);
		$this->assertFalse($firedTwice);
	}

	public function testOpenAccessRequiresConfirmationAndLeadWebhookValidation(): void
	{
		/** @var SettingsService $settings */
		$settings = \OC::$server->get(SettingsService::class);
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);
		/** @var LeadWebhookService $leads */
		$leads = \OC::$server->get(LeadWebhookService::class);
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);

		// Ensure current admin can manage (empty app admin list).
		$config->setAppValue(Application::APP_ID, Constants::CONFIG_APP_ADMIN_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, '1');

		try {
			$settings->update('admin', [
				Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => false,
			]);
			$this->fail('Expected confirmation validation');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
		}

		$settings->update('admin', [
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => false,
			'confirmOpenAccess' => true,
		]);
		$this->assertFalse($access->isAccessRestrictionEnabled());

		// Restore fail-closed for the rest of the suite / instance hygiene.
		$settings->update('admin', [
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => true,
		]);

		$token = $settings->getToken(Constants::CONFIG_LEAD_TOKEN);
		try {
			$leads->handle($token, '127.0.0.1', [
				'companyName' => 'X',
				'email' => '',
				'phone' => '',
			]);
			$this->fail('Expected HOOK_VALIDATION');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_HOOK_VALIDATION, $e->getErrorCode());
		}

		$result = $leads->handle($token, '127.0.0.1', [
			'companyName' => 'Lead Web ' . $this->suffix,
			'contactName' => 'Ada',
			'email' => 'ada-' . $this->suffix . '@lead.example',
			'message' => 'Bitte Angebot',
			'productInterest' => 'azc',
			'lang' => 'de',
		]);
		$this->assertArrayHasKey('companyId', $result);
		$this->assertArrayHasKey('dealId', $result);
		$this->assertArrayHasKey('slug', $result);
	}

	public function testWeeklyDigestGateUsesConfiguredWeekdayAndHour(): void
	{
		/** @var WeeklyDigestService $digest */
		$digest = \OC::$server->get(WeeklyDigestService::class);
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$prevEnabled = $config->getAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_ENABLED, '1');
		$prevWeekday = $config->getAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_WEEKDAY, '1');
		$prevHour = $config->getAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_HOUR, '7');
		try {
			$config->setAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_ENABLED, '1');
			$config->setAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_WEEKDAY, '1');
			$config->setAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_HOUR, '7');

			$mondayWrongHour = new \DateTimeImmutable('2026-07-20 03:00:00'); // Monday
			$mondayRightHour = new \DateTimeImmutable('2026-07-20 07:15:00');
			$this->assertFalse(
				$digest->shouldRunNow($mondayWrongHour),
				'Digest must not run at hour 3 when configured for hour 7',
			);
			$this->assertTrue($digest->shouldRunNow($mondayRightHour));
		} finally {
			$config->setAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_ENABLED, $prevEnabled);
			$config->setAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_WEEKDAY, $prevWeekday);
			$config->setAppValue(Application::APP_ID, Constants::CONFIG_DIGEST_HOUR, $prevHour);
		}
	}
}
