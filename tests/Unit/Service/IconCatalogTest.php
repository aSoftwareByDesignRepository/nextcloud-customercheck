<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

final class IconCatalogTest extends TestCase
{
	public function testRenderCalloutWellIncludesSvgAndVariant(): void
	{
		$html = IconCatalog::renderCalloutWell('alert-triangle', 'warning');
		$this->assertStringContainsString('crm-callout__icon', $html);
		$this->assertStringContainsString('crm-notif-icon-well--warning', $html);
		$this->assertStringContainsString('crm-callout__icon-svg', $html);
		$this->assertStringContainsString('<svg', $html);
		$this->assertStringContainsString('m12 3 10 17H2Z', $html);
	}

	public function testRenderCalloutWellMapsErrorToDanger(): void
	{
		$html = IconCatalog::renderCalloutWell('circle-alert', 'error');
		$this->assertStringContainsString('crm-notif-icon-well--danger', $html);
		$this->assertStringContainsString('<svg', $html);
	}

	public function testUnknownIconRendersEmptySvgSlotButKeepsWell(): void
	{
		$html = IconCatalog::renderCalloutWell('not-a-real-icon', 'info');
		$this->assertStringContainsString('crm-callout__icon', $html);
		$this->assertStringNotContainsString('<svg', $html);
	}
}
