<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Native dialog / modal Bachus UX — AudioCheck-style single-flight, XSS, a11y.
 */
final class DialogUxContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testComponentsSingleFlightAriaModalAndNoDoubleTrap(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/components.js');
		$this->assertStringContainsString('_closeOtherContentDialogs', $js);
		$this->assertStringContainsString("if (!modal.classList.contains('confirm-dialog')) {\n        this._closeOtherContentDialogs(modal);\n      }", $js);
		$this->assertStringContainsString('_activeConfirmCleanup', $js);
		$this->assertStringContainsString("dialog.setAttribute('aria-modal', 'true')", $js);
		$this->assertStringContainsString('Focus trap is _bindFocusTrap only', $js);
		$this->assertStringContainsString('${this._escapeHtml(message)}', $js);
		$this->assertStringContainsString('optionalCheckbox', $js);
		$this->assertStringContainsString('confirm-dialog__optional', $js);
		$this->assertStringContainsString('optionalChecked', $js);
		$this->assertStringContainsString('safeExternalHref', $js);
		$this->assertStringContainsString('buildMailPackDialogHtml', $js);
		$this->assertStringContainsString('Always rebuild when an id is reused', $js);
		// No second Tab trap on confirms (double-trap race).
		$this->assertStringNotContainsString(
			"dialog.addEventListener('keydown', (e) => {\n        if (e.key !== 'Tab') return;",
			$js,
		);
	}

	public function testDialogPresentRegisteredBeforeComponents(): void
	{
		$assets = (string)file_get_contents($this->root . '/lib/Service/FrontEndAssetService.php');
		$presentPos = strpos($assets, "addScript(Application::APP_ID, 'common/dialog-present')");
		$componentsPos = strpos($assets, "addScript(Application::APP_ID, 'common/components')");
		$this->assertNotFalse($presentPos);
		$this->assertNotFalse($componentsPos);
		$this->assertTrue($presentPos < $componentsPos, 'dialog-present must load before components');
		$boot = (string)file_get_contents($this->root . '/js/common/dialog-present.js');
		$this->assertStringContainsString('safeExternalHref', $boot);
		$this->assertStringContainsString('buildMailPackDialogHtml', $boot);
		$utils = (string)file_get_contents($this->root . '/js/utils/dialog-present.js');
		$this->assertStringContainsString("startsWith('javascript:')", $utils);
	}

	public function testDialogFormsUseCrmFormStack(): void
	{
		foreach ([
			'js/pages/pipeline.js',
			'js/pages/agreements.js',
			'js/pages/agreement-form.js',
			'js/pages/company-detail.js',
			'js/common/shared-mail-send.js',
		] as $rel) {
			$js = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringContainsString('crm-form crm-form--stack', $js, $rel);
		}
		foreach ([
			'js/pages/pipeline.js',
			'js/pages/agreements.js',
			'js/pages/agreement-form.js',
			'js/common/shared-mail-send.js',
		] as $rel) {
			$js = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertDoesNotMatchRegularExpression(
				"/class=\"crm-form\"(?![^\"]*crm-form--stack)/",
				$js,
				$rel . ' dialog forms must use crm-form--stack',
			);
		}
		$pipeline = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$this->assertStringContainsString("t('Win chance')", $pipeline);
		$this->assertStringNotContainsString("t('Forecast')", $pipeline);
		$this->assertStringContainsString('crm-advanced__body', $pipeline);
		$company = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString("type: 'company'", $company);
		$this->assertStringContainsString('mountListPanel', $company);
		$this->assertStringContainsString('crm-advanced__body', $company);
		$this->assertStringContainsString("escapeHtml(t('Save'))", $company);
		$this->assertStringNotContainsString("'/companies?limit=200'", $company);
		$shared = (string)file_get_contents($this->root . '/js/common/shared-mail-send.js');
		$this->assertStringContainsString("size: 'md'", $shared);
		$agreements = (string)file_get_contents($this->root . '/js/pages/agreements.js');
		$this->assertStringContainsString("escapeHtml(t('Date'))", $agreements);
		$this->assertStringContainsString("escapeHtml(t('Save'))", $agreements);
	}

	public function testContentDialogsGetSharedPolishClassAndCss(): void
	{
		$components = (string)file_get_contents($this->root . '/js/common/components.js');
		$this->assertStringContainsString('crm-dialog--content', $components);
		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString("common/dialog-forms.css", $appCss);
		$css = (string)file_get_contents($this->root . '/css/common/dialog-forms.css');
		$this->assertStringContainsString('crm-dialog--content', $css);
		$this->assertStringContainsString('min(40rem', $css);
		$this->assertStringContainsString('calc(100vw - 1rem)', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
	}

	public function testDeliveryPackAndPromptFieldsEscapeUntrustedContent(): void
	{
		$company = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString('buildMailPackDialogHtml', $company);
		$this->assertStringContainsString('escapeHtml(f.label || fname)', $company);
		$this->assertStringContainsString('escapeHtml(t(\'More options\'))', $company);
		$this->assertStringNotContainsString("content: '<p><strong>' + (pack.subject || '')", $company);
		$agreements = (string)file_get_contents($this->root . '/js/pages/agreements.js');
		$this->assertStringContainsString('buildMailPackDialogHtml', $agreements);
		$shared = (string)file_get_contents($this->root . '/js/common/shared-mail-send.js');
		$this->assertStringContainsString('safeExternalHref', $shared);
		$this->assertStringContainsString('rel="noopener noreferrer"', $shared);
	}
}
