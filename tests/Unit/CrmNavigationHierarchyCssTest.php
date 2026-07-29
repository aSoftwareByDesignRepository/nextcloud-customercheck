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
}
