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

	public function testGetTheAppCopyIsTranslatedInFooterLocales(): void
	{
		$allowIdentity = ['CustomerCheck Mobile'];
		$locales = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];
		$keys = $this->getTheAppSourceKeys();
		self::assertNotEmpty($keys);
		foreach ($locales as $locale) {
			$jsonPath = $this->root . '/l10n/' . $locale . '.json';
			$jsPath = $this->root . '/l10n/' . $locale . '.js';
			self::assertFileExists($jsonPath, $locale . ' catalog missing');
			self::assertFileExists($jsPath, $locale . ' JS catalog missing');
			$decoded = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
			self::assertIsArray($decoded);
			$translations = $decoded['translations'] ?? null;
			self::assertIsArray($translations);
			$js = (string) file_get_contents($jsPath);
			foreach ($keys as $key) {
				self::assertArrayHasKey($key, $translations, $locale . ' missing Get the App key: ' . $key);
				$value = $translations[$key];
				self::assertIsString($value);
				if (!in_array($key, $allowIdentity, true)) {
					self::assertNotSame($key, $value, $locale . ' left Get the App copy in English: ' . $key);
				}
				$encodedKey = json_encode($key, JSON_UNESCAPED_UNICODE);
				self::assertIsString($encodedKey);
				self::assertStringContainsString($encodedKey, $js, $locale . ' JS missing key: ' . $key);
				$encodedVal = json_encode($value, JSON_UNESCAPED_UNICODE);
				self::assertIsString($encodedVal);
				self::assertStringContainsString($encodedVal, $js, $locale . ' JS missing translation for: ' . $key);
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function getTheAppSourceKeys(): array
	{
		$keys = [];
		$tpl = (string) file_get_contents($this->root . '/templates/get-the-app.php');
		if (preg_match_all("/\\\$l->t\\('((?:\\\\'|[^'])*)'\\)/", $tpl, $matches) === false) {
			self::fail('Failed to parse get-the-app.php l10n keys');
		}
		foreach ($matches[1] as $raw) {
			$keys[] = str_replace("\\'", "'", $raw);
		}
		$controller = (string) file_get_contents($this->root . '/lib/Controller/PageController.php');
		if (preg_match('/function getTheApp\b[\s\S]{0,2500}/', $controller, $block) === 1
			&& preg_match_all("/->(?:l10n|l)->t\\('((?:\\\\'|[^'])*)'\\)/", $block[0], $ctrlMatches) !== false) {
			foreach ($ctrlMatches[1] as $raw) {
				$keys[] = str_replace("\\'", "'", $raw);
			}
		}
		return array_values(array_unique($keys));
	}

	public function testSupportUsEnablesOfficialMobileLicenseCta(): void
	{
		$page = (string) file_get_contents($this->root . '/templates/support-us.php');
		self::assertStringContainsString('appSettingsSection', $page);
		self::assertStringContainsString('#section-license', $page);
		self::assertStringContainsString('$licensePageUrl !== \'\'', $page);
	}
}
