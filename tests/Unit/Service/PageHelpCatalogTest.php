<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\PageHelpCatalog;
use OCA\CustomerCheck\Service\PageQuickStartCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class PageHelpCatalogTest extends TestCase
{
	private PageHelpCatalog $catalog;

	protected function setUp(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$this->catalog = new PageHelpCatalog($l10n);
	}

	public function testEveryRequiredPageHasProvenanceHelp(): void
	{
		foreach (PageHelpCatalog::requiredPageIds() as $pageId) {
			$help = $this->catalog->forPage($pageId);
			$this->assertNotNull($help, $pageId . ' must have page help');
			$this->assertNotSame('', trim((string)$help), $pageId);
			$lower = mb_strtolower((string)$help);
			$mentionsOrigin = str_contains($lower, 'workspace')
				|| str_contains($lower, 'customercheck')
				|| str_contains($lower, 'invoicecheck')
				|| str_contains($lower, 'projectcheck')
				|| str_contains($lower, 'ticketcheck')
				|| str_contains($lower, 'mailbox')
				|| str_contains($lower, 'nextcloud')
				|| str_contains($lower, 'csv')
				|| str_contains($lower, 'agpl')
				|| str_contains($lower, 'linked');
			$this->assertTrue(
				$mentionsOrigin,
				$pageId . ' page help must say where data comes from: ' . $help
			);
		}
	}

	public function testRequiredPagesMatchQuickStartCatalog(): void
	{
		$this->assertSame(
			PageQuickStartCatalog::requiredPageIds(),
			PageHelpCatalog::requiredPageIds(),
		);
	}

	public function testTeamHelpExplainsGreenAmberLegend(): void
	{
		$help = (string)$this->catalog->forPage('team');
		$this->assertStringContainsString('green', mb_strtolower($help));
		$this->assertStringContainsString('amber', mb_strtolower($help));
		$this->assertStringContainsString('workspace', mb_strtolower($help));
	}

	public function testWorkloadAliasesToTeam(): void
	{
		$this->assertSame(
			$this->catalog->forPage('team'),
			$this->catalog->forPage('workload'),
		);
	}

	public function testUnknownPageReturnsNull(): void
	{
		$this->assertNull($this->catalog->forPage('access-denied'));
		$this->assertNull($this->catalog->forPage('nope'));
	}

	public function testPulseAndMoneyAvoidTaxLecture(): void
	{
		foreach (['pulse', 'money'] as $pageId) {
			$help = (string)$this->catalog->forPage($pageId);
			$this->assertStringNotContainsString('GoBD', $help);
			$this->assertStringNotContainsString('tax filing', $help);
			$this->assertStringNotContainsString('Ops overview', $help);
		}
	}
}
