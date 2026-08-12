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
			$mentionsOrigin = str_contains($lower, 'customer')
				|| str_contains($lower, 'companies')
				|| str_contains($lower, 'company')
				|| str_contains($lower, 'teammate')
				|| str_contains($lower, 'team')
				|| str_contains($lower, 'deal')
				|| str_contains($lower, 'promise')
				|| str_contains($lower, 'account plan')
				|| str_contains($lower, 'mailbox')
				|| str_contains($lower, 'mail')
				|| str_contains($lower, 'invoice')
				|| str_contains($lower, 'unmatched')
				|| str_contains($lower, 'customercheck')
				|| str_contains($lower, 'invoicecheck')
				|| str_contains($lower, 'projectcheck')
				|| str_contains($lower, 'ticketcheck')
				|| str_contains($lower, 'nextcloud')
				|| str_contains($lower, 'csv')
				|| str_contains($lower, 'json')
				|| str_contains($lower, 'agpl')
				|| str_contains($lower, 'linked')
				|| str_contains($lower, 'waiting on you')
				|| str_contains($lower, 'follow-up')
				|| str_contains($lower, 'license')
				|| str_contains($lower, 'renewal')
				|| str_contains($lower, 'edited')
				|| str_contains($lower, 'pick what to keep')
				|| str_contains($lower, 'preferences')
				|| str_contains($lower, 'next call')
				|| str_contains($lower, 'open work')
				|| str_contains($lower, 'money');
			$this->assertTrue(
				$mentionsOrigin,
				$pageId . ' page help must say where data comes from: ' . $help
			);
			$this->assertStringNotContainsString('workspace', $lower, $pageId);
			$this->assertStringNotContainsString('customer book', $lower, $pageId);
		}
	}

	public function testRequiredPagesAreSupersetOfQuickStart(): void
	{
		$help = PageHelpCatalog::requiredPageIds();
		$quick = PageQuickStartCatalog::requiredPageIds();
		foreach ($quick as $pageId) {
			$this->assertContains($pageId, $help, 'help must cover every quickstart page');
		}
		$this->assertContains('company-detail', $help);
		$this->assertNotContains('company-detail', $quick);
	}

	public function testTeamHelpExplainsGreenAmberLegend(): void
	{
		$help = (string)$this->catalog->forPage('team');
		$this->assertStringContainsString('needs help', mb_strtolower($help));
		$this->assertStringContainsString('on track', mb_strtolower($help));
		$this->assertStringContainsString('teammate', mb_strtolower($help));
		$this->assertStringNotContainsString('workspace', mb_strtolower($help));
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
