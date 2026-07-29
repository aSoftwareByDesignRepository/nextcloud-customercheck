<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\ReminderLog;
use OCA\CustomerCheck\Db\ReminderLogMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Renewal reminder engine — PSD §6.
 *
 * Contract, in order of importance:
 *
 *  1. **Exactly once.** For every (agreement, offset, valid_until) triple the
 *     ledger row is inserted *before* the notification is dispatched. The unique
 *     index `crm_rl_uq` absorbs job reruns, restarts and parallel workers: a
 *     duplicate-key failure means "already sent", never "send again". If the
 *     *notify* then fails, the row is removed so the next run retries. If
 *     notify succeeded and only the timeline audit fails, the ledger stays
 *     (releasing it would duplicate the Nextcloud notification — AC5).
 *  2. **Backfill collapses to the most urgent offset.** If the instance was down
 *     across −30 and −7, only −7 fires; the offsets that were skipped are still
 *     written to the ledger (so they cannot fire late) and recorded on the
 *     company timeline as `reminder.skipped`.
 *  3. **Re-arming is free.** The ledger key includes the `valid_until` snapshot,
 *     so correcting or renewing a date starts a fresh cadence automatically.
 */
class ReminderScheduler
{
	private const CHUNK_SIZE = 500;

	public function __construct(
		private readonly AgreementMapper $agreementMapper,
		private readonly ReminderLogMapper $reminderLogMapper,
		private readonly CompanyMapper $companyMapper,
		private readonly AgreementStatusService $statusService,
		private readonly NotificationService $notificationService,
		private readonly TimelineService $timelineService,
		private readonly ActivityService $activityService,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}

	// ── Pure cadence selection ──────────────────────────────────────────

	/**
	 * Cadence offsets whose trigger date has been reached.
	 *
	 * An offset `O` is due once `today >= valid_until - O`, i.e. once the
	 * remaining days have fallen to `O` or below. `daysUntil == 60` therefore
	 * makes the 60-day reminder due, while `61` does not.
	 *
	 * @return list<int>
	 */
	public function dueOffsets(?int $daysUntil): array
	{
		if ($daysUntil === null) {
			return [];
		}

		$due = [];
		foreach (Constants::REMINDER_OFFSETS as $offset) {
			if ($daysUntil <= $offset) {
				$due[] = $offset;
			}
		}

		return $due;
	}

	/**
	 * The most urgent of several missed offsets is the smallest one: the cadence
	 * counts down 60 → 30 → 7 → 0 → −7, so a lower number is closer to (or
	 * further past) the expiry date.
	 *
	 * @param list<int> $missedOffsets
	 */
	public function selectMostUrgent(array $missedOffsets): ?int
	{
		if ($missedOffsets === []) {
			return null;
		}

		return min($missedOffsets);
	}

	// ── Orchestration ───────────────────────────────────────────────────

