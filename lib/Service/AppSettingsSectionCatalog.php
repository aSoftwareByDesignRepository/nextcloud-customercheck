<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for App settings sub-pages (`/app-settings/{section}`).
 *
 * @see WorkspaceSettingsSectionCatalog for `/settings/{section}`
 */
final class AppSettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'access';

	/** @var list<string> */
	public const SECTIONS = [
		'access',
		'jobs',
		'tokens',
		'license',
		'product-codes',
		'personal-mail',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access control'),
			'jobs' => $l->t('Reminders & digest'),
			'tokens' => $l->t('Public feeds & intake'),
			'license' => $l->t('Mobile app license'),
			'product-codes' => $l->t('Agreement catalogue'),
			'personal-mail' => $l->t('Personal email OAuth'),
			default => $l->t('App settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access'),
			'jobs' => $l->t('Reminders'),
			'tokens' => $l->t('Feeds'),
			'license' => $l->t('License'),
			'product-codes' => $l->t('Catalogue'),
			'personal-mail' => $l->t('Mail OAuth'),
			default => $l->t('App'),
		};
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Who may open CustomerCheck and who can administer it.'),
			'jobs' => $l->t('When reminders and the weekly digest run.'),
			'tokens' => $l->t('Calendar feed and website intake links for this Nextcloud.'),
			'license' => $l->t('Official mobile seats — the web app stays free either way.'),
			'product-codes' => $l->t('Labels and kinds for agreements across CustomerCheck.'),
			'personal-mail' => $l->t('Google and Microsoft OAuth apps so people can connect their email without a password.'),
			default => $l->t('Access control, reminders, tokens, mobile license, and the agreement catalogue.'),
		};
	}
}
