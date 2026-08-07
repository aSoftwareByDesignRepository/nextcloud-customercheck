<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ImportService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * SHARED-IDENTITY AC-C-17 — ProjectCheck import defaults to linked CRM.
 */
final class ImportServiceProjectCheckLinkDefaultTest extends TestCase
{
	public function testApplyCreateInputIncludesPcCustomerIdFromProjectcheckRow(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ImportService.php');
		$this->assertStringContainsString("\$importSource === 'projectcheck' && \$linkOnImport", $src);
		$this->assertStringContainsString("\$createInput['pcCustomerId'] = \$pcId;", $src);
		$this->assertStringContainsString("\$decision['linkSibling'] ?? true) !== false", $src);
		// Explicit opt-out path must exist (advanced "create unlinked").
		$this->assertStringContainsString('linkSibling', $src);
		$this->assertMatchesRegularExpression('/linkOnImport.*!== false/s', $src);
	}

	public function testImportWizardDefaultsToLinkAndExposesAdvancedUnlinked(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/import.php');
		$this->assertStringContainsString('crm-import-link-default', $tpl);
		$this->assertStringContainsString('name="crm-import-link-sibling" value="1" checked', $tpl);
		$this->assertStringContainsString('Link to ProjectCheck customer (recommended)', $tpl);
		$this->assertStringContainsString('Advanced options', $tpl);
		$this->assertStringContainsString('Create unlinked (separate CRM company)', $tpl);
		$this->assertStringContainsString('name="crm-import-link-sibling" value="0"', $tpl);

		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/import.js');
		$this->assertStringContainsString('linkSiblingPreference', $js);
		$this->assertStringContainsString('decision.linkSibling = linkSiblingPreference()', $js);
		$this->assertStringContainsString("src !== 'projectcheck'", $js);
	}

	public function testImportServiceClassExists(): void
	{
		$this->assertTrue(class_exists(ImportService::class));
		$this->assertTrue(class_exists(ProductCodeRegistry::class));
	}
}
