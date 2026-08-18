<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Aristoteles: CustomerCheck must follow planning/design-system (AZC-derived --crm-*).
 *
 * Critical WCAG gap this pins: solid danger CTAs must use --crm-danger-fill
 * (--color-element-error), never pale --color-error as a button fill.
 */
final class DesignSystemAlignmentContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testTokensMirrorDesignSystemScale(): void
	{
		$tokens = (string)file_get_contents($this->root . '/css/common/tokens.css');
		foreach (['--crm-space-1: 4px', '--crm-space-5: 24px', '--crm-radius-sm: 6px', '--crm-radius-md: 12px', '--crm-fs-2xl: 1.875rem', '--crm-touch: 44px', '--crm-touch-lg: 48px'] as $needle) {
			$this->assertStringContainsString($needle, $tokens);
		}
		$this->assertStringContainsString('--crm-radius-lg: var(--border-radius-large', $tokens);
		$this->assertStringContainsString('--crm-scrim:', $tokens);
		$this->assertStringContainsString('--crm-danger-fill: var(--color-element-error', $tokens);
		$this->assertStringContainsString('--crm-danger-on-fill:', $tokens);
		$this->assertStringContainsString('body[data-theme-dark]', $tokens);
	}

	public function testPrimaryAliasUsesElementNotBrandPrimary(): void
	{
		$colors = (string)file_get_contents($this->root . '/css/common/colors.css');
		$this->assertStringContainsString('--arbeitszeitcheck-color-primary: var(--color-primary-element);', $colors);
		$this->assertStringNotContainsString('--arbeitszeitcheck-color-primary: var(--color-primary);', $colors);
		$this->assertStringContainsString(
			'--arbeitszeitcheck-color-success-light: color-mix(in srgb, var(--color-success-text, var(--color-success)) 15%, var(--color-main-background))',
			$colors,
		);
		$this->assertStringNotContainsString(
			'--arbeitszeitcheck-color-success-light: color-mix(in srgb, var(--color-success) 15%, transparent)',
			$colors,
		);
		$this->assertStringContainsString('--arbeitszeitcheck-color-button-danger: var(--crm-danger-fill', $colors);
		$this->assertDoesNotMatchRegularExpression(
			'/--arbeitszeitcheck-color-button-danger:\s*var\(--color-error\)/',
			$colors,
		);
		$a11y = (string)file_get_contents($this->root . '/css/common/accessibility.css');
		$this->assertStringContainsString('@media (prefers-contrast: more)', $a11y);
		$this->assertStringNotContainsString('@media (prefers-contrast: high)', $a11y);
		$this->assertStringContainsString('body[data-theme-light-highcontrast]', $a11y);
		$this->assertStringContainsString('body[data-theme-dark-highcontrast]', $a11y);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-btn', $a11y);
		$this->assertStringContainsString('dialog.crm-native-dialog :focus-visible', $a11y);
		$base = (string)file_get_contents($this->root . '/css/common/base.css');
		$this->assertStringContainsString('@media (prefers-contrast: more)', $base);
		$this->assertStringNotContainsString('@media (prefers-contrast: high)', $base);
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('minmax(min(160px, 100%), 1fr)', $app);
		$this->assertStringContainsString('.crm-form--row', $app);
		$responsive = (string)file_get_contents($this->root . '/css/common/responsive.css');
		$this->assertStringContainsString('max-width: 100% !important', $responsive);
		$this->assertStringNotContainsString('max-width: 100vw !important', $responsive);
	}

	public function testDangerButtonsNeverUsePaleColorErrorFill(): void
	{
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$components = (string)file_get_contents($this->root . '/css/common/components.css');
		$this->assertStringContainsString('var(--crm-danger-fill)', $app);
		$this->assertStringContainsString('var(--crm-danger-on-fill)', $app);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-btn--danger[^{]*\{[^}]*background-color:\s*var\(--color-error/s',
			$app,
		);
		// Legacy .btn--danger / .btn-danger chrome is gone — danger lives on .crm-btn--danger only.
		$this->assertStringNotContainsString('.btn--danger', $components);
		$this->assertStringNotContainsString('.btn-danger', $components);
	}

	public function testAgreementFormUsesTokenSpacingNotInlineStyles(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/agreement-form.php');
		$this->assertStringNotContainsString('style="', $html);
		$this->assertStringContainsString('crm-ag-terminal-actions', $html);
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('crm-ag-terminal-actions', $css);
		$this->assertStringContainsString('var(--crm-space-3)', $css);
		$this->assertStringContainsString('var(--crm-touch', $css);
	}

	public function testPageChromeAndFilterPanelPatternsExist(): void
	{
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		$this->assertStringContainsString('crm-skip-link', $start);
		$this->assertStringContainsString('crm-main-content', $start);
		$this->assertStringContainsString('crm-live-region', $start);
		$money = (string)file_get_contents($this->root . '/templates/money.php');
		$this->assertStringContainsString('crm-filter-panel__head', $money);
		$this->assertStringContainsString('crm-filter-panel__body', $money);
		$this->assertStringContainsString('crm-filter-panel__form', $money);
	}

	public function testSettingsMultipageStandardIsImplemented(): void
	{
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		$this->assertStringContainsString("/settings/{section}", $routes);
		$this->assertStringContainsString("/app-settings/{section}", $routes);
		$settings = (string)file_get_contents($this->root . '/templates/settings.php');
		$this->assertStringContainsString('parts/settings-nav.php', $settings);
		$this->assertStringContainsString('$crmSettingsSectionFiles', $settings);
		$this->assertStringContainsString("if (!isset(\$crmSettingsSectionFiles[\$crmRequestedSection]))", $settings);
		$nav = (string)file_get_contents($this->root . '/templates/parts/settings-nav.php');
		$this->assertStringContainsString('crm-settings-nav', $nav);
		$appSettings = (string)file_get_contents($this->root . '/templates/app-settings.php');
		$this->assertStringContainsString('$crmAppSettingsSectionFiles', $appSettings);
		$this->assertStringContainsString('parts/app-settings-nav.php', $appSettings);
		$appNav = (string)file_get_contents($this->root . '/templates/parts/app-settings-nav.php');
		$this->assertStringContainsString('crm-settings-nav', $appNav);
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.crm-settings-nav', $css);
		$this->assertStringContainsString('var(--crm-touch', $css);
	}

	public function testPrimaryTemplatesAvoidInlineStyles(): void
	{
		foreach ([
			'templates/agreement-form.php',
			'templates/pipeline.php',
			'templates/company-form.php',
			'templates/company-detail.php',
			'templates/settings.php',
			'templates/app-settings.php',
			'templates/settings/workspace-mailbox.php',
			'templates/settings/workspace-review-sources.php',
			'templates/settings/license.php',
		] as $rel) {
			$html = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringNotContainsString('style="', $html, $rel . ' still has inline style=');
		}
	}

	public function testConfirmDialogsUseNativeDialogAndCrmButtons(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/components.js');
		$this->assertStringContainsString("createElement('dialog')", $js);
		$this->assertStringContainsString('crm-native-dialog', $js);
		$this->assertStringContainsString('showModal', $js);
		$this->assertStringContainsString(
			"const dialog = document.createElement('dialog');\n      dialog.className = 'crm-native-dialog modal modal--sm confirm-dialog crm-dialog';",
			$js,
		);
		$this->assertStringContainsString('crm-btn crm-btn--primary confirm-dialog__confirm', $js);
		$this->assertStringContainsString("crm-btn crm-btn--\${isDestructive ? 'danger' : 'primary'}", $js);
		$this->assertStringContainsString("dialog.setAttribute('aria-modal', 'true')", $js);
		$this->assertStringContainsString('_closeOtherContentDialogs', $js);
		$this->assertStringNotContainsString('class="btn btn--primary confirm-dialog__confirm"', $js);
		$this->assertStringNotContainsString('class="btn btn--danger', $js);
		$css = (string)file_get_contents($this->root . '/css/common/accessibility.css');
		$this->assertStringContainsString('dialog.crm-native-dialog', $css);
		$this->assertStringContainsString('::backdrop', $css);
	}

	public function testCreateModalUsesNativeDialogElement(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/components.js');
		$this->assertMatchesRegularExpression(
			"/createModal\\(options\\s*=\\s*\\{\\}\\)\\s*\\{[\\s\\S]*?createElement\\('dialog'\\)/",
			$js,
		);
		$this->assertStringContainsString("modal.className = `crm-native-dialog modal modal--\${size} crm-dialog crm-dialog--content`", $js);
		$this->assertStringContainsString('dataset.azcClosed', $js);
		$this->assertStringContainsString("settings/review-sources", (string)file_get_contents($this->root . '/js/pages/persona.js'));
		$this->assertStringNotContainsString('placeholder="DE"', (string)file_get_contents($this->root . '/js/pages/persona.js'));
		$componentsCss = (string)file_get_contents($this->root . '/css/common/components.css');
		$this->assertMatchesRegularExpression(
			'/dialog\.crm-native-dialog\.modal:not\(\[open\]\)[\s\S]{0,200}?display:\s*none/s',
			$componentsCss,
		);
	}

	public function testLegacyBtnChromeRemovedAndCrmDangerIsSolid(): void
	{
		$components = (string)file_get_contents($this->root . '/css/common/components.css');
		$this->assertStringContainsString('only `.crm-btn`', $components);
		$this->assertDoesNotMatchRegularExpression('/^\.btn\s*\{/m', $components);
		$this->assertStringNotContainsString('.btn-danger {', $components);
		$this->assertStringNotContainsString('.button.error', $components);
		$this->assertStringContainsString(
			'.crm-card__body > .table-container',
			$components,
		);
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.crm-btn--danger[^{]*\{[^}]*background-color:\s*var\(--crm-danger-fill\)/s',
			$app,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-btn--danger[^{]*\{[^}]*background-color:\s*var\(--color-error\)/s',
			$app,
		);
		$this->assertStringNotContainsString('.crm-app .btn', $app);
		foreach (['js/common/components.js', 'js/pages/pipeline.js', 'templates/pipeline.php'] as $rel) {
			$src = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringNotContainsString(
				'class="btn',
				$src,
				$rel . ' must not use legacy btn class',
			);
		}
	}

	public function testNativeDialogHostsCrmChrome(): void
	{
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-btn,', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-btn--primary,', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-btn--danger,', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-choice,', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-advanced {', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-form-inline-error[hidden]', $app);
		$this->assertStringContainsString(
			'color-mix(in srgb, var(--color-success) 8%, var(--color-main-background))',
			$app,
		);
		$this->assertStringNotContainsString(
			'color-mix(in srgb, var(--color-success) 8%, transparent)',
			$app,
		);
		$notify = (string)file_get_contents($this->root . '/css/common/notification-surfaces.css');
		$this->assertStringContainsString(
			'dialog.crm-native-dialog .crm-callout--info:not(.crm-semantic-panel)',
			$notify,
		);
		$picker = (string)file_get_contents($this->root . '/css/common/user-picker.css');
		$this->assertStringContainsString('dialog.crm-native-dialog .user-picker', $picker);
		$this->assertStringContainsString('dialog.crm-native-dialog .user-picker__clear', $picker);
		$persona = (string)file_get_contents($this->root . '/js/pages/persona.js');
		$this->assertStringContainsString("settings/mailbox", $persona);
		$this->assertStringNotContainsString(
			"generateUrl('/apps/customercheck/settings') + '?workspaceId='",
			$persona,
		);
		$components = (string)file_get_contents($this->root . '/js/common/components.js');
		$this->assertStringContainsString('expected a native <dialog>', $components);
		$this->assertStringNotContainsString("backdrop.className = 'modal-backdrop crm-modal-backdrop'", $components);
		$this->assertStringContainsString('label class="crm-choice confirm-dialog__checkbox-row"', $components);
		$this->assertStringNotContainsString('form-checkbox confirm-dialog__checkbox-row', $components);
		$companyDetail = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString('crm-advanced__summary', $companyDetail);
		$this->assertStringContainsString('label class="crm-choice"', $companyDetail);
		$pipeline = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$this->assertStringContainsString('crm-advanced__summary', $pipeline);
		$support = (string)file_get_contents($this->root . '/css/admin-support-us.css');
		$this->assertStringContainsString(
			'color-mix(in srgb, var(--color-primary-element) 14%, var(--color-main-background))',
			$support,
		);
		$this->assertStringNotContainsString(
			'color-mix(in srgb, var(--color-primary-element) 14%, transparent)',
			$support,
		);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-muted', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-list', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-pre', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-simple-list', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-dl', $app);
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-card__title', $app);
		$this->assertStringContainsString('.crm-empty-state__text', $app);
		$mailbox = (string)file_get_contents($this->root . '/templates/settings/workspace-mailbox.php');
		$this->assertDoesNotMatchRegularExpression(
			'/<summary(?![^>]*class="[^"]*(?:crm-advanced__summary|crm-mailbox-compose__summary))[^>]*>/',
			$mailbox,
		);
		$team = (string)file_get_contents($this->root . '/templates/team.php');
		$this->assertStringContainsString('crm-card--collapsible', $team);
		$this->assertStringContainsString('crm-card__header--summary', $team);
		$this->assertStringNotContainsString('crm-advanced', $team);
	}

	public function testFeatureCssBraceBalanceAndNoOrphanProperties(): void
	{
		$cssDir = $this->root . '/css';
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($cssDir, \FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			if (!$file->isFile() || !str_ends_with($file->getFilename(), '.css')) {
				continue;
			}
			$rel = substr($file->getPathname(), strlen($this->root) + 1);
			$css = (string)file_get_contents($file->getPathname());
			$open = substr_count($css, '{');
			$close = substr_count($css, '}');
			$this->assertSame(
				$open,
				$close,
				$rel . " brace mismatch ({$open} open / {$close} close)",
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\}\s*\n\s*(width|height|margin|padding|display|color|background)\s*:/m',
				$css,
				$rel . ' has orphan properties after a closed rule (icons.css footgun)',
			);
		}
	}
}
