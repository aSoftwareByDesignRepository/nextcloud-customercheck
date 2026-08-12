<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\SeqLog;
use OCA\CustomerCheck\Db\SeqLogMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\DbTransaction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Fixed stage follow-up sequences — PSD §14.2.
 *
 * Entering `quoted` creates three follow-ups (+3/+7/+14 days); entering `won`
 * creates the delivery checklist. Re-entering the same stage must never
 * duplicate them, which is why the `crm_seq_log` unique index on
 * (deal_id, sequence_id) is claimed *before* any activity is written: the
 * database decides who owns the sequence, not a read-then-write race.
 *
 * Claim + activity inserts run in one transaction so a mid-flight failure
 * rolls back the claim and a later retry can recreate the follow-ups.
 *
 * Moving away from a stage deliberately leaves open follow-ups alone — the work
 * may still be owed. The deal view offers a bulk dismiss instead (§14.2).
 */
class StageSequenceService
{
	/** @var array<string, string> stage entered => sequence id */
	private const STAGE_SEQUENCES = [
		'contacted' => Constants::SEQUENCE_CONTACTED,
		'quoted' => Constants::SEQUENCE_QUOTED,
		'negotiation' => Constants::SEQUENCE_NEGOTIATION,
		'won' => Constants::SEQUENCE_WON,
	];

	public function __construct(
		private readonly SeqLogMapper $seqLogMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	public function sequenceIdForStage(string $stage): ?string
	{
		return self::STAGE_SEQUENCES[$stage] ?? null;
	}

	/**
	 * Fire the sequence attached to the stage just entered.
	 *
	 * @return list<string> sequence ids actually fired by this call (empty when
	 *                      the stage has no sequence or it already fired)
	 */
	public function fireForStage(Deal $deal, string $stage, string $actor): array
	{
		$sequenceId = $this->sequenceIdForStage($stage);
		if ($sequenceId === null) {
			return [];
		}

		$dealId = (int)$deal->getId();
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			if (!$this->claimSequence($dealId, $sequenceId)) {
				DbTransaction::commit($this->db, $ownsTxn);
				return [];
			}

			foreach ($this->stepsFor($sequenceId) as $step) {
				$this->createActivity($deal, $sequenceId, $step['title'], $step['type'], $step['offsetDays'], $actor);
			}
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		return [$sequenceId];
	}

	public function hasFired(int $dealId, string $sequenceId): bool
	{
		return $this->seqLogMapper->hasFired($dealId, $sequenceId);
	}

	/**
	 * The literal §14.2 table. Kept as data so the acceptance test can assert
	 * "exactly three follow-ups at +3/+7/+14" against one source of truth.
	 *
	 * @return list<array{title: string, type: string, offsetDays: int}>
	 */
	public function stepsFor(string $sequenceId): array
	{
		if ($sequenceId === Constants::SEQUENCE_CONTACTED) {
			return [
				['title' => $this->l10n->t('Qualify opportunity'), 'type' => 'call', 'offsetDays' => 1],
				['title' => $this->l10n->t('Send materials'), 'type' => 'email', 'offsetDays' => 3],
			];
		}

		if ($sequenceId === Constants::SEQUENCE_QUOTED) {
			return [
				['title' => $this->l10n->t('Follow up quote'), 'type' => 'call', 'offsetDays' => 3],
				['title' => $this->l10n->t('Follow up quote'), 'type' => 'call', 'offsetDays' => 7],
				['title' => $this->l10n->t('Follow up quote'), 'type' => 'call', 'offsetDays' => 14],
			];
		}

		if ($sequenceId === Constants::SEQUENCE_NEGOTIATION) {
			return [
				['title' => $this->l10n->t('Negotiation check-in'), 'type' => 'call', 'offsetDays' => 2],
				['title' => $this->l10n->t('Close or next step'), 'type' => 'call', 'offsetDays' => 5],
			];
		}

		if ($sequenceId === Constants::SEQUENCE_WON) {
			return [
				['title' => $this->l10n->t('Deliver license key / send delivery pack'), 'type' => 'email', 'offsetDays' => 0],
				['title' => $this->l10n->t('Confirm customer pasted key'), 'type' => 'call', 'offsetDays' => 2],
				['title' => $this->l10n->t('Send welcome / onboarding mail'), 'type' => 'email', 'offsetDays' => 3],
			];
		}

		return [];
	}

	public function originForSequence(string $sequenceId): string
	{
		return match ($sequenceId) {
			Constants::SEQUENCE_WON => 'sequence_won',
			Constants::SEQUENCE_CONTACTED => 'sequence_contacted',
			Constants::SEQUENCE_NEGOTIATION => 'sequence_negotiation',
			default => 'sequence_quoted',
		};
	}

	/**
	 * Claim the (deal, sequence) slot. The unique index absorbs concurrent
	 * stage changes, so a duplicate-key failure is a definitive "someone else
	 * already created these activities".
	 */
	private function claimSequence(int $dealId, string $sequenceId): bool
	{
		$row = new SeqLog();
		$row->setDealId($dealId);
		$row->setSequenceId($sequenceId);
		$row->setCreatedAt($this->timeFactory->getDateTime());

		try {
			$this->seqLogMapper->insert($row);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	private function createActivity(
		Deal $deal,
		string $sequenceId,
		string $title,
		string $type,
		int $offsetDays,
		string $actor,
	): Activity {
		$now = $this->timeFactory->getDateTime();
		$due = DateMath::addDays($now, $offsetDays);

		$activity = new Activity();
		$activity->setCompanyId((int)$deal->getCompanyId());
		$activity->setDealId((int)$deal->getId());
		$activity->setType($type);
		$activity->setTitle($title);
		$activity->setDetails($this->l10n->t('Automatic follow-up for deal "%s".', [(string)$deal->getTitle()]));
		$activity->setDueDate(new \DateTime($due->format('Y-m-d')));
		$activity->setOrigin($this->originForSequence($sequenceId));
		$activity->setCreatedBy($actor);
		$activity->setCreatedAt($now);
		$activity->setUpdatedAt($now);

		return $this->activityMapper->insert($activity);
	}
}
