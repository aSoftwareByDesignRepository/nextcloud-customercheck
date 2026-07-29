<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Service\PageQuickStartCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Shell must inject DutyCheck-style Quick start how-tos on every main page.
 */
final class PageQuickStartContractTest extends TestCase
{
	public function testPageStartIncludesQuickStartPartial(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/page-start.php');
		$this->assertStringContainsString("crm-quickstart.php", $src);
		$this->assertStringContainsString('pageHelp', $src);
		$this->assertStringContainsString('crm-page-header__lead', $src);
	}

	public function testQuickStartPartialIsDismissibleAndHiddenByDefault(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-quickstart.php');
		$this->assertStringContainsString('crm-quickstart', $src);
		$this->assertStringContainsString('data-crm-dismiss-hint', $src);
		$this->assertStringContainsString('Hide tips', $src);
		$this->assertStringContainsString('Quick start', $src);
		$this->assertMatchesRegularExpression('/\bhidden\b/', $src);
	}

	public function testComponentsWireDismissibleHints(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/components.js');
		$this->assertStringContainsString('wireAllDismissibleHints', $js);
		$this->assertStringContainsString('data-crm-dismiss-hint', $js);
		$this->assertStringContainsString('crm:hint:', $js);
		$this->assertStringContainsString('wireAllDismissibleHints()', $js);
	}

	public function testShellTraitInjectsQuickstartAndPageHelpCatalog(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageShellTrait.php');
		$this->assertStringContainsString('PageQuickStartCatalog', $src);
		$this->assertStringContainsString("'quickstart'", $src);
		$this->assertStringContainsString('PageHelpCatalog', $src);
		$this->assertStringContainsString('preserveHelp', $src);
	}

	public function testCssCoversQuickStartLayout(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-quickstart', $css);
		$this->assertStringContainsString('.crm-quickstart__list', $css);
		$this->assertStringContainsString('.crm-hint-dismiss', $css);
		$this->assertMatchesRegularExpression('/min-height:\\s*44px/', $css);
	}

	public function testAgreementCreateHasPageHelp(): void
	{
		$helpSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString('agreement-form', $helpSrc);
		$this->assertStringContainsString('An agreement always belongs to one company', $helpSrc);
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("t('New agreement')", $ctrl);
		$this->assertDoesNotMatchRegularExpression(
			"/agreementNew[\\s\\S]{0,400}mergeShellPageParams\\(\\s*'agreement-form'\\s*,\\s*\\\$this->l10n->t\\('New agreement'\\)\\s*,\\s*''\\s*,/m",
			$ctrl,
			'agreementNew must not pass an empty pageHelp'
		);
	}

	public function testRequiredPagesMatchCatalog(): void
	{
		$this->assertContains('today', PageQuickStartCatalog::requiredPageIds());
		$this->assertContains('settings', PageQuickStartCatalog::requiredPageIds());
		$this->assertContains('app-settings', PageQuickStartCatalog::requiredPageIds());
		$this->assertNotContains('access-denied', PageQuickStartCatalog::requiredPageIds());
	}
}
