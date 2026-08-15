<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\PostalAddressParser;
use PHPUnit\Framework\TestCase;

final class PostalAddressParserTest extends TestCase
{
	public function testParsesGermanWebsiteOrderBlockWithNameTypo(): void
	{
		// companyName spelling differs from address letterhead — still isolate street.
		$out = PostalAddressParser::fromImportPayload([
			'companyName' => 'Julian Grosahupt Development',
			'address' => "Julian Groshaupt Development\nHoltenauer Straße 267\n24106 Kiel",
		]);
		$this->assertSame('Holtenauer Straße 267', $out['addrStreet']);
		$this->assertSame('24106', $out['addrZip']);
		$this->assertSame('Kiel', $out['addrCity']);
		$this->assertTrue($out['parsed']);
	}

	public function testStructuredKeysWinOverFreeText(): void
	{
		$out = PostalAddressParser::fromImportPayload([
			'address' => "Ignore Me\n99999 Nowhere",
			'addrStreet' => 'Structured Str. 1',
			'addrZip' => '10115',
			'addrCity' => 'Berlin',
			'country' => 'de',
		]);
		$this->assertSame('Structured Str. 1', $out['addrStreet']);
		$this->assertSame('10115', $out['addrZip']);
		$this->assertSame('Berlin', $out['addrCity']);
		$this->assertSame('DE', $out['country']);
		$this->assertTrue($out['parsed']);
	}

	public function testCountryLineAtEnd(): void
	{
		$parsed = PostalAddressParser::parseFreeText("Main Street 1\n8000 Zürich\nSwitzerland");
		$this->assertSame('Main Street 1', $parsed['addrStreet']);
		$this->assertSame('8000', $parsed['addrZip']);
		$this->assertSame('Zürich', $parsed['addrCity']);
		$this->assertSame('CH', $parsed['country']);
	}

	public function testUnparseableFreeTextIsNotInvented(): void
	{
		$out = PostalAddressParser::fromImportPayload([
			'address' => 'c/o somehow weird',
			'companyName' => 'Acme',
		]);
		// Single non-PLZ line becomes street — still dedicated, not invented zip/city.
		$this->assertSame('c/o somehow weird', $out['addrStreet']);
		$this->assertNull($out['addrZip']);
		$this->assertNull($out['addrCity']);
		$this->assertTrue($out['parsed']);
	}

	public function testEmptyPayload(): void
	{
		$out = PostalAddressParser::fromImportPayload([]);
		$this->assertNull($out['addrStreet']);
		$this->assertFalse($out['parsed']);
	}

	public function testWebsiteStreetAddressWithPostalAndCityKeepsStreet(): void
	{
		// Regression: zip/city present must not drop website streetAddress.
		$out = PostalAddressParser::fromImportPayload([
			'companyName' => 'Muster AG',
			'streetAddress' => 'Musterstraße 1',
			'addressLine2' => 'c/o IT',
			'postalCode' => '24837',
			'city' => 'Schleswig',
			'country' => 'Germany',
			'address' => "Musterstraße 1\nc/o IT\n24837 Schleswig\nGermany",
		]);
		$this->assertSame("Musterstraße 1\nc/o IT", $out['addrStreet']);
		$this->assertSame('24837', $out['addrZip']);
		$this->assertSame('Schleswig', $out['addrCity']);
		$this->assertSame('DE', $out['country']);
		$this->assertTrue($out['parsed']);
	}

	public function testClipsOverlongStreet(): void
	{
		$long = str_repeat('x', 300);
		$out = PostalAddressParser::fromImportPayload(['addrStreet' => $long]);
		$this->assertSame(255, mb_strlen((string)$out['addrStreet']));
	}
}
