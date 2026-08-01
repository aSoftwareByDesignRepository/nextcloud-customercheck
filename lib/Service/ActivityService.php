<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\CompanyScopedFk;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Activities — the single "what do I owe whom" list (§4.5, §8.3).
 *
 * Every activity carries a due date: a dateless note belongs on the timeline,
 * not in the work queue. `origin` records who created it so the UI can badge
 * automation-created rows as "Auto" (§14.2).
 */
class ActivityService
{
	public function __construct(
		private readonly ActivityMapper $activityMapper,
		private readonly AccessControlService $accessControl,
		private readonly TimelineService $timelineService,
		private readonly VTodoService $vTodoService,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly IDBConnection $db,
		private readonly DealMapper $dealMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly PersonMapper $personMapper,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Activity
	{
		try {
			return $this->activityMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('This activity no longer exists.'));
		}
	}

	/**
	 * @param array{companyId?: int, dealId?: int, type?: string, openOnly?: bool, doneOnly?: bool, dueBefore?: \DateTimeInterface} $filters
	 * @return Activity[]
	 */
	public function search(array $filters = [], int $limit = 500, int $offset = 0): array
	{
		return $this->activityMapper->search($filters, $limit, $offset);
	}

	/**
	 * @param array{companyId?: int, dealId?: int, type?: string, openOnly?: bool, doneOnly?: bool, dueBefore?: \DateTimeInterface} $filters
	 */
	public function count(array $filters = []): int
	{
		return $this->activityMapper->countSearch($filters);
	}

	/**
	 * Mobile may only see its own activities unless the caller is an app admin.
	 *
	 * @param array{companyId?: int, dealId?: int, type?: string, createdBy?: string, openOnly?: bool, doneOnly?: bool, dueBefore?: \DateTimeInterface, workspaceId?: int} $filters
	 * @return Activity[]
	 */
	public function searchForMobile(string $userId, array $filters = [], int $limit = 500, int $offset = 0): array
	{
		if (!$this->accessControl->isAppAdmin($userId)) {
			$filters['createdBy'] = $userId;
		}

		return $this->activityMapper->search($filters, $limit, $offset);
	}

