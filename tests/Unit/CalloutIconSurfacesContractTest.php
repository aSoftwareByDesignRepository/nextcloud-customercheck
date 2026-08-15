<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Callout icon wells must embed IconCatalog SVG — never empty decorative spans.
 */
final class CalloutIconSurfacesContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testTemplatesNeverShipEmptyCalloutIconSpans(): void
	{
		$files = [
			'templates/company-detail.php',
			'templates/settings/workspace-mailbox.php',
		];
		foreach ($files as $rel) {
			$html = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringNotContainsString(
				'<span class="crm-callout__icon" aria-hidden="true"></span>',
				$html,
				$rel . ' must not leave an empty callout icon well',
			);
			$this->assertStringContainsString('IconCatalog::renderCalloutWell', $html, $rel);
		}
	}

	public function testCompanyDetailAndMailboxUseVariantMatchedIcons(): void
	{
		$detail = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString("renderCalloutWell('info', 'info')", $detail);
		$this->assertStringContainsString("renderCalloutWell('circle-alert', 'danger')", $detail);

		$mailbox = (string)file_get_contents($this->root . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString("renderCalloutWell('alert-triangle', 'warning')", $mailbox);
	}
}
