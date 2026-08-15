<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus personal-mail Sales Inbox — giant tabs, collapsed Cc, empty Connect CTA, quiet chrome.
 */
final class BachusPersonalMailUxContractTest extends TestCase
{
	public function testSalesInboxTemplateIsBachusSimplified(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/sales-inbox.php');
		$this->assertStringContainsString('data-crm-bachus-mail="1"', $html);
		$this->assertStringContainsString('Your mail, the team inbox, and items that still need a company — one place.', $html);
		$this->assertStringContainsString('id="crm-sales-inbox-empty-cta"', $html);
		$this->assertStringContainsString('Connect my email', $html);
		$this->assertStringContainsString('id="crm-sales-compose-cc-toggle"', $html);
		$this->assertStringContainsString('Add Cc', $html);
		$this->assertStringContainsString('id="crm-sales-compose-cc-wrap" hidden', $html);
		$this->assertStringContainsString('id="crm-sales-inbox-account-wrap" hidden', $html);
		$this->assertStringContainsString('id="crm-sales-inbox-status"', $html);
		$this->assertStringContainsString('Update mail', $html);
		$this->assertStringContainsString('Tap a message to read it.', $html);
		$this->assertStringContainsString('crm-btn--lg', $html);
		// Tabs must not use the tiny sm variant (touch targets).
		$this->assertStringNotContainsString('crm-btn--sm crm-tab crm-sales-inbox__tab', $html);
		$this->assertStringNotContainsString('crm-btn--sm" role="tab"', $html);
	}

	public function testSalesInboxJsAutoSyncQuietAndCcToggle(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('autoSyncedOnce', $js);
		$this->assertStringContainsString('syncPersonal({ quiet: true })', $js);
		$this->assertStringContainsString('crm-sales-compose-cc-toggle', $js);
		$this->assertStringContainsString('crm-sales-inbox-empty-cta', $js);
		$this->assertStringContainsString('Connect my email', $js);
		$this->assertStringContainsString('accountFilterVisible', $js);
		$this->assertStringContainsString('closeSalesPreview', $js);
		$this->assertStringContainsString('aria-expanded', $js);
		$this->assertStringContainsString('snippetUseful', $js);
		$this->assertStringContainsString('Second click / Enter on the open row collapses', $js);
	}

	public function testSalesInboxCssGiantTouchTargets(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-sales-inbox__tab', $css);
		$this->assertStringContainsString('min-height: 48px', $css);
		$this->assertStringContainsString('#crm-sales-inbox-reply.crm-btn--lg', $css);
		$this->assertStringContainsString('.crm-rich-mail__quote', $css);
		$this->assertStringContainsString('.crm-sales-compose__hint', $css);
		$this->assertStringContainsString('.crm-sales-compose__field-head', $css);
		$this->assertStringContainsString('.crm-sales-compose__recipients', $css);
		$this->assertStringContainsString('width: 100%', $css);
		$this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-rich-mail__editor,\s*#app-content\.crm-app \.crm-rich-mail__quote\s*\{[^}]*width:\s*100%/s',
			$css,
		);
	}

	public function testSalesInboxComposeUsesSendToNotAmbiguousTo(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/sales-inbox.php');
		$this->assertStringContainsString("\$l->t('Send to')", $html);
		$this->assertStringNotContainsString("for=\"crm-sales-compose-to\"><?php p(\$l->t('To'));", $html);
		$this->assertStringContainsString("\$l->t('Your message')", $html);
		$this->assertStringContainsString('crm-sales-compose__hint', $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('quotedPlain: quoted', $js);
		$this->assertStringContainsString('lastPreviewPlain', $js);
		$this->assertStringNotContainsString("setAttribute('data-crm-quote'", $js);
		$rich = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/rich-mail-composer.js');
		$this->assertStringContainsString('crm-rich-mail__quote', $rich);
		$this->assertStringContainsString('getQuotedPlain', $rich);
	}
}
