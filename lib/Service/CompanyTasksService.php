<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Company open-tasks panel — COMMAND-CENTER-SPEC §9.5.
 */
class CompanyTasksService
{
	public function __construct(
		private readonly CompanyService $companies,
		private readonly ActivityService $activities,
		private readonly PersonService $persons,
		private readonly VTodoService $vTodo,
		private readonly TasksDeepLinkBuilder $deepLinks,
		private readonly IAppManager $appManager,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array{items: list<array<string, mixed>>, capabilities: array{tasks: bool}, tasksAppUrl: string|null}
	 */
	public function list(int $companyId, string $userId, bool $includeDone = false): array
	{
		$this->companies->get($companyId);
		$filters = ['companyId' => $companyId];
		if (!$includeDone) {
			$filters['openOnly'] = true;
		}
		$activities = $this->activities->search($filters, 200);
		$persons = [];
		foreach ($this->persons->listForCompany($companyId) as $p) {
			$persons[(int)$p->getId()] = (string)$p->getName();
		}

		$cutoff = DateMath::addDays($this->timeFactory->getDateTime(), -30);
		$items = [];
		foreach ($activities as $act) {
			if ($includeDone && !$act->isOpen()) {
				$doneAt = $act->getDoneAt();
				if ($doneAt !== null && $doneAt < $cutoff) {
					continue;
				}
			}
			$items[] = $this->row($act, $persons);
		}

		$tasksCapable = $this->vTodo->isAvailable($userId) || $this->appManager->isEnabledForUser('tasks');

		return [
			'items' => $items,
			'capabilities' => ['tasks' => $tasksCapable],
			'tasksAppUrl' => $tasksCapable ? $this->deepLinks->tasksHomeUrl() : null,
		];
	}

	/**
	 * @param array{title?: string, type?: string, dueDate?: string, personId?: int|null, pushToTasks?: bool, calendarUri?: string|null} $input
	 * @return array{activity: array<string, mixed>, todoUri: ?string, tasksDeepLink: ?string, pushError: ?string}
	 */
	public function create(int $companyId, array $input, string $userId): array
	{
		$this->companies->get($companyId);
		$title = trim((string)($input['title'] ?? ''));
		if ($title === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'title',
				$this->l10n->t('Give the task a title.'),
			);
		}
		$due = trim((string)($input['dueDate'] ?? ''));
		if ($due === '') {
			$due = DateMath::addDays($this->timeFactory->getDateTime(), 1)->format('Y-m-d');
		}
		$type = (string)($input['type'] ?? 'todo');
		if (!in_array($type, Constants::ACTIVITY_TYPES, true)) {
			$type = 'todo';
		}
		$personId = isset($input['personId']) ? (int)$input['personId'] : 0;
		if ($personId > 0) {
			$person = $this->persons->get($personId);
			if ((int)$person->getCompanyId() !== $companyId) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'personId',
					$this->l10n->t('Pick a person on this company.'),
				);
			}
		}

		$activity = $this->activities->create([
			'companyId' => $companyId,
			'title' => $title,
			'type' => $type,
			'dueDate' => $due,
			'personId' => $personId > 0 ? $personId : null,
		], $userId);

		$pushRequested = !empty($input['pushToTasks']);
		$tasksCapable = $this->vTodo->isAvailable($userId);
		$todoUri = null;
		$pushError = null;
		$deepLink = null;

		if ($pushRequested) {
			if (!$tasksCapable) {
				$pushError = Constants::ERR_TASKS_UNAVAILABLE;
			} else {
				$result = $this->activities->pushToTasks((int)$activity->getId(), $userId);
				if ($result['todoUri'] !== null) {
					$todoUri = $result['todoUri'];
					$deepLink = $this->deepLinks->fromTodoUri($todoUri)['url'];
					$activity = $this->activities->get((int)$activity->getId());
				} else {
					$pushError = Constants::ERR_TASKS_PUSH_FAILED;
				}
			}
		}

		$persons = [];
		if ($personId > 0) {
			$persons[$personId] = (string)$this->persons->get($personId)->getName();
		}

		return [
			'activity' => $this->row($activity, $persons),
			'todoUri' => $todoUri ?? $activity->getTodoUri(),
			'tasksDeepLink' => $deepLink,
			'pushError' => $pushError,
		];
	}

	/**
	 * @param array<int, string> $persons
	 * @return array<string, mixed>
	 */
	private function row(Activity $act, array $persons): array
	{
		$todoUri = $act->getTodoUri();
		$link = $this->deepLinks->fromTodoUri($todoUri);

		return [
			'activityId' => (int)$act->getId(),
			'title' => (string)$act->getTitle(),
			'type' => (string)$act->getType(),
			'isCallback' => $act->isCallback(),
			'dueDate' => $act->getDueDate()?->format('Y-m-d'),
			'done' => !$act->isOpen(),
			'personId' => $act->getPersonId(),
			'personName' => isset($persons[(int)($act->getPersonId() ?? 0)])
				? $persons[(int)$act->getPersonId()]
				: null,
			'todoUri' => $todoUri,
			'tasksDeepLink' => $todoUri ? $link['url'] : null,
			'inTasks' => $todoUri !== null && $todoUri !== '',
			'updatedAt' => $act->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}
}
