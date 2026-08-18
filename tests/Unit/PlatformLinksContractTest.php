<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Company 360 platform strip: only enabled sibling apps, hidden when none.
 */
final class PlatformLinksContractTest extends TestCase
{
	public function testTemplateStartsHiddenWithoutPlaceholderChips(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-platform-links" hidden', $html);
		$this->assertStringNotContainsString('Related apps load here when available.', $html);
		$this->assertStringNotContainsString('crm-platform-links__chip', $html);
		$this->assertStringNotContainsString('/apps/calendar/', $html);
		$this->assertStringNotContainsString('/apps/tasks/', $html);
		$this->assertStringNotContainsString('/apps/files/', $html);
	}

	public function testHydratorPaintsOnlySafeEnabledChipsAndHidesEmpty(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('CustomerCheckPlatformLinks.enabledChips', $js);
		$this->assertStringContainsString('CustomerCheckPlatformLinks.enabledChips(links, origin)', $js);
		$this->assertStringContainsString("plat.setAttribute('data-platform-painted', '1')", $js);
		$this->assertStringContainsString('plat.hidden = true', $js);
		$this->assertStringContainsString('crm-platform-links__lead', $js);
		$this->assertStringContainsString("t('Open related Nextcloud apps')", $js);
		$this->assertStringNotContainsString("t('No related apps enabled.')", $js);
		$this->assertStringNotContainsString('if (!info || !info.url) return;', $js);
	}

	public function testAssetsRegisterPlatformLinksCore(): void
	{
		$assets = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FrontEndAssetService.php');
		$core = strpos($assets, "addScript(Application::APP_ID, 'common/platform-links-core')");
		$datepicker = strpos($assets, "addScript(Application::APP_ID, 'common/datepicker')");
		$this->assertNotFalse($core);
		$this->assertNotFalse($datepicker);
		$this->assertGreaterThan($datepicker, $core);
		$this->assertFileExists(dirname(__DIR__, 2) . '/js/common/platform-links-core.js');
	}

	public function testEmptyStripIsNotDisplayed(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.crm-platform-links\[hidden\][^}]*display:\s*none/s',
			$css,
		);
		$this->assertStringContainsString('.crm-platform-links__lead', $css);
		$this->assertStringContainsString('.crm-platform-links__row', $css);
	}
}
