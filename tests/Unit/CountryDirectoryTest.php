<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Support\CountryDirectory;
use PHPUnit\Framework\TestCase;

/** CountryDirectory — full ISO 3166-1 (+XK) catalogue, localized labels. */
final class CountryDirectoryTest extends TestCase
{
	public function testCatalogueCoversWholeIsoList(): void
	{
		$codes = CountryDirectory::codes();
		$this->assertGreaterThanOrEqual(249, count($codes), 'ISO 3166-1 alpha-2 has 249 assigned codes + XK');
		foreach ($codes as $code) {
			$this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code, $code . ' must be alpha-2');
		}
		$this->assertSame(count($codes), count(array_unique($codes)), 'no duplicate codes');
	}

	public function testBrazilAndCoreMarketsAreSelectable(): void
	{
		// The actual bug report: Brasil missing from the company form.
		foreach (['BR', 'DE', 'AT', 'CH', 'US', 'GB', 'JP', 'AU', 'ZA', 'XK'] as $code) {
			$this->assertTrue(CountryDirectory::isKnown($code), $code . ' must be selectable');
		}
	}

	public function testPseudoAndCldrOnlyCodesAreNotOffered(): void
	{
		// CLDR-only / exceptional reservations must not leak into the picker.
		foreach (['EU', 'EZ', 'UN', 'ZZ', 'QO', 'AC', 'TA', 'CP', 'DG', 'EA', 'IC', 'SU', 'FX'] as $code) {
			$this->assertFalse(CountryDirectory::isKnown($code), $code . ' is not an ISO country');
		}
	}

	public function testIsKnownNormalizesInput(): void
	{
		$this->assertTrue(CountryDirectory::isKnown('br'));
		$this->assertTrue(CountryDirectory::isKnown(' br '));
		$this->assertFalse(CountryDirectory::isKnown(''));
		$this->assertFalse(CountryDirectory::isKnown('BRA'));
	}

	public function testOptionsReturnLocalizedSortedLabels(): void
	{
		$options = CountryDirectory::options('en');
		$this->assertArrayHasKey('BR', $options);
		$this->assertArrayHasKey('DE', $options);
		$this->assertSame(count(CountryDirectory::codes()), count($options));
		// Sorted by label — the keys must not be in code order.
		$labels = array_values($options);
		$sorted = $labels;
		$collator = class_exists(\Collator::class) ? \Collator::create('en') : null;
		if ($collator !== null) {
			uasort($sorted, static fn (string $a, string $b): int => $collator->compare($a, $b));
		} else {
			sort($sorted, SORT_LOCALE_STRING);
		}
		$this->assertSame($sorted, $labels, 'options must be sorted by their label');
	}

	public function testLabelFallsBackToCodeForUnknown(): void
	{
		$this->assertSame('QQ', CountryDirectory::label('QQ', 'en'));
		$this->assertSame('', CountryDirectory::label('', 'en'));
	}

	public function testGermanLabelsWhenCldrAvailable(): void
	{
		if (!class_exists(\Punic\Territory::class)) {
			$this->markTestSkipped('Punic not autoloadable in this test context (bare vendor run)');
		}
		$this->assertSame('Brasilien', CountryDirectory::label('BR', 'de'));
		$this->assertSame('Deutschland', CountryDirectory::label('DE', 'de'));
		$options = CountryDirectory::options('de');
		$this->assertSame('Brasilien', $options['BR']);
	}

	public function testEnglishLabel(): void
	{
		// Embedded fallback and CLDR 'en' agree on the canonical English name.
		$this->assertSame('Brazil', CountryDirectory::label('BR', 'en'));
		$this->assertSame('Germany', CountryDirectory::label('DE', 'en'));
	}
}
