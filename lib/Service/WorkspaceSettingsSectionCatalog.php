<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for Workspace settings sub-pages (`/settings/{section}`).
 *
 * @see AppSettingsSectionCatalog for `/app-settings/{section}`
 */
final class WorkspaceSettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'preferences';

	/** @var list<string> */
	public const SECTIONS = [
		'my-email',
		'mailbox',
		'preferences',
		'checklists',
		'review-sources',
		'promises',
		'integrations',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function isVisible(string $section, bool $canManageWorkspace): bool
	{
		return match ($section) {
			'my-email', 'preferences', 'integrations' => true,
			'mailbox', 'checklists', 'review-sources', 'promises' => $canManageWorkspace,
			default => false,
		};
	}

	public function defaultSection(bool $canManageWorkspace): string
	{
		// Managers land on team mailbox; members on their personal inbox setup.
		if ($canManageWorkspace && $this->isVisible('mailbox', true)) {
			return 'mailbox';
		}
		if ($this->isVisible('my-email', $canManageWorkspace)) {
			return 'my-email';
		}

		return self::DEFAULT_SECTION;
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'my-email' => $l->t('My email'),
			'mailbox' => $l->t('Team & mailbox'),
			'preferences' => $l->t('Your preferences'),
			'checklists' => $l->t('Checklist templates'),
			'review-sources' => $l->t('Assign inbox sources'),
			'promises' => $l->t('Weekly promise check'),
			'integrations' => $l->t('Integrations'),
			default => $l->t('Team & mailbox'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'my-email' => $l->t('My email'),
			'mailbox' => $l->t('Team mailbox'),
			'preferences' => $l->t('Preferences'),
			'checklists' => $l->t('Checklists'),
			'review-sources' => $l->t('Assign sources'),
			'promises' => $l->t('Promises'),
			'integrations' => $l->t('Integrations'),
			default => $l->t('Settings'),
		};
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'my-email' => $l->t('Connect your personal mailbox, pick folders to sync, and edit your signature.'),
			'mailbox' => $l->t('Members, shared mailbox, personal email sync policy, and weekly limits for your team.'),
			'preferences' => $l->t('How deals look for you.'),
			'checklists' => $l->t('Reusable step lists for any deal — licenses, services, hardware, mixed carts. Make once, use on every order.'),
			'review-sources' => $l->t('Which inboxes and folders feed Assign.'),
			'promises' => $l->t('Who owes a weekly promise check.'),
			'integrations' => $l->t('Linked Check apps and how they show on companies.'),
			default => $l->t('Preferences, members, mailbox, and integrations.'),
		};
	}
}
