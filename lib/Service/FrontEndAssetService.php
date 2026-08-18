<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCP\Util;

/**
 * Central front-end asset registration for CustomerCheck pages.
 */
final class FrontEndAssetService
{
	private static bool $coreRegistered = false;

	/**
	 * Register shared stylesheet + JS stack (idempotent per request).
	 */
	public static function registerCore(): void
	{
		if (self::$coreRegistered) {
			return;
		}
		self::$coreRegistered = true;

		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'app');
		Util::addStyle(Application::APP_ID, 'navigation');
		Util::addScript(Application::APP_ID, 'common/dialog-present');
		Util::addScript(Application::APP_ID, 'common/company-picker');
		Util::addScript(Application::APP_ID, 'common/components');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/shell-can');
		Util::addScript(Application::APP_ID, 'common/folder-picker');
		Util::addScript(Application::APP_ID, 'common/shared-mail-send');
		Util::addScript(Application::APP_ID, 'common/user-picker-nav');
		Util::addScript(Application::APP_ID, 'common/navigation');
		Util::addScript(Application::APP_ID, 'common/navigation-icons');
		Util::addScript(Application::APP_ID, 'common/app-feedback');
		Util::addScript(Application::APP_ID, 'common/filter-live-core');
		Util::addScript(Application::APP_ID, 'common/filter-live');
	}

	/**
	 * Register core assets plus optional page-specific CSS/JS.
	 *
	 * @param list<string> $extraStyles
	 * @param list<string> $extraScripts Loaded after core and before the page script.
	 */
	public static function registerPage(string $pageScript, ?string $pageStyle = null, array $extraStyles = [], array $extraScripts = []): void
	{
		self::registerCore();
		foreach ($extraStyles as $style) {
			if ($style !== '') {
				Util::addStyle(Application::APP_ID, $style);
			}
		}
		if ($pageStyle !== null && $pageStyle !== '') {
			Util::addStyle(Application::APP_ID, $pageStyle);
		}
		foreach ($extraScripts as $script) {
			if ($script !== '') {
				Util::addScript(Application::APP_ID, $script);
			}
		}
		if ($pageScript !== '') {
			Util::addScript(Application::APP_ID, $pageScript);
		}
	}
}
