<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus clarity contracts: nav jobs, owners visible, pipeline board scan chrome.
 */
final class NavClarityContractTest extends TestCase
{
	public function testNavigationGroupsPlainJobs(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString('crm-nav__group', $html);
		$this->assertStringContainsString("\$l->t('Do now')", $html);
		$this->assertStringContainsString("\$l->t('Customers')", $html);
		$this->assertStringContainsString("\$l->t('Deals & money')", $html);
		$this->assertStringContainsString("\$l->t('My customers')", $html);
		$this->assertStringContainsString("\$l->t('Plans')", $html);
		$this->assertStringContainsString("\$l->t('Deals, customers & money')", $html);
		$this->assertStringContainsString("\$l->t('Clear what needs you now')", $html);
		$this->assertStringContainsString("\$l->t('See how the book is doing')", $html);
		$this->assertStringContainsString('title="<?php p($jobTitle); ?>"', $html);
	}

	public function testCompaniesListShowsAccountOwner(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		$this->assertStringContainsString("\$l->t('Owner')", $html);
		$this->assertStringContainsString('getAccountOwnerUserId', $html);
		$this->assertStringContainsString('data-crm-account-owner', $html);
		$this->assertStringContainsString("\$l->t('Unassigned')", $html);
	}

	public function testPipelineBoardShowsColumnSumsAndOwner(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString('crm-kanban__column-count', $html);
		$this->assertStringContainsString('crm-kanban__column-sum', $html);
		$this->assertStringContainsString('data-crm-stage-count', $html);
		$this->assertStringContainsString('crm-kanban__card-owner', $html);
		$this->assertStringContainsString("\$l->t('Unassigned')", $html);
		// Kanban columns are role=group; outer board must not be role=list (axe aria-required-children).
		$this->assertStringContainsString('role="region"', $html);
		$this->assertStringContainsString("\$l->t('Deal stages board')", $html);
		$this->assertStringNotContainsString('class="crm-kanban" role="list"', $html);
		$this->assertStringNotContainsString('getURLGenerator()', $html);
		$this->assertStringContainsString('$urlGenerator->linkToRoute', $html);
		$this->assertStringContainsString('crm-pipeline-period-custom', $html);
	}

	public function testPageHelpLeadsArePlainSentences(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringNotContainsString('Job:', $src);
		$this->assertStringNotContainsString('Aufgabe:', $src);
		$this->assertStringContainsString('workspace', $src);
		$this->assertGreaterThanOrEqual(15, substr_count($src, "\$l->t('"));
	}

	public function testNavUsesOverviewThisWeekAndDeals(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString("\$l->t('Overview')", $html);
		$this->assertStringContainsString("\$l->t('This week')", $html);
		$this->assertStringContainsString("\$l->t('Deals')", $html);
		$this->assertStringNotContainsString("\$l->t('Pulse')", $html);
		$this->assertStringNotContainsString("\$l->t('Brief')", $html);
		$this->assertStringNotContainsString("\$l->t('Pipeline')", $html);
	}

	public function testTodayTitleMatchesNav(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("\$this->l10n->t('Today')", $src);
		$this->assertStringNotContainsString("\$this->l10n->t('Command Board')", $src);
		$this->assertStringContainsString("\$this->l10n->t('My customers')", $src);
		$this->assertStringContainsString("\$this->l10n->t('Overview')", $src);
		$this->assertStringContainsString("\$this->l10n->t('This week')", $src);
		$this->assertStringContainsString("\$this->l10n->t('Deals')", $src);
		$this->assertStringNotContainsString("\$this->l10n->t('Business Pulse')", $src);
		$this->assertStringNotContainsString("\$this->l10n->t('Weekly Brief')", $src);
		$this->assertStringContainsString('workspaceMemberOptions', $src);
		$this->assertStringContainsString('WorkspaceService', $src);
	}
}
