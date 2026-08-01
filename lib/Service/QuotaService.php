<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\HoursEntry;
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\ReminderLog;
use OCA\CustomerCheck\Db\ReminderLogMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Check Partner hour log and quota alerting — PSD §4.6, §6.3, §8.4.
 *
 * Two invariants drive everything here:
 *
 *  1. **15-minute billing units** (CHECK-PARTNER §2.1). Anything else is
 *     `HOURS_NOT_QUARTER`. Negative multiples are legal, but only as
 *     compensating corrections — an entry older than 7 days is immutable, so
 *     the only way to fix it is a further entry (§8.4 E1).
 *  2. **One 80 % alert per agreement.** The alert reuses the reminder ledger
 *     with the `80080` sentinel offset, so the DB unique index — not
 *     application bookkeeping — is what guarantees exactly-once (§6.3).
 */
class QuotaService
{
	public function __construct(
		private readonly HoursMapper $hoursMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly CompanyMapper $companyMapper,
		private readonly ReminderLogMapper $reminderLogMapper,
		private readonly NotificationService $notificationService,
		private readonly TimelineService $timelineService,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	// ── Pure validation & math ──────────────────────────────────────────

	/**
	 * @throws ValidationException `HOURS_WRONG_KIND`
	 */
	public function assertPartnerAgreement(Agreement $agreement): void
	{
		if ((string)$agreement->getKind() !== 'partner') {
			throw ValidationException::field(
				Constants::ERR_HOURS_WRONG_KIND,
				'agreementId',
				$this->l10n->t('Hours can only be logged on Check Partner agreements.'),
			);
		}
	}

	/**
	 * @throws ValidationException `HOURS_NOT_QUARTER`
	 */
	public function assertQuarterHour(int $minutes): void
	{
		if ($minutes === 0 || $minutes % Constants::HOURS_UNIT_MINUTES !== 0) {
			throw ValidationException::field(
				Constants::ERR_HOURS_NOT_QUARTER,
				'minutes',
				$this->l10n->t('Log time in 15-minute steps (for example 15, 30, 45 or 60 minutes).'),
			);
		}
	}

	/**
	 * Entries older than 7 days are frozen for integrity (§4.6).
	 *
	 * @throws ValidationException `HOURS_LOCKED`
	 */
	public function assertEntryEditable(HoursEntry $entry, \DateTimeInterface $now): void
	{
		$createdAt = $entry->getCreatedAt();
		if ($createdAt === null) {
			return;
		}

		if (DateMath::daysBetween($createdAt, $now) > Constants::HOURS_LOCK_DAYS) {
			throw ValidationException::field(
				Constants::ERR_HOURS_LOCKED,
				'id',
				$this->l10n->t('This entry is older than 7 days and can no longer be edited. Add a correcting entry instead.'),
			);
		}
	}

	/**
	 * Contracted quota in minutes; 0 when the agreement carries no `hoursQuota`.
	 */
	public function quotaMinutes(Agreement $agreement): int
	{
		$limits = $agreement->decodedLimits();
		$hours = $limits['hoursQuota'] ?? null;
		if (!is_int($hours) && !(is_string($hours) && ctype_digit($hours))) {
			return 0;
		}

		return max(0, (int)$hours) * 60;
	}

	/**
	 * Percentage of the quota consumed, for display only. Returns 0.0 when the
	 * agreement has no quota so the UI never renders a division by zero.
	 */
	public function usedPercent(int $usedMinutes, int $quotaMinutes): float
	{
		if ($quotaMinutes <= 0) {
			return 0.0;
		}

		return ($usedMinutes / $quotaMinutes) * 100.0;
	}

	/**
	 * Alert predicate in exact integer arithmetic — floats must not decide
	 * whether a customer gets an 80 % warning. 79.9 % stays silent, 80.0 % fires.
	 */
	public function hasReachedAlertThreshold(int $usedMinutes, int $quotaMinutes): bool
	{
		if ($quotaMinutes <= 0) {
			return false;
		}

		return $usedMinutes * 100 >= $quotaMinutes * (int)Constants::QUOTA_ALERT_PERCENT;
	}

	// ── Reads ───────────────────────────────────────────────────────────

	public function usedMinutes(int $agreementId): int
	{
		return $this->hoursMapper->sumMinutes($agreementId);
	}

	/** @return HoursEntry[] */
	public function listEntries(int $agreementId, int $limit = 500): array
	{
		return $this->hoursMapper->findByAgreement($agreementId, $limit);
	}

	public function getEntry(int $entryId): HoursEntry
	{
		try {
			return $this->hoursMapper->findById($entryId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('Hours entry not found.'), Constants::ERR_NOT_FOUND);
		}
	}

	/**
	 * Everything the quota bar needs in one call.
	 *
	 * @return array{quotaMinutes: int, usedMinutes: int, remainingMinutes: int, percent: float, alert: bool, overdrawn: bool, negative: bool}
	 */
	public function snapshot(Agreement $agreement): array
	{
		$quotaMinutes = $this->quotaMinutes($agreement);
		$usedMinutes = $this->usedMinutes((int)$agreement->getId());

		return [
			'quotaMinutes' => $quotaMinutes,
			'usedMinutes' => $usedMinutes,
			'remainingMinutes' => $quotaMinutes - $usedMinutes,
			'percent' => round($this->usedPercent($usedMinutes, $quotaMinutes), 1),
			'alert' => $this->hasReachedAlertThreshold($usedMinutes, $quotaMinutes),
			'overdrawn' => $quotaMinutes > 0 && $usedMinutes > $quotaMinutes,
			// A7: mis-logged corrections can push the sum below zero; warn, don't block.
			'negative' => $usedMinutes < 0,
		];
	}

	// ── Writes ──────────────────────────────────────────────────────────

	/**
	 * Log one hours entry and, if it crosses 80 %, fire the one-time alert.
	 *
	 * Lock order (§4.8): agreement → hours → remlog → event.
	 *
	 * @throws ValidationException
	 */
	public function logHours(
		Agreement $agreement,
		\DateTimeInterface $entryDate,
		int $minutes,
		string $topic,
		string $actor,
	): HoursEntry {
		$this->assertPartnerAgreement($agreement);
		$this->assertQuarterHour($minutes);

		$topic = trim($topic);
		if ($topic === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'topic',
				$this->l10n->t('Describe what the time was spent on.'),
			);
		}
		if (mb_strlen($topic) > 255) {
			$topic = mb_substr($topic, 0, 255);
		}

		$agreementId = (int)$agreement->getId();
		$now = $this->timeFactory->getDateTime();

		$entry = new HoursEntry();
		$entry->setAgreementId($agreementId);
		$entry->setEntryDate(new \DateTime($entryDate->format('Y-m-d')));
		$entry->setMinutes($minutes);
		$entry->setTopic($topic);
		$entry->setCreatedBy($actor);
		$entry->setCreatedAt($now);

		$this->db->beginTransaction();
		try {
			$entry = $this->hoursMapper->insert($entry);
			$this->timelineService->append(
				(int)$agreement->getCompanyId(),
				'hours.logged',
				$actor,
				[
					'agreementId' => $agreementId,
					'minutes' => $minutes,
					'entryDate' => $entryDate->format('Y-m-d'),
				],
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->maybeFireQuotaAlert($agreement);

		return $entry;
	}

	/**
	 * Edit an hours entry within the 7-day window (§4.6 / S5).
	 * Older entries must use a compensating (negative) logHours call instead.
	 *
	 * Serialises concurrent edits with FOR UPDATE so billing minutes cannot
	 * last-write-wins past the lock window check.
	 *
	 * @param array{minutes?:int,topic?:string,entryDate?:string} $input
	 */
	public function updateEntry(int $entryId, array $input, string $actor): HoursEntry
	{
		$this->db->beginTransaction();
		try {
			if (!$this->hoursMapper->lockRow($entryId)) {
				throw new NotFoundException($this->l10n->t('Hours entry not found.'), Constants::ERR_NOT_FOUND);
			}
			$entry = $this->hoursMapper->findById($entryId);
			$now = $this->timeFactory->getDateTime();
			$this->assertEntryEditable($entry, $now);

			if (array_key_exists('minutes', $input)) {
				$this->assertQuarterHour((int)$input['minutes']);
				$entry->setMinutes((int)$input['minutes']);
			}
			if (array_key_exists('topic', $input)) {
				$topic = trim((string)$input['topic']);
				if ($topic === '') {
					throw ValidationException::field(
						Constants::ERR_VALIDATION,
						'topic',
						$this->l10n->t('Describe what the time was spent on.'),
					);
				}
				$entry->setTopic(mb_substr($topic, 0, 255));
			}
			if (array_key_exists('entryDate', $input) && $input['entryDate'] !== null && $input['entryDate'] !== '') {
				$date = DateMath::parseStrictDate((string)$input['entryDate']);
				if ($date === null) {
					throw ValidationException::field(Constants::ERR_VALIDATION, 'entryDate', $this->l10n->t('Use YYYY-MM-DD.'));
				}
				$entry->setEntryDate(new \DateTime($date->format('Y-m-d')));
			}

			$entry = $this->hoursMapper->update($entry);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $entry;
	}

	/**
	 * Fire the 80 % notification at most once per agreement.
	 *
	 * The ledger row is inserted *first*: a duplicate-key failure means another
	 * request (or an earlier job run) already owns this alert, so this call
	 * simply stands down. Losing the notification dispatch afterwards removes
	 * the row again so the next entry retries.
	 *
	 * @return bool true when this call sent the alert
	 */
	public function maybeFireQuotaAlert(Agreement $agreement): bool
	{
		$quotaMinutes = $this->quotaMinutes($agreement);
		if ($quotaMinutes <= 0) {
			return false;
		}

		$agreementId = (int)$agreement->getId();
		$usedMinutes = $this->usedMinutes($agreementId);
		if (!$this->hasReachedAlertThreshold($usedMinutes, $quotaMinutes)) {
			return false;
		}

		$ledgerRow = new ReminderLog();
		$ledgerRow->setAgreementId($agreementId);
		$ledgerRow->setOffsetDays(Constants::REMLOG_OFFSET_QUOTA80);
		$ledgerRow->setValidUntilSnap($this->quotaSnapshotDate($agreement));
		$ledgerRow->setSentAt($this->timeFactory->getDateTime());

		try {
			$ledgerRow = $this->reminderLogMapper->insert($ledgerRow);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}

		try {
			$company = $this->companyMapper->findById((int)$agreement->getCompanyId());
			$workspaceId = (int)($company->getWorkspaceId() ?? 0);
			$params = [
				'agreementId' => $agreementId,
				'companyId' => (int)$agreement->getCompanyId(),
				'label' => (string)$agreement->getLabel(),
				'percent' => (int)floor($this->usedPercent($usedMinutes, $quotaMinutes)),
			];
			if ($workspaceId > 0) {
				$this->notificationService->notifyWorkspaceUsers(
					$workspaceId,
					Constants::NOTIFY_QUOTA80,
					'agreement',
					(string)$agreementId,
					$params,
				);
			}
			// Orphan agreements without a workspace stay silent (fail closed).
		} catch (\Throwable $e) {
			// Notify failed — release so the next entry retries.
			$this->reminderLogMapper->delete($ledgerRow);
			throw $e;
		}

		// Timeline is best-effort AFTER a successful notify. Never release the
		// ledger here: that would duplicate the 80 % alert (AC5 / quota ledger).
		try {
			$this->timelineService->append(
				(int)$agreement->getCompanyId(),
				'quota.alert',
				'system',
				['agreementId' => $agreementId, 'usedMinutes' => $usedMinutes, 'quotaMinutes' => $quotaMinutes],
			);
		} catch (\Throwable) {
			// Audit trail failure must not re-arm the alert.
		}

		return true;
	}

	/**
	 * Quota alerts are per contract term, so they re-arm when a renewal moves
	 * `valid_until`. Open-ended partner packages use a fixed epoch anchor.
	 */
	private function quotaSnapshotDate(Agreement $agreement): \DateTime
	{
		$validUntil = $agreement->getValidUntil();
		if ($validUntil !== null) {
			return new \DateTime($validUntil->format('Y-m-d'));
		}

		return new \DateTime('1970-01-01');
	}

	/**
	 * Partner agreements with quota state for one workspace — digest / Today.
	 * Fail closed without a workspace (never blend books).
	 *
	 * @return list<array{agreement: Agreement, quotaMinutes: int, usedMinutes: int, percent: float, alert: bool}>
	 */
	public function partnerQuotaOverview(int $workspaceId, int $limit = 500): array
	{
		if ($workspaceId <= 0) {
			return [];
		}

		$agreements = $this->agreementMapper->findPartnerAgreements($workspaceId, $limit);
		if ($agreements === []) {
			return [];
		}

		$ids = array_map(static fn (Agreement $a): int => (int)$a->getId(), $agreements);
		$sums = $this->hoursMapper->sumMinutesForAgreements($ids);

		$out = [];
		foreach ($agreements as $agreement) {
			$quotaMinutes = $this->quotaMinutes($agreement);
			if ($quotaMinutes <= 0) {
				continue;
			}
			$usedMinutes = $sums[(int)$agreement->getId()] ?? 0;
			$out[] = [
				'agreement' => $agreement,
				'quotaMinutes' => $quotaMinutes,
				'usedMinutes' => $usedMinutes,
				'percent' => round($this->usedPercent($usedMinutes, $quotaMinutes), 1),
				'alert' => $this->hasReachedAlertThreshold($usedMinutes, $quotaMinutes),
			];
		}

		return $out;
	}
}
