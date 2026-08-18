<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\LeadSource;
use PHPUnit\Framework\TestCase;

final class LeadSourceTest extends TestCase
{
	public function testNormalizeEmptyDefaultsManualWhenRequested(): void
	{
		$this->assertSame('manual', LeadSource::normalize('', true));
		$this->assertNull(LeadSource::normalize('', false));
	}

	public function testNormalizeCanonicalSlug(): void
	{
		$this->assertSame('website', LeadSource::normalize('website', false));
		$this->assertSame('referral', LeadSource::normalize('Referral', false));
	}

	public function testNormalizeAlias(): void
	{
		$this->assertSame('website', LeadSource::normalize('inbound', false));
		$this->assertSame('event', LeadSource::normalize('trade_show', false));
	}

	public function testUnknownMapsToOther(): void
	{
		$this->assertSame('other', LeadSource::normalize('random-campaign-2026', false));
	}

	public function testIsKnown(): void
	{
		$this->assertTrue(LeadSource::isKnown('manual'));
		$this->assertFalse(LeadSource::isKnown('bogus'));
	}
}
