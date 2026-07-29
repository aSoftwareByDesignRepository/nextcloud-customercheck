<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IURLGenerator;

/**
 * Tasks app deep-link builder — COMMAND-CENTER-SPEC §9.5.6.
 */
class TasksDeepLinkBuilder
{
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function tasksHomeUrl(): string
	{
		return $this->urlGenerator->linkToRouteAbsolute('tasks.page.index')
			?: $this->urlGenerator->getAbsoluteURL('/apps/tasks/');
	}

	/**
	 * Parse todo_uri `{calendarUri}/{objectName}` → deep link, else home.
	 *
	 * @return array{url: string, specific: bool}
	 */
	public function fromTodoUri(?string $todoUri): array
	{
		$home = $this->fallbackHomePath();
		if ($todoUri === null || trim($todoUri) === '') {
			return ['url' => $home, 'specific' => false];
		}
		$todoUri = trim($todoUri);
		$parts = explode('/', $todoUri);
		if (count($parts) < 2) {
			return ['url' => $home, 'specific' => false];
		}
		$objectName = array_pop($parts);
		$calendarId = implode('/', $parts);
		if ($calendarId === '' || $objectName === '' || str_contains($calendarId, '..')) {
			return ['url' => $home, 'specific' => false];
		}
		$path = sprintf(
			'/apps/tasks/calendars/%s/tasks/%s',
			rawurlencode($calendarId),
			rawurlencode($objectName),
		);

		return [
			'url' => $this->urlGenerator->getAbsoluteURL($path),
			'specific' => true,
		];
	}

	private function fallbackHomePath(): string
	{
		try {
			$url = $this->urlGenerator->linkToRouteAbsolute('tasks.page.index');
			if (is_string($url) && $url !== '') {
				return $url;
			}
		} catch (\Throwable) {
			// Tasks route may be absent when app disabled.
		}

		return $this->urlGenerator->getAbsoluteURL('/apps/tasks/');
	}
}
