<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Company postal address fields — form, 360 display, JS wire-up. */
final class CompanyAddressUxContractTest extends TestCase
{
	public function testCompanyFormExposesDedicatedAddressFieldsWithName(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$fields = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-company-address-fields.php');
		$advancedPos = (int)strpos($html, 'id="crm-company-advanced"');
		$includePos = (int)strpos($html, 'crm-company-address-fields.php');
		$namePos = (int)strpos($html, 'id="crm-company-name"');
		$this->assertGreaterThan(0, $advancedPos);
		$this->assertGreaterThan(0, $includePos);
		$this->assertLessThan($advancedPos, $includePos, 'Address must sit with the name, not under Advanced');
		$this->assertLessThan($advancedPos, $namePos, 'Name stays in essentials');
		$this->assertStringContainsString('id="<?php p($crmAddrPrefix); ?>-street"', $fields);
		$this->assertStringContainsString('id="<?php p($crmAddrPrefix); ?>-zip"', $fields);
		$this->assertStringContainsString('id="<?php p($crmAddrPrefix); ?>-city"', $fields);
		$this->assertStringContainsString('id="<?php p($crmAddrPrefix); ?>-country"', $fields);
		$this->assertStringContainsString('name="addrStreet"', $fields);
		$this->assertStringContainsString('name="addrZip"', $fields);
		$this->assertStringContainsString('name="addrCity"', $fields);
		$this->assertStringContainsString('autocomplete="street-address"', $fields);
		$this->assertStringContainsString('autocomplete="postal-code"', $fields);
		$this->assertStringContainsString('Postal address', $fields);
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
		$this->assertStringContainsString('crm-card__lead crm-identity-strip__address crm-identity-strip__address--empty', $html);
		$this->assertStringNotContainsString('crm-sr-only crm-identity-strip__address', $html);
		$this->assertStringContainsString('CompanyNotesPresenter::publicHeadline', $html);
		$this->assertStringNotContainsString('p($company->getNotes())', $html);
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
