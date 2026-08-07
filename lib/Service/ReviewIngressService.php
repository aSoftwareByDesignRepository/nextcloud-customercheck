<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;

/**
 * Opt-in Review source ingress — GNQ §5.2.
 *
 * Hard rules: no personal IMAP; no whole-FS crawl; Contacts scan only when
 * enabled + DSGVO + workspace-member scanActor. File/Calendar/Tasks use the
 * same member-scoped pattern.
 */
class ReviewIngressService
{
	public function __construct(
		private readonly WorkspaceService $workspaces,
		private readonly ReviewService $review,
		private readonly IRootFolder $rootFolder,
		private readonly IL10N $l10n,
		private readonly ?ICalendarManager $calendars = null,
		private readonly ?ContactsSuggestService $contactsSuggest = null,
		private readonly ?\OCA\CustomerCheck\Db\PersonMapper $persons = null,
	) {
	}

	/**
	 * Background sweep across all workspaces.
	 */
	public function syncAll(): int
	{
		$added = 0;
		foreach ($this->workspaces->listAll() as $ws) {
			$added += $this->syncWorkspace((int)$ws->getId());
		}

		return $added;
	}

	public function syncWorkspace(int $workspaceId): int
	{
		if ($workspaceId <= 0) {
			return 0;
		}
		$sources = $this->workspaces->getReviewSources($workspaceId);
		$added = 0;

		if (!empty($sources['shared_mail']['enabled'])) {
			$added += $this->review->syncSharedMail($workspaceId);
		}

		if (!empty($sources['contact_suggest']['enabled'])) {
			$added += $this->ingressContacts($workspaceId, $sources['contact_suggest']);
		}

		if (!empty($sources['file']['enabled'])) {
			$added += $this->ingressFiles($workspaceId, $sources['file']);
		}

		if (!empty($sources['calendar_event']['enabled'])) {
			$added += $this->ingressCalendar($workspaceId, $sources['calendar_event'], false);
		}

		if (!empty($sources['task']['enabled'])) {
			$added += $this->ingressCalendar($workspaceId, $sources['task'], true);
		}

		return $added;
	}

	/**
	 * Periodic Contacts → Review for ORG/email candidates without a person link (GNQ §5.2).
	 *
	 * @param array<string, mixed> $cfg
	 */
	private function ingressContacts(int $workspaceId, array $cfg): int
	{
		if ($this->contactsSuggest === null || $this->persons === null) {
			return 0;
		}
		$scanActor = trim((string)($cfg['scanActor'] ?? ''));
		if ($scanActor === '' || empty($cfg['dsgvoAckAt'])) {
			return 0;
		}
		if (!$this->isWorkspaceMember($workspaceId, $scanActor)) {
			return 0;
		}

		$added = 0;
		foreach ($this->contactsSuggest->scanCandidatesAs($scanActor, 40) as $row) {
			$email = isset($row['email']) ? trim((string)$row['email']) : '';
			$uid = isset($row['uid']) ? trim((string)$row['uid']) : '';
			$name = trim((string)($row['name'] ?? ''));
			$org = isset($row['org']) ? trim((string)$row['org']) : '';
			if ($email === '' && $uid === '') {
				continue;
			}
			if ($email !== '') {
				$linked = $this->persons->findByEmail($email, $workspaceId);
				if ($linked !== []) {
					continue;
				}
			}
			$refKey = $uid !== '' ? $uid : strtolower($email);
			$result = $this->review->enqueueSourceItem(
				$workspaceId,
				'contact_suggest',
				$refKey,
				[
					'uid' => $uid !== '' ? $uid : null,
					'email' => $email !== '' ? $email : null,
					'scanActor' => $scanActor,
				],
				$name !== '' ? $name : ($email !== '' ? $email : $this->l10n->t('Contact')),
				$email !== '' ? $email : $org,
				$email !== '' ? $email : null,
				$org !== '' ? $org : null,
			);
			if (!empty($result['added'])) {
				$added++;
			}
		}

		return $added;
	}

