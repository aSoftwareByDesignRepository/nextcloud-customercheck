<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\BackgroundJob;

use OCA\CustomerCheck\BackgroundJob\AgreementStatusRecomputeJob;
use OCA\CustomerCheck\BackgroundJob\EntityRevisionPurgeJob;
use OCA\CustomerCheck\BackgroundJob\InvoicePaidPollJob;
use OCA\CustomerCheck\BackgroundJob\MailboxSyncJob;
use OCA\CustomerCheck\BackgroundJob\MailRetentionPurgeJob;
use OCA\CustomerCheck\BackgroundJob\PersonaMaintenanceJob;
use OCA\CustomerCheck\BackgroundJob\RenewalReminderJob;
use OCA\CustomerCheck\BackgroundJob\ReviewIngressJob;
use OCA\CustomerCheck\BackgroundJob\WeeklyDigestJob;
use OCA\CustomerCheck\Service\ActivityDueReminderService;
use OCA\CustomerCheck\Service\DealRottingReminderService;
use OCA\CustomerCheck\Service\EntityRevisionService;
use OCA\CustomerCheck\Service\InvoicePaidSyncService;
use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\PersonaMaintenanceService;
use OCA\CustomerCheck\Service\ReminderScheduler;
use OCA\CustomerCheck\Service\ReviewIngressService;
use OCA\CustomerCheck\Service\WeeklyDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Atlas v3 invoke coverage — BackgroundJob::run for coverage-map symbols.
 */
final class AtlasBackgroundJobInvokeCoverageTest extends TestCase
{
	private function invokeRun(object $job, mixed $argument = []): void
	{
		$method = new ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}

	private function time(): ITimeFactory
	{
		return $this->createMock(ITimeFactory::class);
	}

	public function testAllCoverageMapJobsRun(): void
	{
		$reminder = $this->createMock(ReminderScheduler::class);
		$reminder->expects($this->once())->method('runStatusOnly');
		$this->invokeRun(new AgreementStatusRecomputeJob($this->time(), $reminder));

		$revisions = $this->createMock(EntityRevisionService::class);
		$revisions->expects($this->once())->method('purgeOlderThanDays')->with(90, 500)->willReturn(0);
		$this->invokeRun(new EntityRevisionPurgeJob($this->time(), $revisions));

		$invoice = $this->createMock(InvoicePaidSyncService::class);
		$invoice->expects($this->once())->method('poll');
		$this->invokeRun(new InvoicePaidPollJob($this->time(), $invoice));

		$sync = $this->createMock(MailboxSyncService::class);
		$sync->expects($this->once())->method('purgeExpiredAcrossWorkspaces');
		$drafts = $this->createMock(MailDraftService::class);
		$drafts->expects($this->once())->method('purgeStale');
		$this->invokeRun(new MailRetentionPurgeJob($this->time(), $sync, $drafts));

		$mailboxSync = $this->createMock(MailboxSyncService::class);
		$mailboxSync->expects($this->once())->method('syncAll');
		$this->invokeRun(new MailboxSyncJob($this->time(), $mailboxSync));

		$persona = $this->createMock(PersonaMaintenanceService::class);
		$persona->expects($this->once())->method('run');
		$this->invokeRun(new PersonaMaintenanceJob($this->time(), $persona));

		$scheduler = $this->createMock(ReminderScheduler::class);
		$scheduler->method('shouldRunNow')->willReturn(false);
		$activityDue = $this->createMock(ActivityDueReminderService::class);
		$activityDue->method('shouldRunNow')->willReturn(false);
		$dealRotting = $this->createMock(DealRottingReminderService::class);
		$dealRotting->method('shouldRunNow')->willReturn(false);
		$this->invokeRun(new RenewalReminderJob($this->time(), $scheduler, $activityDue, $dealRotting));

		$ingress = $this->createMock(ReviewIngressService::class);
		$ingress->expects($this->once())->method('syncAll');
		$this->invokeRun(new ReviewIngressJob($this->time(), $ingress));

		$digest = $this->createMock(WeeklyDigestService::class);
		$digest->method('shouldRunNow')->willReturn(false);
		$this->invokeRun(new WeeklyDigestJob($this->time(), $digest));
	}
}
