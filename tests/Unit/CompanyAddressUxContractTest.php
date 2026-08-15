<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Company postal address fields — form, 360 display, JS wire-up. */
final class CompanyAddressUxContractTest extends TestCase
{
	public function testCompanyFormExposesDedicatedAddressFieldsUnderAdvanced(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$advancedPos = (int)strpos($html, 'id="crm-company-advanced"');
		$addressPos = (int)strpos($html, 'id="crm-company-address"');
		$namePos = (int)strpos($html, 'id="crm-company-name"');
		$this->assertGreaterThan(0, $advancedPos);
		$this->assertGreaterThan($advancedPos, $addressPos, 'Address must stay under Advanced');
		$this->assertLessThan($advancedPos, $namePos, 'Name stays in essentials');
		$this->assertStringContainsString('id="crm-company-street"', $html);
		$this->assertStringContainsString('id="crm-company-zip"', $html);
		$this->assertStringContainsString('id="crm-company-city"', $html);
		$this->assertStringContainsString('id="crm-company-country"', $html);
		$this->assertStringContainsString('name="addrStreet"', $html);
		$this->assertStringContainsString('name="addrZip"', $html);
		$this->assertStringContainsString('name="addrCity"', $html);
		$this->assertStringContainsString('autocomplete="street-address"', $html);
		$this->assertStringContainsString('autocomplete="postal-code"', $html);
		$this->assertStringContainsString('Postal address', $html);
		// Bachus: still never force-open Advanced on edit.
		$this->assertStringNotContainsString("if (\$mode === 'edit'): ?> open", $html);
	}

	public function testCompanyFormJsPostsAddressFields(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-form.js');
		$this->assertStringContainsString('addrStreet:', $js);
		$this->assertStringContainsString('addrZip:', $js);
		$this->assertStringContainsString('addrCity:', $js);
		$this->assertStringContainsString('country:', $js);
		$this->assertStringContainsString('crm-company-street', $js);
	}

	public function testCompanyDetailShowsAddressOrAddLink(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-identity-strip__address', $html);
		$this->assertStringContainsString('crm-company-address-display', $html);
		$this->assertStringContainsString('Add postal address', $html);
		$this->assertStringContainsString('getAddrStreet', $html);
		$this->assertStringContainsString('getAddrZip', $html);
		$this->assertStringContainsString('getAddrCity', $html);
	}

	public function testImportApplyWritesAddressFields(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ImportService.php');
		$this->assertStringContainsString("foreach (['addrStreet', 'addrZip', 'addrCity'] as \$addrKey)", $src);
		$this->assertStringContainsString('PostalAddressParser::fromImportPayload', $src);
		$this->assertStringContainsString("'addrStreet' => trim(\$row['addrStreet'] ?? '')", $src);
	}

	public function testWebhookStoresParsedAddress(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/WebsiteOrderWebhookService.php');
		$this->assertStringContainsString('PostalAddressParser::fromImportPayload', $src);
		$this->assertStringContainsString('fillEmptyAddressFields', $src);
		$this->assertStringContainsString("\$create['addrStreet']", $src);
	}
}
