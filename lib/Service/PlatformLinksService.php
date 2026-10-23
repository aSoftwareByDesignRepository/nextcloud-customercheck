<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Official Nextcloud platform hub chips — COMMAND-CENTER-SPEC §16.4.
 *
 * Chips are shortcuts to sibling apps on this Nextcloud. They must never
 * advertise Calendar/Tasks/Files (or any other app) that is not installed
 * and enabled for the current user — a dead /apps/{id}/ link is a 404.
 */
class PlatformLinksService
{
	/** @var list<array{id: string, appId: string, labelKey: string}> */
	private const APPS = [
		['id' => 'contacts', 'appId' => 'contacts', 'labelKey' => 'Contacts'],
		['id' => 'mail', 'appId' => 'mail', 'labelKey' => 'Mail'],
		['id' => 'calendar', 'appId' => 'calendar', 'labelKey' => 'Calendar'],
		['id' => 'tasks', 'appId' => 'tasks', 'labelKey' => 'Tasks'],
		['id' => 'files', 'appId' => 'files', 'labelKey' => 'Files'],
		['id' => 'talk', 'appId' => 'spreed', 'labelKey' => 'Talk'],
		['id' => 'deck', 'appId' => 'deck', 'labelKey' => 'Deck'],
		['id' => 'notes', 'appId' => 'notes', 'labelKey' => 'Notes'],
	];

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array<string, array{available: bool, url: string, label: string}>
	 */
	public function forUser(string $userId, ?int $filesFolderId = null): array
	{
		if (trim($userId) === '') {
			return [];
		}
		$out = [];
		foreach (self::APPS as $app) {
			$id = $app['id'];
			$appId = $app['appId'];
			if (!$this->appOpenForUser($appId)) {
				continue;
			}
			$url = $this->appHomeUrl($id, $appId, $filesFolderId);
			if ($url === '') {
				continue;
			}
			$out[$id] = [
				'available' => true,
				'url' => $url,
				'label' => $this->l10n->t($app['labelKey']),
			];
		}

		return $out;
	}

	private function appOpenForUser(string $appId): bool
	{
		if ($appId === '' || str_contains($appId, '/') || str_contains($appId, '\\')) {
			return false;
		}
		try {
			if (!$this->appManager->isInstalled($appId)) {
				return false;
			}

			return $this->appManager->isEnabledForUser($appId) === true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function appHomeUrl(string $id, string $appId, ?int $filesFolderId): string
	{
		$path = match ($id) {
			'files' => $filesFolderId !== null && $filesFolderId > 0
				? sprintf('/apps/files/?fileid=%d', $filesFolderId)
				: '/apps/files/',
			'talk' => '/apps/spreed/',
			'contacts' => '/apps/contacts/',
			'mail' => '/apps/mail/',
			'calendar' => '/apps/calendar/',
			'tasks' => '/apps/tasks/',
			'deck' => '/apps/deck/',
			'notes' => '/apps/notes/',
			default => '',
		};
		if ($path === '') {
			return '';
		}
		$absolute = $this->urlGenerator->getAbsoluteURL($path);
		if (!is_string($absolute) || $absolute === '') {
			return '';
		}

		return $absolute;
	}
}
