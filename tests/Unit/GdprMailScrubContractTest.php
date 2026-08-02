<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GDPR erase must scrub shared-mail PII, not only detach company_id (§18.9).
 */
final class GdprMailScrubContractTest extends TestCase
{
	public function testEraseCancelsConflictsAndScrubsReview(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$erase = $this->extractMethod($src, 'gdprErase');
		$this->assertStringContainsString('eraseReviewAndConflicts', $erase);
		$this->assertStringContainsString('$agreementIds', $erase);
		$method = $this->extractMethod($src, 'eraseReviewAndConflicts');
		$this->assertStringContainsString('cancelOpenConflicts', $method);
		$this->assertStringContainsString("'agreement'", $method);
		$this->assertStringContainsString("update('crm_review_item')", $method);
		$this->assertStringContainsString("set('source_ref'", $method);
		$this->assertStringContainsString('[erased]', $method);
		$this->assertStringContainsString('scrubReviewSuggestionsForCompany', $method);
		$cancel = $this->extractMethod($src, 'cancelOpenConflicts');
		$this->assertStringContainsString("update('crm_edit_conflict')", $cancel);
		$this->assertStringContainsString("'cancelled'", $cancel);
		$this->assertStringContainsString("set('base_json'", $cancel);
		$this->assertStringContainsString("set('current_json'", $cancel);
		$this->assertStringContainsString("set('incoming_json'", $cancel);
	}

	public function testEraseScrubsSharedMailFields(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$method = $this->extractMethod($src, 'erasePersonaLedgers');
		$this->assertStringContainsString("update('crm_mail_msg')", $method);
		$this->assertStringContainsString("set('from_addr'", $method);
		$this->assertStringContainsString("set('to_addrs'", $method);
		$this->assertStringContainsString("set('subject'", $method);
		$this->assertStringContainsString("set('snippet'", $method);
		$this->assertStringContainsString("set('preview'", $method);
		$this->assertStringContainsString('[erased]', $method);
		$this->assertStringContainsString('scrub PII', $method);
	}

	public function testEraseClearsFilesFolderPointers(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$erase = $this->extractMethod($src, 'gdprErase');
		$this->assertStringContainsString('setFilesFolderId(null)', $erase);
		$this->assertStringContainsString('setFilesFolderPath(null)', $erase);
		$this->assertStringContainsString("setSlug('erased-'", $erase);
		$this->assertStringContainsString('setPcCustomerId(null)', $erase);
		$this->assertStringContainsString('setTcCustomerId(null)', $erase);
	}

	public function testEraseScrubsCommitmentAndEscalationText(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$method = $this->extractMethod($src, 'erasePersonaLedgers');
		$this->assertStringContainsString("set('title'", $method);
		$this->assertStringContainsString("set('verify_note'", $method);
		$this->assertStringContainsString("set('evidence'", $method);
		$this->assertStringContainsString("set('reason'", $method);
		$this->assertStringContainsString("set('deal_id'", $method);
		$this->assertStringContainsString("delete('crm_plan_ms')", $method);
	}

	public function testAgreementAnonymiseScrubsLabel(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Db/AgreementMapper.php');
		$method = $this->extractMethod($src, 'anonymiseByCompany');
		$this->assertStringContainsString("set('label'", $method);
		$this->assertStringContainsString('[erased]', $method);
	}

	public function testGdprExportIncludesSharedMailAndDrafts(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$export = $this->extractMethod($src, 'gdprExport');
		$this->assertStringContainsString("'sharedMail'", $export);
		$this->assertStringContainsString("'mailDrafts'", $export);
		$this->assertStringContainsString("'escalations'", $export);
		$this->assertStringContainsString("'commitments'", $export);
		$this->assertStringContainsString('exportSharedMail', $export);
		$this->assertStringContainsString('exportCommitments', $export);
		$serialise = $this->extractMethod($src, 'serialiseCompany');
		$this->assertStringContainsString("'kamTier'", $serialise);
		$this->assertStringContainsString("'accountOwnerUserId'", $serialise);
		$this->assertStringContainsString("'workspaceId'", $serialise);
	}

	private function extractMethod(string $src, string $name): string
	{
		$pos = strpos($src, 'function ' . $name);
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos);
		if (preg_match('/^function\s+' . preg_quote($name, '/') . '\b.*?(?=\n\tpublic function|\n\tprivate function|\n\tprotected function|\n}\n)/s', $chunk, $m) === 1) {
			return $m[0];
		}
		return substr($chunk, 0, 6000);
	}
}
