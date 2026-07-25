<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CustomerCheck must ship its own theme-safe icons — not the AZC clock glyph.
 */
final class AppIconAssetsContractTest extends TestCase
{
	private string $imgDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->imgDir = dirname(__DIR__, 2) . '/img';
	}

	public function testRequiredIconFilesExist(): void
	{
		foreach (['app.svg', 'app-dark.svg', 'app-dashboard.svg'] as $file) {
			$path = $this->imgDir . '/' . $file;
			$this->assertFileExists($path, $file);
			$this->assertGreaterThan(200, filesize($path), $file . ' must not be a stub');
		}
	}

	public function testAppSvgIsNotArbeitszeitCheckClock(): void
	{
		$crm = (string)file_get_contents($this->imgDir . '/app.svg');
		$azcPath = dirname(__DIR__, 3) . '/arbeitszeitcheck/img/app.svg';
		$this->assertFileExists($azcPath, 'AZC reference icon must exist for regression compare');
		$azc = (string)file_get_contents($azcPath);

		$this->assertNotSame(
			preg_replace('/\s+/', '', $crm),
			preg_replace('/\s+/', '', $azc),
			'CustomerCheck app.svg must not be a copy of the AZC clock icon'
		);
		$this->assertStringNotContainsString('a10 10 0 1 0 10 10', $crm, 'Clock circle path must not remain');
		$this->assertStringContainsString('CustomerCheck', $crm);
		$this->assertStringContainsString('fill="#ffffff"', $crm, 'Header glyph must be white for NC invert filters');
	}

	public function testSvgCommentsAreStrictXmlSafe(): void
	{
		foreach (['app.svg', 'app-dark.svg', 'app-dashboard.svg'] as $file) {
			$svg = (string)file_get_contents($this->imgDir . '/' . $file);
			if (preg_match_all('/<!--(.*?)-->/s', $svg, $matches) === false) {
				$this->fail($file . ' comment parse failed');
			}
			foreach ($matches[1] as $commentBody) {
				$this->assertStringNotContainsString(
					'--',
					$commentBody,
					$file . ' XML comment must not contain double-hyphen (breaks librsvg / strict parsers)'
				);
			}
		}
	}

	public function testDarkAndDashboardIconsHaveNoFixedBrandColours(): void
	{
		foreach (['app-dark.svg', 'app-dashboard.svg'] as $file) {
			$svg = (string)file_get_contents($this->imgDir . '/' . $file);
			$this->assertStringNotContainsString('fill="#ffffff"', $svg, $file . ' must be dark/uncoloured for dashboard');
			$this->assertDoesNotMatchRegularExpression('/fill="#(?!000000)[0-9a-fA-F]{3,8}"/', $svg, $file . ' must not embed brand hex fills');
			$this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
			$this->assertStringContainsString('CustomerCheck', $svg);
		}
	}

	public function testIconGeometryIsBuildingPlusCheckMotif(): void
	{
		$svg = (string)file_get_contents($this->imgDir . '/app.svg');
		$this->assertSame(3, substr_count($svg, '<path '), 'Expected tower + wings paths (check is evenodd cutout)');
		$this->assertMatchesRegularExpression('/building|customers/i', $svg);
		$this->assertMatchesRegularExpression('/check/i', $svg);
		$this->assertStringContainsString('fill-rule="evenodd"', $svg, 'Check cutout needs evenodd');
		$this->assertStringContainsString('M8 2a2 2', $svg, 'Main tower must match Lucide building-2 geometry');
		$this->assertStringContainsString('M9.5 14.8', $svg, 'Check cutout must remain in tower');
		$this->assertStringNotContainsString('a10 10 0 1 0 10 10', $svg);
	}

	public function testAppIconServiceIsWiredForSurfaces(): void
	{
		$today = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Dashboard/TodayWidget.php');
		$this->assertStringContainsString('AppIconService', $today);
		$this->assertStringContainsString('absoluteSurfaceIconUrl', $today);

		foreach (['CompanySearchProvider', 'PersonSearchProvider', 'DealSearchProvider'] as $cls) {
			$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Search/' . $cls . '.php');
			$this->assertStringContainsString('AppIconService', $src, $cls);
			$this->assertStringContainsString('surfaceIconPath', $src, $cls);
			$this->assertStringNotContainsString("imagePath(Application::APP_ID, 'app.svg')", $src, $cls . ' must not hard-code white header icon for search');
		}

		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AppIconService.php');
		$this->assertStringContainsString('withCacheBust', $svc);
		$this->assertStringContainsString('getAppVersion', $svc);
	}
}
