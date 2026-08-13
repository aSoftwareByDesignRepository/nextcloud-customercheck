<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Argus FULL AUDIT — Bachus mail Review/Talk/triage security invariants.
 */
final class ArgusBachusMailSecurityContractTest extends TestCase
{
	public function testTriageMailRequiresWriteNotOnlyMailRead(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonaApiController.php');
		$pos = strpos($ctrl, 'function triageMail');
		$this->assertNotFalse($pos);
		$chunk = substr($ctrl, (int)$pos, 900);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'mail.read')", $chunk);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'write')", $chunk);
		// Preview/reply stay mail.read-only (viewers may read).
		$preview = substr($ctrl, (int)strpos($ctrl, 'function previewMail'), 500);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'mail.read')", $preview);
		$this->assertStringNotContainsString("assertCapability(\$uid, \$workspaceId, 'write')", $preview);
	}

	public function testViewerCannotSeeMailTriageAssignForm(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$pos = strpos($js, 'async function loadMail');
		$this->assertNotFalse($pos);
		$chunk = substr($js, (int)$pos, 5500);
		$this->assertStringContainsString("shellCan('write')", $chunk);
		$this->assertStringContainsString('const canWrite = shellCan', $chunk);
		$this->assertStringContainsString('const assignHtml = canWrite', $chunk);
		$this->assertStringContainsString('if (canWrite) {', $chunk);
		// Assign form markup only inside canWrite branch.
		$this->assertMatchesRegularExpression(
			'/const assignHtml = canWrite[\s\S]*crm-triage-assign[\s\S]*: \'\'/',
			$chunk,
		);
	}

	public function testMailTriageAssignSubmitLocksBusy(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$pos = strpos($js, "apiPost('/api/v1/workspaces/' + ws + '/mail/' + id + '/triage'");
		$this->assertNotFalse($pos);
		$chunk = substr($js, max(0, $pos - 800), 1200);
		$this->assertStringContainsString("form.getAttribute('aria-busy') === 'true'", $chunk);
		$this->assertStringContainsString("form.setAttribute('aria-busy', 'true')", $chunk);
		$this->assertStringContainsString("form.removeAttribute('aria-busy')", $chunk);
	}

	public function testViewerCapabilityMatrixDeniesWrite(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/WorkspaceCapability.php');
		$this->assertMatchesRegularExpression(
			"/'viewer'\s*=>\s*\[[^\]]*mail\.read[^\]]*\]/s",
			$src,
		);
		$viewerBlock = [];
		$this->assertSame(1, preg_match(
			"/'viewer'\s*=>\s*\[(.*?)\]/s",
			$src,
			$viewerBlock,
		));
		$this->assertStringNotContainsString("'write'", $viewerBlock[1]);
		$this->assertStringContainsString("'mail.read'", $viewerBlock[1]);
	}

	public function testMailPreviewBodiesStayTextContentOnly(): void
	{
		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$company = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('pre.textContent = bodyText', $persona);
		$this->assertStringContainsString('Never use innerHTML', $persona);
		$this->assertStringContainsString('pre.textContent = textBody', $company);
		$this->assertStringContainsString('Never use innerHTML', $company);
	}

	public function testMailPreviewAbortControllerCancelsPriorFetch(): void
	{
		$api = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/api.js');
		$this->assertStringContainsString('opts.signal = signal', $api);
		$this->assertStringContainsString("hasOwnProperty.call(extra, 'signal')", $api);

		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('function bumpMailPreviewRequest()', $persona);
		$this->assertStringContainsString('mailPreviewAbort.abort()', $persona);
		$this->assertStringContainsString('fetchOpts.signal = opts.signal', $persona);
		$this->assertStringContainsString('isAbortError(e)', $persona);

		$company = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('talkMailPreviewAbort.abort()', $company);
		$this->assertStringContainsString('signal: previewSignal', $company);
		$this->assertStringContainsString('isAbortError(e)', $company);
	}
}
