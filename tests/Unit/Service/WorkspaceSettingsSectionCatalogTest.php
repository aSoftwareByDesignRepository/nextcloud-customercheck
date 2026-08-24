<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\WorkspaceSettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class WorkspaceSettingsSectionCatalogTest extends TestCase
{
	private WorkspaceSettingsSectionCatalog $catalog;
	private IL10N $l10n;

	protected function setUp(): void
	{
		$this->catalog = new WorkspaceSettingsSectionCatalog();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $t): string => $t);
	}

	public function testDefaultSectionIsInAllowlist(): void
	{
		self::assertSame('preferences', WorkspaceSettingsSectionCatalog::DEFAULT_SECTION);
		self::assertContains(WorkspaceSettingsSectionCatalog::DEFAULT_SECTION, WorkspaceSettingsSectionCatalog::SECTIONS);
	}

	public function testSectionsAreUniqueAndStable(): void
	{
		$sections = WorkspaceSettingsSectionCatalog::SECTIONS;
		self::assertSame($sections, array_values(array_unique($sections)));
		self::assertSame(
			['my-email', 'mailbox', 'preferences', 'checklists', 'review-sources', 'promises', 'integrations'],
			$sections,
		);
	}

	public function testIsSectionAndRouteRequirement(): void
	{
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $section) {
			self::assertTrue($this->catalog->isSection($section));
		}
		self::assertFalse($this->catalog->isSection('access'));
		self::assertFalse($this->catalog->isSection('../mailbox'));
		self::assertSame(implode('|', WorkspaceSettingsSectionCatalog::SECTIONS), WorkspaceSettingsSectionCatalog::routeRequirement());
	}

	public function testVisibilityGatesManagerOnlySections(): void
	{
		self::assertTrue($this->catalog->isVisible('preferences', false));
		self::assertTrue($this->catalog->isVisible('integrations', false));
		self::assertTrue($this->catalog->isVisible('my-email', false));
		self::assertFalse($this->catalog->isVisible('mailbox', false));
		self::assertFalse($this->catalog->isVisible('checklists', false));
		self::assertFalse($this->catalog->isVisible('review-sources', false));
		self::assertFalse($this->catalog->isVisible('promises', false));
		self::assertTrue($this->catalog->isVisible('mailbox', true));
		self::assertTrue($this->catalog->isVisible('my-email', true));
		self::assertTrue($this->catalog->isVisible('checklists', true));
		self::assertFalse($this->catalog->isVisible('bogus', true));
	}

	public function testDefaultSectionPrefersMailboxForManagers(): void
	{
		self::assertSame('mailbox', $this->catalog->defaultSection(true));
		self::assertSame('my-email', $this->catalog->defaultSection(false));
	}

	public function testLabelsCoverEverySection(): void
	{
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $section) {
			self::assertNotSame('', $this->catalog->label($this->l10n, $section));
			self::assertNotSame('', $this->catalog->navLabel($this->l10n, $section));
			self::assertNotSame('', $this->catalog->help($this->l10n, $section));
		}
	}
}