	public function isCadenceEnabled(): bool
	{
		return $this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_CADENCE_ENABLED,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_CADENCE_ENABLED],
		) === '1';
	}

	/**
	 * True when the hourly TimedJob should run the full reminder sweep at
	 * `reminder_hour` (PSD §4.9.3 / §15). Independent of cadence_enabled so
	 * status recompute via run() can still happen when reminders are paused;
	 * processAgreement already no-ops notification when cadence is off.
	 */
	public function shouldRunNow(?\DateTimeInterface $now = null): bool
	{
		$now = $now ?? $this->timeFactory->getDateTime();
		$hour = (int)$this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_REMINDER_HOUR,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_REMINDER_HOUR],
		);

		return (int)$now->format('G') === $hour;
	}

	/**
	 * Status-only sweep (no reminder dispatch). Used by AgreementStatusRecomputeJob
	 * so cadence stays tied to `reminder_hour` via RenewalReminderJob.
	 *
	 * @return array{scanned: int, statusChanged: int}
	 */
	public function runStatusOnly(?\DateTimeInterface $today = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$summary = ['scanned' => 0, 'statusChanged' => 0];
		$offset = 0;
		while (true) {
			$chunk = $this->agreementMapper->findRenewalCandidates(self::CHUNK_SIZE, $offset);
			if ($chunk === []) {
				break;
			}
			foreach ($chunk as $agreement) {
				$summary['scanned']++;
				try {
					$statusChanged = $this->statusService->recompute($agreement, $today);
					if ($statusChanged) {
						$agreement->setUpdatedAt($this->timeFactory->getDateTime());
						$this->agreementMapper->update($agreement);
						$this->timelineService->append(
							(int)$agreement->getCompanyId(),
							'agreement.status',
							'system',
							['agreementId' => (int)$agreement->getId(), 'status' => (string)$agreement->getStatus()],
						);
						$summary['statusChanged']++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('CustomerCheck status recompute failed for agreement', [
						'app' => Application::APP_ID,
						'agreementId' => (int)$agreement->getId(),
						'exception' => $e,
					]);
				}
			}
			$offset += self::CHUNK_SIZE;
		}

		return $summary;
	}

	/**
	 * Nightly sweep over every watched agreement.
	 *
	 * @return array{scanned: int, statusChanged: int, sent: int, skipped: int}
	 */
	public function run(?\DateTimeInterface $today = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$summary = ['scanned' => 0, 'statusChanged' => 0, 'sent' => 0, 'skipped' => 0];

		$offset = 0;
		while (true) {
			$chunk = $this->agreementMapper->findRenewalCandidates(self::CHUNK_SIZE, $offset);
			if ($chunk === []) {
				break;
			}

			foreach ($chunk as $agreement) {
				$summary['scanned']++;
				try {
					$result = $this->processAgreement($agreement, $today);
					$summary['statusChanged'] += $result['statusChanged'] ? 1 : 0;
					$summary['sent'] += $result['fired'] === null ? 0 : 1;
					$summary['skipped'] += count($result['skipped']);
				} catch (\Throwable $e) {
					// One broken agreement must not stop the sweep.
					$this->logger->warning('CustomerCheck reminder scan failed for agreement', [
						'app' => Application::APP_ID,
						'agreementId' => (int)$agreement->getId(),
						'exception' => $e,
					]);
				}
			}

			$offset += self::CHUNK_SIZE;
		}

		return $summary;
	}

	/**
	 * Recompute the status and, when the cadence is on, fire at most one reminder.
	 *
	 * @return array{statusChanged: bool, fired: int|null, skipped: list<int>}
	 */
	public function processAgreement(Agreement $agreement, \DateTimeInterface $today): array
	{
		$statusChanged = $this->statusService->recompute($agreement, $today);
		if ($statusChanged) {
			$agreement->setUpdatedAt($this->timeFactory->getDateTime());
			$this->agreementMapper->update($agreement);
			$this->timelineService->append(
				(int)$agreement->getCompanyId(),
				'agreement.status',
				'system',
				['agreementId' => (int)$agreement->getId(), 'status' => (string)$agreement->getStatus()],
			);
		}

		if (!$this->isCadenceEnabled()) {
			return ['statusChanged' => $statusChanged, 'fired' => null, 'skipped' => []];
		}
		if ((string)$agreement->getKind() === 'onetime') {
			return ['statusChanged' => $statusChanged, 'fired' => null, 'skipped' => []];
		}
		// Website orders deliberately create drafts. They may have an end date,
		// but must not enter the renewal cadence until explicitly activated.
		if ((string)$agreement->getStatus() === 'draft') {
			return ['statusChanged' => $statusChanged, 'fired' => null, 'skipped' => []];
		}
		if (in_array((string)$agreement->getStatus(), Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
			return ['statusChanged' => $statusChanged, 'fired' => null, 'skipped' => []];
		}

		$validUntil = $agreement->getValidUntil();
		if ($validUntil === null) {
			return ['statusChanged' => $statusChanged, 'fired' => null, 'skipped' => []];
		}

		$daysUntil = $this->statusService->daysUntilExpiry($validUntil, $today);
		$alreadySent = $this->reminderLogMapper->findSentOffsets((int)$agreement->getId(), $validUntil);
		$missed = array_values(array_diff($this->dueOffsets($daysUntil), $alreadySent));

		$target = $this->selectMostUrgent($missed);
		if ($target === null) {
			return ['statusChanged' => $statusChanged, 'fired' => null, 'skipped' => []];
		}

		$skipped = [];
		foreach ($missed as $offset) {
			if ($offset === $target) {
				continue;
			}
			if ($this->consumeSilently($agreement, $offset, $validUntil)) {
				$skipped[] = $offset;
			}
		}

		if ($skipped !== []) {
			$this->timelineService->append(
				(int)$agreement->getCompanyId(),
				'reminder.skipped',
				'system',
				['agreementId' => (int)$agreement->getId(), 'offsets' => $skipped, 'firedInstead' => $target],
			);
		}

		$fired = $this->fireReminder($agreement, $target, $validUntil, $daysUntil ?? 0) ? $target : null;

		return ['statusChanged' => $statusChanged, 'fired' => $fired, 'skipped' => $skipped];
	}

	/**
	 * Claim a ledger slot and dispatch the notification.
	 *
	 * @return bool true when this call sent the reminder
	 */
	private function fireReminder(
		Agreement $agreement,
		int $offset,
		\DateTimeInterface $validUntil,
		int $daysUntil,
	): bool {
		$row = $this->claimLedgerSlot((int)$agreement->getId(), $offset, $validUntil);
		if ($row === null) {
			return false;
		}

		try {
			$companyName = '';
			try {
				$companyName = (string)$this->companyMapper->findById((int)$agreement->getCompanyId())->getName();
			} catch (\Throwable) {
				$companyName = '';
			}
			$graceUntil = '';
			if ($offset === 0) {
				$graceUntil = DateMath::addDays($validUntil, Constants::GRACE_DAYS)->format('Y-m-d');
			}
			$this->notificationService->notifyCrmUsers(
				Constants::NOTIFY_REMINDER,
				'agreement',
				(string)$agreement->getId(),
				[
					'agreementId' => (int)$agreement->getId(),
					'companyId' => (int)$agreement->getCompanyId(),
					'companyName' => $companyName,
					'label' => (string)$agreement->getLabel(),
					'offsetDays' => $offset,
					'daysUntil' => $daysUntil,
					'validUntil' => $validUntil->format('Y-m-d'),
					'graceUntil' => $graceUntil,
				],
			);
		} catch (\Throwable $e) {
			// Notify failed — release so the next run retries this exact offset.
			$this->reminderLogMapper->delete($row);
			throw $e;
		}

		// Timeline is best-effort AFTER a successful notify. Never release the
		// ledger here: that would re-fire the Nextcloud notification (AC5).
		try {
			$this->timelineService->append(
				(int)$agreement->getCompanyId(),
				'reminder.sent',
				'system',
				[
					'agreementId' => (int)$agreement->getId(),
					'offsetDays' => $offset,
					'validUntil' => $validUntil->format('Y-m-d'),
				],
			);
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck: reminder.sent timeline failed after notify (ledger kept)', [
				'app' => 'customercheck',
				'agreementId' => (int)$agreement->getId(),
				'offsetDays' => $offset,
				'exception' => $e,
			]);
		}

		// Grace end: the churn decision becomes an explicit, dated task (§6.2).
		if ($offset === -Constants::GRACE_DAYS) {
			$this->activityService->createChurnDecisionTask($agreement);
		}

		return true;
	}

	/**
	 * Mark an offset as consumed without notifying — the backfill "skip" path.
	 */
	private function consumeSilently(Agreement $agreement, int $offset, \DateTimeInterface $validUntil): bool
	{
		return $this->claimLedgerSlot((int)$agreement->getId(), $offset, $validUntil) !== null;
	}

	private function claimLedgerSlot(int $agreementId, int $offset, \DateTimeInterface $validUntil): ?ReminderLog
	{
		$row = new ReminderLog();
		$row->setAgreementId($agreementId);
		$row->setOffsetDays($offset);
		$row->setValidUntilSnap(new \DateTime($validUntil->format('Y-m-d')));
		$row->setSentAt($this->timeFactory->getDateTime());

		try {
			return $this->reminderLogMapper->insert($row);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}
	}

	/**
	 * Human-readable cadence line for the agreement card / settings preview.
	 */
	public function describeOffset(int $offset): string
	{
		return match ($offset) {
			60 => $this->l10n->t('60 days before expiry — send renewal quote'),
			30 => $this->l10n->t('30 days before expiry — reminder, call accounts of €500 and above'),
			7 => $this->l10n->t('7 days before expiry — escalation, clients will hit 402 at expiry'),
			0 => $this->l10n->t('On the expiry day — goodwill note, grace period starts'),
			-Constants::GRACE_DAYS => $this->l10n->t('7 days after expiry — grace ended, decide about churn'),
			Constants::REMLOG_OFFSET_QUOTA80 => $this->l10n->t('Check Partner quota reached 80 percent'),
			default => $this->l10n->t('Renewal reminder'),
		};
	}

	/**
	 * Next cadence date for an agreement, for the UI. Null when nothing is left.
	 */
	public function nextTriggerDate(Agreement $agreement, \DateTimeInterface $today): ?\DateTimeImmutable
	{
		$validUntil = $agreement->getValidUntil();
		if ($validUntil === null) {
			return null;
		}

		$alreadySent = $this->reminderLogMapper->findSentOffsets((int)$agreement->getId(), $validUntil);
		foreach (Constants::REMINDER_OFFSETS as $offset) {
			if (in_array($offset, $alreadySent, true)) {
				continue;
			}
			$trigger = DateMath::addDays($validUntil, -$offset);
			if (DateMath::daysBetween($today, $trigger) >= 0) {
				return $trigger;
			}
		}

		return null;
	}
}
