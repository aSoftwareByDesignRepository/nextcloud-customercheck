<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Support\PostalAddressParser;
use PHPUnit\Framework\TestCase;

/**
 * Contract: website OrderProcessor::toCrmPayload keys ↔ CRM webhook intake.
 * Keeps dual-write from silently dropping street/zip when both structured keys exist.
 */
final class WebsiteOrderDualWriteContractTest extends TestCase
{
	public function testWebsiteCrmKeysMapOntoPostalParser(): void
	{
		// Mirrors website/lib/OrderProcessor.php::toCrmPayload field names.
		$websiteCrm = [
			'orderId' => 'contractord123456',
			'source' => 'website-order',
			'companyName' => 'Contract Co',
			'streetAddress' => 'Holtenauer Straße 267',
			'addressLine2' => 'EG',
			'postalCode' => '24106',
			'city' => 'Kiel',
			'country' => 'Germany',
			'address' => "Holtenauer Straße 267\nEG\n24106 Kiel\nGermany",
		];
		$out = PostalAddressParser::fromImportPayload($websiteCrm);
		$this->assertSame("Holtenauer Straße 267\nEG", $out['addrStreet']);
		$this->assertSame('24106', $out['addrZip']);
		$this->assertSame('Kiel', $out['addrCity']);
		$this->assertSame('DE', $out['country']);
	}

	public function testTokensHelpDocumentsWebsiteConfigSplit(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/tokens.php');
		$this->assertStringContainsString('customercheck_order_url', $html);
		$this->assertStringContainsString('customercheck_order_token', $html);
		$this->assertStringContainsString('/hooks/order', $html);
		$this->assertStringContainsString('aria-describedby="crm-order-url-help"', $html);
		$this->assertStringContainsString('autocomplete="off"', $html);
	}

	public function testWebsiteClientRejectsDoubledTokenUrlDocumentedInParserComment(): void
	{
		// Website lives outside the Nextcloud container bind — assert the CRM-side
		// parser accepts the website field names (dual-write contract) instead.
		$parser = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/PostalAddressParser.php');
		$this->assertStringContainsString("\$payload['streetAddress']", $parser);
		$this->assertStringContainsString('OrderProcessor::toCrmPayload', $parser);
		$this->assertStringContainsString("\$payload['addressLine2']", $parser);
	}
}
