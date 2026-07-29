<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PSD §9 / S12 — crm-btn anchors keep solid fills (port of AZC cascade contract).
 */
final class CrmBtnCascadeContractTest extends TestCase
{
	public function testLinkResetsExcludeCrmBtn(): void
	{
		$root = dirname(__DIR__, 2);
		foreach (['base.css', 'typography.css', 'accessibility.css'] as $file) {
			$path = $root . '/css/common/' . $file;
			$css = (string)file_get_contents($path);
			$this->assertTrue(
				str_contains($css, 'a:not(.btn):not(.crm-btn)') || str_contains($css, 'a:not(.btn):not(.azc-btn):not(.crm-btn)'),
				$path . ' must exclude .crm-btn from link demotion'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/#app-content-wrapper a\s*\{/',
				$css,
				'Unscoped wrapper link rule must not remain in ' . $path
			);
		}
	}

	public function testCrmBtnPrimaryHasSolidFillAndHover(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('#app-content.crm-app .crm-btn--primary', $css);
		$this->assertStringContainsString('.crm-btn--primary:hover', $css);
		$this->assertStringContainsString('.crm-btn--secondary:hover', $css);
		$this->assertStringContainsString('text-decoration: none !important', $css);
		$this->assertMatchesRegularExpression(
			'/background-color:\s*var\(--color-primary-element[^)]*\)\s*!important/',
			$css,
		);
		// WCAG 2.5.5 target size is defined in CSS px; 2.75rem shrank to 41.25px under NC's 15px root font.
		$this->assertStringContainsString('min-height: 44px', $css);
	}
}
