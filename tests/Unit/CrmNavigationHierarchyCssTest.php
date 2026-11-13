<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CrmNavigationHierarchyCssTest extends TestCase
{
	public function testNavigationTemplateUsesAccordionGroups(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString('crm-nav', $html);
		$this->assertStringContainsString('app-navigation', $html);
	}

	public function testNavigationCssScopesUnderCrmApp(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/navigation.css');
		$this->assertTrue(
			str_contains($css, 'crm-') || str_contains($css, 'app-navigation'),
			'navigation.css must style CRM nav'
		);
	}

	public function testDisclosureChevronPinsToTrailingEdge(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/navigation.css');
		// <button> keeps intrinsic sizing; the li must be a flex column so the
		// toggle row fills the sidebar instead of hugging its label text.
		$this->assertMatchesRegularExpression(
			'/\.nav-item-has-children\s*\{[^}]*display:\s*flex[^}]*flex-direction:\s*column/s',
			$css,
			'.nav-item-has-children must be a flex column (button rows must fill the sidebar)'
		);
		$this->assertMatchesRegularExpression(
			'/\.nav-parent-chevron\s*\{[^}]*margin-inline-start:\s*auto/s',
			$css,
			'.nav-parent-chevron must pin to the trailing edge via margin-inline-start:auto'
		);
	}
}