	/**
	 * Company 360 lite uses the same visibility as the mobile list to avoid
	 * leaking counts for other users' open tasks.
	 *
	 * @param array{companyId?: int, dealId?: int, type?: string, createdBy?: string, openOnly?: bool, doneOnly?: bool, dueBefore?: \DateTimeInterface, workspaceId?: int} $filters
	 */
	public function countForMobile(string $userId, array $filters = []): int
	{
		if (!$this->accessControl->isAppAdmin($userId)) {
			$filters['createdBy'] = $userId;
		}

		return $this->activityMapper->countSearch($filters);
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 */
	public function create(array $input, string $actor): Activity
	{
		$companyId = (int)($input['companyId'] ?? 0);
		if ($companyId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'companyId',
				$this->l10n->t('Pick the company this activity belongs to.'),
			);
		}

		$now = $this->timeFactory->getDateTime();
		$activity = new Activity();
		$activity->setCompanyId($companyId);
		$activity->setCreatedBy($actor);
		$activity->setCreatedAt($now);
		$activity->setUpdatedAt($now);
		$activity->setOrigin($this->normaliseOrigin((string)($input['origin'] ?? 'user')));

		$this->applyInput($activity, $input);

		return $this->activityMapper->insert($activity);
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function update(int $id, array $input, string $actor, ?string $updatedAtPrecondition): Activity
	{
		$this->db->beginTransaction();
		try {
			// Serialize field edits — optimistic stamp alone loses to concurrent writers.
			if (!$this->activityMapper->lockRow($id)) {
				throw new NotFoundException($this->l10n->t('This activity no longer exists.'));
			}
			$activity = $this->activityMapper->findById($id);
			OptimisticLock::assertFresh($activity->getUpdatedAt(), $updatedAtPrecondition);

			$this->applyInput($activity, $input);
			$activity->setUpdatedAt($this->timeFactory->getDateTime());
			$stored = $this->activityMapper->update($activity);
			$this->db->commit();

			return $stored;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Toggle completion. Returns the stored entity so the UI can offer undo
	 * with the fresh `updatedAt` (§9.6). User-facing callers must echo `updatedAt`.
	 * CAS on updated_at so concurrent Done/Undo with the same token cannot both win.
	 */
	public function setDone(int $id, bool $done, string $actor, ?string $updatedAtPrecondition): Activity
	{
		$activity = $this->get($id);
		OptimisticLock::assertFresh($activity->getUpdatedAt(), $updatedAtPrecondition);

		$expectedUpdatedAt = $activity->getUpdatedAt();
		if ($expectedUpdatedAt === null) {
			throw new \OCA\CustomerCheck\Exception\ConflictException(
				$this->l10n->t('This activity changed. Reload and try again.'),
			);
		}

		$now = $this->timeFactory->getDateTime();
		$doneAt = $done ? $now : null;
		$affected = $this->activityMapper->updateDoneCas($id, $expectedUpdatedAt, $doneAt, $now);
		if ($affected !== 1) {
			throw new \OCA\CustomerCheck\Exception\ConflictException(
				$this->l10n->t('Someone else just updated this activity. Reload and try again.'),
			);
		}

		return $this->get($id);
	}

	/**
	 * App admins may act on every activity. Regular mobile users may only act on
	 * activities they created themselves.
	 *
	 * @throws \OCA\CustomerCheck\Exception\AccessDeniedException
	 */
	public function requireMobileAccess(int $activityId, string $userId): Activity
	{
		$activity = $this->get($activityId);
		if ($this->accessControl->isAppAdmin($userId)) {
			return $activity;
		}
		if ((string)$activity->getCreatedBy() !== $userId) {
			throw new \OCA\CustomerCheck\Exception\AccessDeniedException(
				$this->l10n->t('You may only change your own activities in the mobile app.')
			);
		}

		return $activity;
	}

	/**
	 * One-way VTODO push (§7.2). Idempotent: a second push is refused because
	 * `todo_uri` is already set. CAS claim prevents two concurrent pushes from
	 * each creating a calendar task.
	 *
	 * @return array{pushed: bool, todoUri: string|null, reason: string|null}
	 */
	public function pushToTasks(int $id, string $userId): array
	{
		$activity = $this->get($id);

		$existing = $activity->getTodoUri();
		if ($existing !== null && $existing !== '') {
			return ['pushed' => false, 'todoUri' => $existing, 'reason' => 'already_pushed'];
		}

		$result = $this->vTodoService->push($activity, $userId);
		if ($result === null) {
			return ['pushed' => false, 'todoUri' => null, 'reason' => 'no_writable_calendar'];
		}

		$now = $this->timeFactory->getDateTime();
		$claimed = $this->activityMapper->claimTodoUri($id, $result, $now);
		if ($claimed !== 1) {
			$fresh = $this->get($id);
			$uri = $fresh->getTodoUri();
			return [
				'pushed' => false,
				'todoUri' => ($uri !== null && $uri !== '') ? $uri : null,
				'reason' => 'already_pushed',
			];
		}

		return ['pushed' => true, 'todoUri' => $result, 'reason' => null];
	}

	/**
	 * Grace-end follow-up (§6.2): due the day after the grace period closed.
	 * Idempotent — the ledger already guarantees one grace-end reminder per
	 * agreement term, and the title probe protects against manual re-runs.
	 */
	public function createChurnDecisionTask(Agreement $agreement): ?Activity
	{
		$title = $this->l10n->t('Decide about churn after grace period');
		$agreementId = (int)$agreement->getId();

		if ($this->activityMapper->hasOpenSystemActivity($agreementId, $title)) {
			return null;
		}

		$now = $this->timeFactory->getDateTime();
		$activity = new Activity();
		$activity->setCompanyId((int)$agreement->getCompanyId());
		$activity->setAgreementId($agreementId);
		$activity->setType('todo');
		$activity->setTitle($title);
		$activity->setDetails($this->l10n->t(
			'The grace period for "%s" has ended. Cancel the agreement or record a late renewal.',
			[(string)$agreement->getLabel()],
		));
		$activity->setDueDate(new \DateTime(DateMath::addDays($now, 1)->format('Y-m-d')));
		$activity->setOrigin('system');
		$activity->setCreatedBy('system');
		$activity->setCreatedAt($now);
		$activity->setUpdatedAt($now);

		return $this->activityMapper->insert($activity);
	}

	/**
	 * "Generate / attach license key" task after an invoice-paid transition
	 * (§14.3). Idempotent by (agreement, title) on open activities.
	 */
	public function createAttachKeyTask(Agreement $agreement): ?Activity
	{
		$title = $this->l10n->t('Generate / attach license key');
		$agreementId = (int)$agreement->getId();

		if ($this->activityMapper->hasOpenSystemActivity($agreementId, $title)) {
			return null;
		}

		$now = $this->timeFactory->getDateTime();
		$activity = new Activity();
		$activity->setCompanyId((int)$agreement->getCompanyId());
		$activity->setAgreementId($agreementId);
		$activity->setType('todo');
		$activity->setTitle($title);
		$activity->setDetails($this->l10n->t(
			'The invoice for "%s" is paid but no license key is attached yet.',
			[(string)$agreement->getLabel()],
		));
		$activity->setDueDate(new \DateTime($now->format('Y-m-d')));
		$activity->setOrigin('system');
		$activity->setCreatedBy('system');
		$activity->setCreatedAt($now);
		$activity->setUpdatedAt($now);

		return $this->activityMapper->insert($activity);
	}

	/**
	 * Webhook intake task (§14.4): "Reply to inquiry", due today.
	 */
	public function createLeadReplyTask(int $companyId, int $dealId, string $type, ?string $details): Activity
	{
		$now = $this->timeFactory->getDateTime();
		$activity = new Activity();
		$activity->setCompanyId($companyId);
		$activity->setDealId($dealId);
		$activity->setType(in_array($type, Constants::ACTIVITY_TYPES, true) ? $type : 'email');
		$activity->setTitle($this->l10n->t('Reply to inquiry'));
		$activity->setDetails($details === null ? null : mb_substr($details, 0, 4000));
		$activity->setDueDate(new \DateTime($now->format('Y-m-d')));
		$activity->setOrigin('webhook');
		$activity->setCreatedBy('system');
		$activity->setCreatedAt($now);
		$activity->setUpdatedAt($now);

		return $this->activityMapper->insert($activity);
	}

	/**
	 * Bulk-dismiss remaining open follow-up activities for a deal (§14.2 / §11.4).
	 * Only sequence-originated open activities are closed; user-created ones stay.
	 * Each row uses updateDoneCas so concurrent Done/Undo cannot be clobbered.
	 *
	 * @return array{dismissed: int}
	 */
	public function dismissRemainingFollowUps(int $dealId, string $actor): array
	{
		if ($dealId <= 0) {
			return ['dismissed' => 0];
		}
		// All stage-sequence origins (§11.4) — contacted/quoted/negotiation/won.
		$open = $this->activityMapper->search(['dealId' => $dealId, 'openOnly' => true], 200);
		$now = $this->timeFactory->getDateTime();
		$dismissed = 0;
		foreach ($open as $activity) {
			$origin = (string)$activity->getOrigin();
			if (!str_starts_with($origin, 'sequence_')) {
				continue;
			}
			$expected = $activity->getUpdatedAt();
			if ($expected === null) {
				continue;
			}
			$affected = $this->activityMapper->updateDoneCas(
				(int)$activity->getId(),
				$expected,
				$now,
				$now,
			);
			if ($affected === 1) {
				$dismissed++;
			}
		}
		return ['dismissed' => $dismissed];
	}

	public function deleteForCompany(int $companyId): int
	{
		return $this->activityMapper->deleteByCompany($companyId);
	}

	/**
	 * Group open activities into the Today / Overdue / Later buckets the
	 * Activities page renders as visual sections (§9.8).
	 *
	 * @param Activity[] $activities
	 * @return array{overdue: list<Activity>, today: list<Activity>, later: list<Activity>}
	 */
	public function bucketByDueDate(array $activities, \DateTimeInterface $today): array
	{
		$buckets = ['overdue' => [], 'today' => [], 'later' => []];

		foreach ($activities as $activity) {
			$due = $activity->getDueDate();
			if ($due === null) {
				$buckets['later'][] = $activity;
				continue;
			}
			$delta = DateMath::daysBetween($today, $due);
			if ($delta < 0) {
				$buckets['overdue'][] = $activity;
			} elseif ($delta === 0) {
				$buckets['today'][] = $activity;
			} else {
				$buckets['later'][] = $activity;
			}
		}

		return $buckets;
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 */
	private function applyInput(Activity $activity, array $input): void
	{
		$fields = [];

		if (array_key_exists('type', $input)) {
			$type = (string)$input['type'];
			if (!in_array($type, Constants::ACTIVITY_TYPES, true)) {
				$fields['type'] = $this->l10n->t('Choose call, email, meeting or to-do.');
			} else {
				$activity->setType($type);
			}
		}

		if (array_key_exists('title', $input)) {
			$title = trim((string)$input['title']);
			if ($title === '') {
				$fields['title'] = $this->l10n->t('Give the activity a short title.');
			} elseif (mb_strlen($title) > 255) {
				$fields['title'] = $this->l10n->t('Keep the title under 256 characters.');
			} else {
				$activity->setTitle($title);
			}
		}

		if (array_key_exists('details', $input)) {
			$details = trim((string)($input['details'] ?? ''));
			if (mb_strlen($details) > 4000) {
				$fields['details'] = $this->l10n->t('Keep the details under 4000 characters.');
			} else {
				$activity->setDetails($details === '' ? null : $details);
			}
		}

		if (array_key_exists('dueDate', $input)) {
			$due = DateMath::parseStrictDate((string)($input['dueDate'] ?? ''));
			if ($due === null) {
				$fields['dueDate'] = $this->l10n->t('Enter the due date as YYYY-MM-DD.');
			} else {
				$activity->setDueDate(new \DateTime($due->format('Y-m-d')));
			}
		}

		if (array_key_exists('dealId', $input)) {
			$dealId = (int)($input['dealId'] ?? 0);
			if ($dealId <= 0) {
				$activity->setDealId(null);
			} else {
				try {
					$deal = $this->dealMapper->findById($dealId);
				} catch (DoesNotExistException) {
					throw ValidationException::field(
						Constants::ERR_VALIDATION,
						'dealId',
						$this->l10n->t('Pick a deal that belongs to this company.'),
					);
				}
				CompanyScopedFk::assertSameCompany(
					(int)$activity->getCompanyId(),
					(int)$deal->getCompanyId(),
					'dealId',
					$this->l10n,
					$this->l10n->t('Pick a deal that belongs to this company.'),
				);
				$activity->setDealId($dealId);
			}
		}

		if (array_key_exists('agreementId', $input)) {
			$agreementId = (int)($input['agreementId'] ?? 0);
			if ($agreementId <= 0) {
				$activity->setAgreementId(null);
			} else {
				try {
					$agreement = $this->agreementMapper->findById($agreementId);
				} catch (DoesNotExistException) {
					throw ValidationException::field(
						Constants::ERR_VALIDATION,
						'agreementId',
						$this->l10n->t('Pick an agreement that belongs to this company.'),
					);
				}
				CompanyScopedFk::assertSameCompany(
					(int)$activity->getCompanyId(),
					(int)$agreement->getCompanyId(),
					'agreementId',
					$this->l10n,
					$this->l10n->t('Pick an agreement that belongs to this company.'),
				);
				$activity->setAgreementId($agreementId);
			}
		}

		if (array_key_exists('personId', $input)) {
			$personId = (int)($input['personId'] ?? 0);
			if ($personId <= 0) {
				$activity->setPersonId(null);
			} else {
				try {
					$person = $this->personMapper->findById($personId);
				} catch (DoesNotExistException) {
					throw ValidationException::field(
						Constants::ERR_VALIDATION,
						'personId',
						$this->l10n->t('Pick a person on this company.'),
					);
				}
				CompanyScopedFk::assertSameCompany(
					(int)$activity->getCompanyId(),
					(int)$person->getCompanyId(),
					'personId',
					$this->l10n,
					$this->l10n->t('Pick a person on this company.'),
				);
				$activity->setPersonId($personId);
			}
		}

		if (array_key_exists('isCallback', $input)) {
			$flag = $input['isCallback'];
			$on = $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
			$activity->setIsCallback($on ? 1 : 0);
		}

		if ($activity->getDueDate() === null) {
			$fields['dueDate'] = $this->l10n->t('Every activity needs a due date. Use a timeline note for undated remarks.');
		}
		if ((string)$activity->getTitle() === '') {
			$fields['title'] = $this->l10n->t('Give the activity a short title.');
		}

		if ($fields !== []) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Please correct the highlighted fields.'),
				$fields,
			);
		}
	}

	private function normaliseOrigin(string $origin): string
	{
		return in_array($origin, Constants::ACTIVITY_ORIGINS, true) ? $origin : 'user';
	}

	/**
	 * Timeline note helper so callers do not need the timeline service directly.
	 */
	public function logNote(int $companyId, string $note, string $actor): void
	{
		$this->timelineService->append($companyId, 'note', $actor, [
			'note' => mb_substr(trim($note), 0, 1000),
			'app' => Application::APP_ID,
		]);
	}
}
