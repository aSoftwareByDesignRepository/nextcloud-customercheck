<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Jump nav must use list markup + bar layout CSS — bare <a> stacks look broken.
 */
final class JumpNavContractTest extends TestCase
{
	public function testPartialRequiresOrderedListAndHashHrefs(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-jump-nav.php');
		$this->assertStringContainsString('crm-jump-nav__list', $src);
		$this->assertStringContainsString('<ol', $src);
		$this->assertStringContainsString('crm-jump-nav__item', $src);
		$this->assertStringContainsString('data-crm-jump-nav="1"', $src);
		$this->assertStringContainsString("str_starts_with(\$href, '#')", $src);
		$this->assertStringContainsString('On this page', $src);
		$this->assertStringContainsString('More sections', $src);
	}

	public function testCompanyDetailUsesFocusTabsNotJumpNav(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-360-focus-tabs.php', $html);
		$this->assertStringNotContainsString('crm-jump-nav.php', $html);
		foreach (['crm-glance', 'crm-communication', 'crm-persons', 'crm-next-steps', 'crm-contracts', 'crm-billing', 'crm-deals', 'crm-projects', 'crm-documents', 'crm-identity-links', 'crm-prepare-meeting', 'crm-timeline', 'crm-account-plan', 'crm-tickets', 'crm-invoices'] as $id) {
			$this->assertStringContainsString('id="' . $id . '"', $html);
		}
		$this->assertStringContainsString("\$l->t('Talk')", $html);
		$this->assertStringContainsString("\$l->t('To-do')", $html);
		$this->assertStringNotContainsString("'href' => '#crm-promises'", $html);
		$this->assertStringNotContainsString("'href' => '#crm-activities'", $html);
		$this->assertStringNotContainsString("'href' => '#crm-open-tasks'", $html);
		$this->assertStringContainsString('crm-360-focus', $html);
		$this->assertStringNotContainsString('How to use this page', $html);
	}

	public function testBarCssIsChipStripNotSidebarColumn(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('crm-app--company-detail .crm-jump-nav--bar', $css);
		$this->assertStringContainsString('border-radius: 999px', $css);
		$this->assertStringContainsString('scroll-margin-top', $css);
		$this->assertStringContainsString('prefers-reduced-motion', $css);
		$this->assertStringContainsString('forced-colors', $css);
	}

	public function testFocusScriptIsRegisteredForCompanyDetail(): void
	{
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("registerFrontEndAssets('pages/company-detail', null, ['common/identity-links'], ['common/company360-focus', 'common/folder-picker', 'common/glance-summary', 'common/company360-present', 'common/deal-checklist-ux', 'common/mail-sync-now', 'common/rich-mail-composer', 'common/shared-mail-send', 'pages/persona'])", $controller);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-focus.js');
		$this->assertStringContainsString('aria-selected', $js);
		$this->assertStringContainsString('hashchange', $js);
		$this->assertStringContainsString('preventDefault', $js);
	}

	public function testPartialSkipsUnsafeOrEmptyItems(): void
	{
		require_once __DIR__ . '/Support/template_stubs.php';
		$l = new class {
			public function t(string $text, array $parameters = []): string
			{
				return $text;
			}
		};
		$jumpNavAriaLabel = 'Jump';
		$jumpNavLayout = 'bar';
		$jumpNavItems = [
			['href' => '#crm-agreements', 'label' => 'Agreements'],
			['href' => 'https://evil.example', 'label' => 'Nope'],
			['href' => '#crm-deals', 'label' => ''],
			['href' => '', 'label' => 'Empty'],
			['href' => '#crm-timeline', 'label' => 'Timeline'],
		];
		ob_start();
		include dirname(__DIR__, 2) . '/templates/common/crm-jump-nav.php';
		$html = (string)ob_get_clean();
		$this->assertStringContainsString('href="#crm-agreements"', $html);
		$this->assertStringContainsString('href="#crm-timeline"', $html);
		$this->assertStringNotContainsString('evil.example', $html);
		$this->assertSame(2, substr_count($html, 'crm-jump-nav__link'));
	}
}
