<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Add / Change person must be a native modal (not an inline identity form).
 *
 * Security: persist + body host so Contacts confirm cannot tear the form down
 * and page `inert` cannot trap it inside a hidden People tab.
 */
final class PersonDialogUxContractTest extends TestCase
{
	private function detail(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
	}

	private function pageJs(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
	}

	private function coreJs(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/person-dialog-core.js');
	}

	public function testMarkupIsANativePersistDialogInsidePeople(): void
	{
		$detail = $this->detail();
		$this->assertStringContainsString('id="crm-person-dialog"', $detail);
		$this->assertSame(1, substr_count($detail, 'id="crm-person-dialog"'));
		$this->assertStringContainsString('<dialog id="crm-person-dialog"', $detail);
		$this->assertStringContainsString('crm-native-dialog', $detail);
		$this->assertStringContainsString('crm-dialog--content', $detail);
		$this->assertStringContainsString('crm-person-dialog', $detail);
		$this->assertStringContainsString('aria-modal="true"', $detail);
		$this->assertStringContainsString('aria-labelledby="crm-person-form-title"', $detail);
		$this->assertStringContainsString('data-azc-modal-persist="true"', $detail);
		$this->assertStringContainsString('data-azc-focus-trap-standalone="1"', $detail);
		$this->assertStringContainsString('id="crm-person-dialog-close"', $detail);
		$this->assertStringContainsString("\$l->t('Close')", $detail);
		$this->assertStringContainsString('aria-haspopup="dialog"', $detail);
		$this->assertStringContainsString('aria-controls="crm-person-dialog"', $detail);
		$this->assertStringNotContainsString('aria-controls="crm-person-form"', $detail);
		$this->assertStringContainsString('id="crm-person-form"', $detail);
		$this->assertStringContainsString('class="crm-form crm-form--stack"', $detail);
		$this->assertDoesNotMatchRegularExpression(
			'/<form id="crm-person-form"[^>]*\shidden/',
			$detail,
			'Visibility is the dialog open state — the form must not stay [hidden] inside an open dialog',
		);
		$personsPos = strpos($detail, 'id="crm-persons"');
		$dialogPos = strpos($detail, 'id="crm-person-dialog"');
		$formPos = strpos($detail, 'id="crm-person-form"');
		$dealsPos = strpos($detail, 'id="crm-360-panel-deals"');
		$this->assertNotFalse($personsPos);
		$this->assertNotFalse($dialogPos);
		$this->assertNotFalse($formPos);
		$this->assertNotFalse($dealsPos);
		$this->assertGreaterThan($personsPos, $dialogPos);
		$this->assertGreaterThan($dialogPos, $formPos);
		$this->assertLessThan($dealsPos, $formPos);
		$this->assertGreaterThan(
			(int)strpos($detail, '<dialog id="crm-person-dialog"'),
			(int)strpos($detail, 'id="crm-person-form-title"'),
			'Title lives inside the dialog, after the dialog opens',
		);
		$this->assertLessThan(
			(int)strpos($detail, 'id="crm-person-name"'),
			(int)strpos($detail, 'id="crm-person-form-title"'),
			'Title belongs in the dialog header, not as a second heading in the form',
		);
	}

	public function testPageScriptOpensNativeModalAndBlocksInlineToggle(): void
	{
		$js = $this->pageJs();
		$this->assertStringContainsString('CustomerCheckPersonDialogCore', $js);
		$this->assertStringContainsString('hostPersonDialog', $js);
		$this->assertStringContainsString('openPersonModal', $js);
		$this->assertStringContainsString('closePersonModal', $js);
		$this->assertStringContainsString('C.openModal(dlg)', $js);
		$this->assertStringContainsString('C.closeModal(dlg)', $js);
		$this->assertStringContainsString('hostDialogOnBody', $js);
		$this->assertStringContainsString('document.body', $js);
		$this->assertStringContainsString('azcModalPersist', $js);
		$this->assertStringContainsString('shouldBlockDialogDismiss', $js);
		$this->assertStringContainsString('shouldSuggestContacts', $js);
		$this->assertStringContainsString('validatePersonFields', $js);
		$this->assertStringContainsString('buildCreatePayload', $js);
		$this->assertStringContainsString('buildUpdatePayload', $js);
		$this->assertStringContainsString('payload.contactsUid', $js);
		$this->assertStringNotContainsString("setFormOpen(document.getElementById('crm-person-form')", $js);
		$this->assertStringNotContainsString("promptFields(t('Add person')", $js);
		$this->assertStringContainsString("} else if (which === 'person') {\n      revealPeopleFocusTab();", $js);
		$this->assertStringContainsString("if (isPersonDialogOpen()) return;", $js);
		$this->assertStringContainsString("['crm-company-name-form', 'crm-address-form']", $js);
		$this->assertStringNotContainsString("'crm-person-form']", $js);
	}

	public function testCoreEncodesSaveAndHostInvariants(): void
	{
		$core = $this->coreJs();
		$this->assertStringContainsString('function validatePersonFields', $core);
		$this->assertStringContainsString("if (name === '')", $core);
		$this->assertStringContainsString('EMAIL_RE.test(email)', $core);
		$this->assertStringContainsString('PHONE_RE.test(phone)', $core);
		$this->assertStringContainsString('function hostDialogOnBody', $core);
		$this->assertStringContainsString('dialog.parentElement === body', $core);
		$this->assertStringContainsString("azcModalPersist = 'true'", $core);
		$this->assertStringContainsString("mode === 'add' && saveBusy !== true && dialogOpen === true", $core);
		$this->assertStringContainsString('return saveBusy === true', $core);
		$this->assertStringContainsString('payload.contactsUid = uid', $core);
		$this->assertStringContainsString("if (uid !== '')", $core);
		$this->assertStringContainsString('FOCUS_AFTER_OPEN_MS = 80', $core);
		$this->assertStringContainsString('CustomerCheckPersonDialogCore', $core);
	}

	public function testAssetsLoadCoreBeforeCompanyDetail(): void
	{
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/person-dialog-core'", $controller);
		$posCore = strpos($controller, "'common/person-dialog-core'");
		$posPage = strpos($controller, "'pages/company-detail'");
		$this->assertNotFalse($posCore);
		$this->assertNotFalse($posPage);
		$register = strpos($controller, "registerFrontEndAssets('pages/company-detail'");
		$this->assertNotFalse($register);
		$this->assertGreaterThan($register, $posCore, 'person-dialog-core must be an extraScript of company-detail');
	}

	public function testDialogFormCssSurvivesBodyHost(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/dialog-forms.css');
		$this->assertMatchesRegularExpression(
			'/dialog\.crm-native-dialog\.crm-dialog--content \.crm-input--lg\s*,/',
			$css,
			'Large inputs must stay styled after the person dialog is hosted on body',
		);
		$this->assertStringContainsString('dialog.crm-person-dialog .crm-form--stack .crm-form-actions .crm-btn', $css);
		$this->assertStringContainsString('min-height: 48px', $css);
		$this->assertStringContainsString('dialog.crm-native-dialog.crm-dialog--content .form-group:focus-within', $css);
		$this->assertStringContainsString('@media (max-width: 640px)', $css);
		$this->assertStringContainsString('prefers-reduced-motion', $css);
	}
}
