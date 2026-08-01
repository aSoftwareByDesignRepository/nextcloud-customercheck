<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\IL10N;

/**
 * Schedule callback activities — COMMAND-CENTER-SPEC §9.2.
 */
class CallbackService
{
	public function __construct(
		private readonly ActivityService $activities,
		private readonly PersonService $persons,
		private readonly CompanyService $companies,
		private readonly TimelineService $timeline,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @param array{personId?: int|null, dueDate?: string|null, reason?: string|null, title?: string|null} $input
	 * @throws ValidationException
	 */
	public function schedule(int $companyId, array $input, string $actor): Activity
	{
		$this->companies->get($companyId);

		$dueRaw = trim((string)($input['dueDate'] ?? ''));
		if ($dueRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueRaw)) {
			throw ValidationException::field(
				Constants::ERR_CALLBACK_DUE_REQUIRED,
				'dueDate',
				$this->l10n->t('Pick a due date for the callback.'),
			);
		}

		$personId = isset($input['personId']) ? (int)$input['personId'] : 0;
		$personName = null;
		if ($personId > 0) {
			$person = $this->persons->get($personId);
			if ((int)$person->getCompanyId() !== $companyId) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'personId',
					$this->l10n->t('Pick a person on this company.'),
				);
			}
			$personName = (string)$person->getName();
		}

		$company = $this->companies->get($companyId);
		$defaultTitle = $personName !== null
			? $this->l10n->t('Callback: %s', [$personName])
			: $this->l10n->t('Callback: %s', [(string)$company->getName()]);
		$title = trim((string)($input['title'] ?? ''));
		if ($title === '') {
			$title = $defaultTitle;
		}
		$reason = trim((string)($input['reason'] ?? ''));

		$activity = $this->activities->create([
			'companyId' => $companyId,
			'type' => 'call',
			'title' => $title,
			'details' => $reason === '' ? null : mb_substr($reason, 0, 4000),
			'dueDate' => $dueRaw,
			'isCallback' => 1,
			'personId' => $personId > 0 ? $personId : null,
			'origin' => 'user',
		], $actor);

		$this->timeline->append($companyId, 'note', $actor, [
			'callbackScheduled' => true,
			'activityId' => (int)$activity->getId(),
			'dueDate' => $dueRaw,
			'personId' => $personId > 0 ? $personId : null,
		]);

		return $activity;
	}

	public function isCallbackActivity(Activity $activity): bool
	{
		if ($activity->isCallback()) {
			return true;
		}
		if ((string)$activity->getType() === 'call') {
			return true;
		}
		$title = (string)$activity->getTitle();
		$prefixes = [
			'Callback:',
			$this->l10n->t('Callback:'),
		];
		foreach ($prefixes as $prefix) {
			if ($prefix !== '' && str_starts_with($title, $prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Default due date = tomorrow (server TZ date).
	 */
	public function defaultDueDate(\DateTimeInterface $today): string
	{
		return DateMath::addDays($today, 1)->format('Y-m-d');
	}
}
