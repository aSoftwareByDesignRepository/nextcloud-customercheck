<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\MobileAppLinks;
use PHPUnit\Framework\TestCase;

final class GetTheAppPageContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testRouteAndControllerExist(): void
	{
		$routes = (string) file_get_contents($this->root . '/appinfo/routes.php');
		self::assertStringContainsString("page#getTheApp", $routes);
		self::assertStringContainsString("'/get-the-app'", $routes);

		$php = (string) file_get_contents($this->root . '/lib/Controller/PageController.php');
		self::assertStringContainsString('function getTheApp(', $php);
		self::assertMatchesRegularExpression('/#\[NoAdminRequired\]\s*#\[NoCSRFRequired\]\s*public function getTheApp\(/s', $php);
		self::assertStringContainsString("'get-the-app'", $php);
		self::assertStringContainsString('Get the App', $php);
		self::assertStringContainsString('MobileAppLinks', $php);
		self::assertStringContainsString("'playStore'", $php);

		$shell = (string) file_get_contents($this->root . '/lib/Controller/PageShellTrait.php');
		self::assertStringContainsString('customercheck.page.getTheApp', $shell);
	}

	public function testNavInToolsBeforeSupportUs(): void
	{
		$nav = (string) file_get_contents($this->root . '/templates/common/navigation.php');
		$getAppPos = strpos($nav, "\$u('getTheApp')");
		$supportPos = strpos($nav, "\$u('supportUs')");
		self::assertNotFalse($getAppPos);
		self::assertNotFalse($supportPos);
		self::assertGreaterThan($getAppPos, $supportPos);
		self::assertStringContainsString("'get-the-app'", $nav);
	}

	public function testTemplateOmitsLivePlayBadgeWhileUnlisted(): void
	{
		$tpl = (string) file_get_contents($this->root . '/templates/get-the-app.php');
		self::assertStringContainsString('crm-get-app__hero', $tpl);
		self::assertStringContainsString('crm-get-app__features', $tpl);
		self::assertStringContainsString('MobileAppLinks::PLAY_LISTED', $tpl);
		self::assertStringContainsString('if ($playListed)', $tpl);
		self::assertStringContainsString('crm-btn crm-btn--primary crm-get-app__play', $tpl);
		self::assertStringContainsString('Not yet publicly listed on Google Play', $tpl);
		self::assertStringContainsString('rel="noopener noreferrer"', $tpl);
		self::assertFalse(MobileAppLinks::PLAY_LISTED);
	}

	public function testCssSeparatesStaticFeaturesFromActionButtons(): void
	{
		$css = (string) file_get_contents($this->root . '/css/get-the-app.css');
		self::assertMatchesRegularExpression(
			'/\.crm-get-app__hero[^{]*\{[^}]*linear-gradient/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.crm-get-app__feature[^{]*\{[^}]*cursor:\s*default/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.crm-get-app__action[^{]*\{[^}]*cursor:\s*pointer/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.crm-app--get-the-app a\.crm-get-app__play[^{]*\{[^}]*background-color:\s*var\(--color-primary-element\)\s*!important/s',
			$css,
		);
		self::assertSame(MobileAppLinks::PLAY_STORE_PACKAGE_ID, 'de.softwarebydesign.customercheck');
	}

	public function testSupportUsEnablesOfficialMobileLicenseCta(): void
	{
		$page = (string) file_get_contents($this->root . '/templates/support-us.php');
		self::assertStringContainsString('appSettingsSection', $page);
		self::assertStringContainsString('#section-license', $page);
		self::assertStringContainsString('$licensePageUrl !== \'\'', $page);
	}
}