	/**
	 * @param array<string, mixed> $cfg
	 */
	private function ingressFiles(int $workspaceId, array $cfg): int
	{
		$folderId = (int)($cfg['rootFolderId'] ?? 0);
		$scanActor = trim((string)($cfg['scanActor'] ?? ''));
		if ($folderId <= 0 || $scanActor === '' || empty($cfg['dsgvoAckAt'])) {
			return 0;
		}
		if (!$this->isWorkspaceMember($workspaceId, $scanActor)) {
			return 0;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($scanActor);
			$nodes = $userFolder->getById($folderId);
		} catch (\Throwable) {
			return 0;
		}
		if ($nodes === [] || !($nodes[0] instanceof Folder)) {
			return 0;
		}
		/** @var Folder $folder */
		$folder = $nodes[0];

		$added = 0;
		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable) {
			return 0;
		}
		$count = 0;
		foreach ($children as $node) {
			if ($count >= 50) {
				break;
			}
			if ($node->getType() === FileInfo::TYPE_FOLDER) {
				continue;
			}
			$count++;
			$fileId = (int)$node->getId();
			$name = (string)$node->getName();
			$result = $this->review->enqueueSourceItem(
				$workspaceId,
				'file',
				(string)$fileId,
				[
					'fileId' => $fileId,
					'path' => $node->getPath(),
					'mime' => (string)$node->getMimetype(),
				],
				$name !== '' ? $name : $this->l10n->t('File %s', [(string)$fileId]),
				(string)$node->getMimetype(),
				null,
				pathinfo($name, PATHINFO_FILENAME) ?: null,
			);
			if (!empty($result['added'])) {
				$added++;
			}
		}

