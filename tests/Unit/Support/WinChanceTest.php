<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\WinChance;
use PHPUnit\Framework\TestCase;

final class WinChanceTest extends TestCase
{
	public function testEmptyIsUnset(): void
	{
		$this->assertNull(WinChance::normalize(null));
		$this->assertNull(WinChance::normalize(''));
		$this->assertNull(WinChance::normalize('  '));
		$this->assertNull(WinChance::normalize('Not set'));
		$this->assertNull(WinChance::normalize(0));
	}

	public function testPercentsMapToBuckets(): void
	{
		$this->assertSame('pipeline', WinChance::normalize(25));
		$this->assertSame('pipeline', WinChance::normalize('25%'));
		$this->assertSame('upside', WinChance::normalize('50'));
		$this->assertSame('commit', WinChance::normalize('75%'));
		$this->assertSame('certain', WinChance::normalize(100));
		$this->assertSame('certain', WinChance::normalize('100%'));
	}

	public function testCanonicalBucketsPassThrough(): void
	{
		$this->assertSame('pipeline', WinChance::normalize('pipeline'));
		$this->assertSame('upside', WinChance::normalize('Upside'));
		$this->assertSame('commit', WinChance::normalize('commit'));
		$this->assertSame('certain', WinChance::normalize('certain'));
	}

	public function testUnknownIsInvalid(): void
	{
		$this->assertSame(WinChance::INVALID, WinChance::normalize('33'));
		$this->assertSame(WinChance::INVALID, WinChance::normalize('won'));
		$this->assertSame(WinChance::INVALID, WinChance::normalize(12.5));
		$this->assertSame(WinChance::INVALID, WinChance::normalize(true));
	}

	public function testHighConfidenceIsCommitAndCertainOnly(): void
	{
		$this->assertTrue(WinChance::isHighConfidence('commit'));
		$this->assertTrue(WinChance::isHighConfidence('certain'));
		$this->assertFalse(WinChance::isHighConfidence('upside'));
		$this->assertFalse(WinChance::isHighConfidence('pipeline'));
		$this->assertFalse(WinChance::isHighConfidence(null));
	}

	public function testHundredPercentIsNotWon(): void
	{
		$this->assertSame('certain', WinChance::normalize(100));
		$this->assertNotSame('won', WinChance::normalize(100));
	}

	public function testPercentLabelsAreLocaleInvariantAndSprintfSafe(): void
	{
		$this->assertSame('25%', WinChance::percentLabel(25));
		$this->assertSame('100%', WinChance::percentLabel(100));
		foreach ([25, 50, 75, 100] as $percent) {
			$label = WinChance::percentLabel($percent);
			$this->assertSame($label, vsprintf(str_replace('%', '%%', $label), []));
		}
	}
}
