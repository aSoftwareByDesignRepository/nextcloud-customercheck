<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ValueMapLogic;
use PHPUnit\Framework\TestCase;

final class ValueMapLogicTest extends TestCase
{
	public function testNormalizeCollapsesWhitespaceAndCase(): void
	{
		$this->assertSame('deutsch land', ValueMapLogic::normalizeRaw("  Deutsch   LAND \n"));
	}

	public function testBuiltinDeutschlandMapsToDe(): void
	{
		$this->assertSame('DE', ValueMapLogic::builtinCountryCanonical('Deutschland'));
		$this->assertSame('DE', ValueMapLogic::builtinCountryCanonical('germany'));
	}

	public function testValidIsoAccepted(): void
	{
		$this->assertTrue(ValueMapLogic::isValidIsoCountry('de'));
		$this->assertTrue(ValueMapLogic::isValidIsoCountry('AT'));
		$this->assertFalse(ValueMapLogic::isValidIsoCountry('D'));
		$this->assertFalse(ValueMapLogic::isValidIsoCountry('DEX'));
	}

	public function testUnknownAliasReturnsNull(): void
	{
		$this->assertNull(ValueMapLogic::builtinCountryCanonical('Narnia'));
	}
}