		return $added;
	}

	/**
	 * Calendar events or VTODOs from manager-selected calendar URIs (GNQ §5.2).
	 *
	 * @param array<string, mixed> $cfg
	 */
	private function ingressCalendar(int $workspaceId, array $cfg, bool $tasksOnly): int
	{
		if ($this->calendars === null || empty($cfg['dsgvoAckAt'])) {
			return 0;
		}
		$scanActor = trim((string)($cfg['scanActor'] ?? ''));
		$uris = $tasksOnly
			? (is_array($cfg['listIds'] ?? null) ? $cfg['listIds'] : [])
			: (is_array($cfg['calendarUris'] ?? null) ? $cfg['calendarUris'] : []);
		$uriSet = [];
		foreach ($uris as $uri) {
			$u = trim((string)$uri);
			if ($u !== '') {
				$uriSet[$u] = true;
			}
		}
		if ($scanActor === '' || $uriSet === [] || !$this->isWorkspaceMember($workspaceId, $scanActor)) {
			return 0;
		}

		try {
			$cals = $this->calendars->getCalendarsForPrincipal('principals/users/' . $scanActor);
		} catch (\Throwable) {
			return 0;
		}

		$kind = $tasksOnly ? 'task' : 'calendar_event';
		$start = new \DateTimeImmutable('today');
		$end = $start->modify('+30 days');
		$added = 0;
		$seen = 0;
		foreach ($cals as $calendar) {
			if (!$calendar instanceof ICalendar) {
				continue;
			}
			$calUri = (string)$calendar->getUri();
			if (!isset($uriSet[$calUri]) && !isset($uriSet[(string)$calendar->getKey()])) {
				continue;
			}
			try {
				$hits = $calendar->search('', [], [
					'timerange' => [
						'start' => \DateTime::createFromImmutable($start),
						'end' => \DateTime::createFromImmutable($end),
					],
				], 40);
			} catch (\Throwable) {
				continue;
			}
			if (!is_array($hits)) {
				continue;
			}
			foreach ($hits as $hit) {
				if ($seen >= 40) {
					break 2;
				}
				$seen++;
				$summary = '';
				$uid = '';
				$isTodo = false;
				$attendeeEmail = null;
				if (is_array($hit)) {
					$summary = (string)($hit['SUMMARY'] ?? $hit['summary'] ?? '');
					$uid = (string)($hit['UID'] ?? $hit['uid'] ?? '');
					$isTodo = isset($hit['VTODO']) || (($hit['@type'] ?? '') === 'VTODO');
					$attendeeEmail = self::extractAttendeeEmail($hit);
					if (!$isTodo && isset($hit['objects']) && is_array($hit['objects'])) {
						foreach ($hit['objects'] as $obj) {
							if (is_array($obj) && (($obj['name'] ?? '') === 'VTODO')) {
								$isTodo = true;
							}
							if (is_array($obj) && ($summary === '') && isset($obj['SUMMARY'][0]['value'])) {
								$summary = (string)$obj['SUMMARY'][0]['value'];
							}
							if (is_array($obj) && ($uid === '') && isset($obj['UID'][0]['value'])) {
								$uid = (string)$obj['UID'][0]['value'];
							}
							if ($attendeeEmail === null && is_array($obj)) {
								$attendeeEmail = self::extractAttendeeEmail($obj);
							}
						}
					}
				}
				if ($tasksOnly !== $isTodo && $tasksOnly) {
					// Prefer VTODO when task source; skip pure VEVENT.
					if (!$isTodo) {
						continue;
					}
				}
				if (!$tasksOnly && $isTodo) {
					continue;
				}
				if ($uid === '') {
					$uid = hash('sha256', $calUri . '|' . $summary . '|' . $seen);
				}
				$result = $this->review->enqueueSourceItem(
					$workspaceId,
					$kind,
					$uid,
					[
						'calendarUri' => $calUri,
						'uid' => $uid,
						'scanActor' => $scanActor,
						'email' => $attendeeEmail,
					],
					$summary !== '' ? $summary : $this->l10n->t('Calendar item'),
					$calUri,
					$attendeeEmail,
					$summary !== '' ? $summary : null,
				);
				if (!empty($result['added'])) {
					$added++;
				}
			}
		}

		return $added;
	}

	private function isWorkspaceMember(int $workspaceId, string $userId): bool
	{
		foreach ($this->workspaces->listMembers($workspaceId) as $member) {
			if ((string)$member->getUserId() === $userId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First usable ATTENDEE mailto for Review match scoring (GNQ §8.2 calendar edge).
	 * Skips ORGANIZER when another attendee exists.
	 *
	 * @param array<string, mixed> $hit
	 */
	public static function extractAttendeeEmail(array $hit): ?string
	{
		$organizer = self::mailtoFromValue($hit['ORGANIZER'] ?? $hit['organizer'] ?? null);
		$candidates = [];
		foreach (['ATTENDEE', 'attendee', 'ATTENDEES'] as $key) {
			if (!isset($hit[$key])) {
				continue;
			}
			$raw = $hit[$key];
			if (!is_array($raw) || self::isAssocMailtoRow($raw)) {
				$email = self::mailtoFromValue($raw);
				if ($email !== null) {
					$candidates[] = $email;
				}
				continue;
			}
			foreach ($raw as $row) {
				$email = self::mailtoFromValue($row);
				if ($email !== null) {
					$candidates[] = $email;
				}
			}
		}
		if (isset($hit['properties']) && is_array($hit['properties'])) {
			foreach ($hit['properties'] as $prop) {
				if (!is_array($prop)) {
					continue;
				}
				$name = strtoupper((string)($prop['name'] ?? $prop['key'] ?? ''));
				if ($name === 'ORGANIZER' && $organizer === null) {
					$organizer = self::mailtoFromValue($prop['value'] ?? $prop);
				}
				if ($name === 'ATTENDEE') {
					$email = self::mailtoFromValue($prop['value'] ?? $prop);
					if ($email !== null) {
						$candidates[] = $email;
					}
				}
			}
		}
		$candidates = array_values(array_unique($candidates));
		if ($candidates === []) {
			return null;
		}
		foreach ($candidates as $email) {
			if ($organizer === null || $email !== $organizer) {
				return $email;
			}
		}

		return $candidates[0];
	}

	/** @param mixed $raw */
	private static function mailtoFromValue(mixed $raw): ?string
	{
		if (is_array($raw)) {
			if (isset($raw[0]) && is_array($raw[0]) && array_key_exists('value', $raw[0])) {
				return self::mailtoFromValue($raw[0]['value']);
			}
			if (array_key_exists('value', $raw)) {
				return self::mailtoFromValue($raw['value']);
			}
			if (array_key_exists('email', $raw)) {
				return self::mailtoFromValue($raw['email']);
			}

			return null;
		}
		$s = trim((string)$raw);
		if ($s === '') {
			return null;
		}
		if (preg_match('/mailto:([^;?>\s]+)/i', $s, $m) === 1) {
			$s = urldecode($m[1]);
		}
		$s = mb_strtolower(trim($s));
		if (filter_var($s, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		return $s;
	}

	/** @param array<string|int, mixed> $raw */
	private static function isAssocMailtoRow(array $raw): bool
	{
		return array_key_exists('value', $raw) || array_key_exists('email', $raw);
	}
}
