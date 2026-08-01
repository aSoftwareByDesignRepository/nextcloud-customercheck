<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\App\IAppManager;
use OCP\IURLGenerator;

/**
 * Official Nextcloud platform hub chips — COMMAND-CENTER-SPEC §16.4.
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
		private readonly VTodoService $vTodo,
	) {
	}

	/**
	 * @return array<string, array{available: bool, url: string|null, label: string}>
	 */
	public function forUser(string $userId, ?int $filesFolderId = null): array
	{
		$out = [];
		foreach (self::APPS as $app) {
			$id = $app['id'];
			$appId = $app['appId'];
			$available = $appId === 'files' || $this->appManager->isEnabledForUser($appId);
			if ($id === 'tasks') {
				$available = $available || $this->vTodo->isAvailable($userId);
			}
			if (!$available) {
				continue;
			}
			$url = match ($id) {
				'files' => $filesFolderId !== null && $filesFolderId > 0
					? $this->urlGenerator->getAbsoluteURL('/apps/files/?fileid=' . $filesFolderId)
					: $this->urlGenerator->getAbsoluteURL('/apps/files/'),
				'talk' => $this->urlGenerator->getAbsoluteURL('/apps/spreed/'),
				default => $this->urlGenerator->getAbsoluteURL('/apps/' . $appId . '/'),
			};
			$out[$id] = [
				'available' => true,
				'url' => $url,
				'label' => $app['labelKey'],
			];
		}

		return $out;
	}
}
