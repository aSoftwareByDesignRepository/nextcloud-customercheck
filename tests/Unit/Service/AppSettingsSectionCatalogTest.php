<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\AppSettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class AppSettingsSectionCatalogTest extends TestCase
{
	private AppSettingsSectionCatalog $catalog;
	private IL10N $l10n;

	protected function setUp(): void
	{
		$this->catalog = new AppSettingsSectionCatalog();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);
	}

	public function testDefaultSectionIsInAllowlist(): void
	{
		self::assertSame('access', AppSettingsSectionCatalog::DEFAULT_SECTION);
		self::assertContains(AppSettingsSectionCatalog::DEFAULT_SECTION, AppSettingsSectionCatalog::SECTIONS);
	}

	public function testSectionsAreUniqueAndStable(): void
	{
		$sections = AppSettingsSectionCatalog::SECTIONS;
		self::assertSame($sections, array_values(array_unique($sections)));
		self::assertSame(
			['access', 'jobs', 'tokens', 'license', 'product-codes'],
			$sections,
		);
	}

	public function testIsSectionAndRouteRequirement(): void
	{
		foreach (AppSettingsSectionCatalog::SECTIONS as $section) {
			self::assertTrue($this->catalog->isSection($section));
		}
		self::assertFalse($this->catalog->isSection('mailbox'));
		self::assertSame(implode('|', AppSettingsSectionCatalog::SECTIONS), AppSettingsSectionCatalog::routeRequirement());
	}

	public function testLegacyAnchorsMapOnlyToKnownSections(): void
	{
		self::assertNotSame([], AppSettingsSectionCatalog::LEGACY_ANCHORS);
		foreach (AppSettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertNotSame('', $anchor);
			self::assertTrue($this->catalog->isSection($section), "Anchor {$anchor} → {$section}");
		}
	}

	public function testLabelsCoverEverySection(): void
	{
		foreach (AppSettingsSectionCatalog::SECTIONS as $section) {
			self::assertNotSame('', $this->catalog->label($this->l10n, $section));
			self::assertNotSame('', $this->catalog->navLabel($this->l10n, $section));
			self::assertNotSame('', $this->catalog->help($this->l10n, $section));
		}
	}
}
