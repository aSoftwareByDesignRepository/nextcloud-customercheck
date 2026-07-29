<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Support\Ics;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * One-way VTODO export for activities (§7.2).
 *
 * The push is explicit and one-way on purpose: two-way task sync is out of
 * scope for v1, so the CRM stays the source of truth and Tasks/Calendar gets a
 * snapshot. If the Calendar app is missing or no writable calendar exists, the
 * button is simply reported as unavailable — never a hard failure.
 */
class VTodoService
{
	public function __construct(
		private readonly ICalendarManager $calendarManager,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(string $userId): bool
	{
		return $this->pickCalendar($userId) !== null;
	}

	/**
	 * @return string|null the created object URI, or null when no writable calendar exists
	 */
	public function push(Activity $activity, string $userId): ?string
	{
		$calendar = $this->pickCalendar($userId);
		if ($calendar === null) {
			return null;
		}

		$uid = sprintf('customercheck-activity-%d@%s', (int)$activity->getId(), Application::APP_ID);
		$objectName = $uid . '.ics';

		try {
			$calendar->createFromString($objectName, $this->buildVTodo($activity, $uid));
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck could not create VTODO', [
				'app' => Application::APP_ID,
				'activityId' => (int)$activity->getId(),
				'exception' => $e,
			]);

			return null;
		}

		return $calendar->getUri() . '/' . $objectName;
	}

	/**
	 * @return list<array{uri: string, name: string}>
	 */
	public function listWritableCalendars(string $userId): array
	{
		$out = [];
		foreach ($this->calendarsFor($userId) as $calendar) {
			$out[] = ['uri' => $calendar->getUri(), 'name' => $calendar->getDisplayName() ?? $calendar->getUri()];
		}

		return $out;
	}

	private function pickCalendar(string $userId): ?ICreateFromString
	{
		if ($userId === '') {
			return null;
		}

		$calendars = $this->calendarsFor($userId);
		if ($calendars === []) {
			return null;
		}

		$preferred = trim($this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_VTODO_CALENDAR_URI,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_VTODO_CALENDAR_URI],
		));

		if ($preferred !== '') {
			foreach ($calendars as $calendar) {
				if ($calendar->getUri() === $preferred) {
					return $calendar;
				}
			}
		}

		return $calendars[0];
	}

	/**
	 * @return list<ICreateFromString>
	 */
	private function calendarsFor(string $userId): array
	{
		try {
			$calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $userId);
		} catch (\Throwable $e) {
			$this->logger->debug('CustomerCheck could not enumerate calendars', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return [];
		}

		$writable = [];
		foreach ($calendars as $calendar) {
			if (!$calendar instanceof ICreateFromString) {
				continue;
			}
			if ($calendar->isDeleted()) {
				continue;
			}
			if ($calendar instanceof ICalendarIsWritable && !$calendar->isWritable()) {
				continue;
			}
			$writable[] = $calendar;
		}

		return $writable;
	}

	private function buildVTodo(Activity $activity, string $uid): string
	{
		$due = $activity->getDueDate();
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Software by Design//CustomerCheck//EN',
			'BEGIN:VTODO',
			'UID:' . $uid,
			'DTSTAMP:' . gmdate('Ymd\THis\Z'),
			'SUMMARY:' . Ics::escapeText((string)$activity->getTitle()),
		];

		if ($due !== null) {
			$lines[] = 'DUE;VALUE=DATE:' . $due->format('Ymd');
		}

		$details = $activity->getDetails();
		if ($details !== null && $details !== '') {
			$lines[] = 'DESCRIPTION:' . Ics::escapeText($details);
		}

		$lines[] = 'STATUS:' . ($activity->getDoneAt() === null ? 'NEEDS-ACTION' : 'COMPLETED');
		$lines[] = 'END:VTODO';
		$lines[] = 'END:VCALENDAR';

		return Ics::fold($lines);
	}
}
