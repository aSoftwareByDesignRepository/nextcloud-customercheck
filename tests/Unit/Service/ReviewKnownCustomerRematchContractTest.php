<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailMatchLogic;
use OCA\CustomerCheck\Service\ReviewMatchLogic;
use PHPUnit\Framework\TestCase;

/**
 * Known-customer mail must not linger in Review when a unique person match exists.
 */
final class ReviewKnownCustomerRematchContractTest extends TestCase
{
	public function testSuggestionCatalogNoLongerHardCapsAt500Companies(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('function reconcileKnownSharedMail', $src);
		$this->assertStringContainsString('function suggestionCatalog', $src);
		$catPos = strpos($src, 'function suggestionCatalog');
		$this->assertNotFalse($catPos);
		$chunk = substr($src, $catPos, 1200);
		$this->assertStringContainsString('while ($fetched === $pageSize)', $chunk);
		$this->assertStringContainsString('pageSize = 5000', $chunk);
		$this->assertStringNotContainsString('hard-cap catalog at first page only', $chunk);
		$this->assertDoesNotMatchRegularExpression(
			'/companyMapper->search\(\$filters,\s*500,\s*0\)/',
			$chunk,
			'Suggestion catalog must not hard-cap at 500 companies',
		);
	}

	public function testSyncSharedMailRematchesBeforeEnqueue(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$postPos = strpos($src, 'function postMailboxSync');
		$this->assertNotFalse($postPos);
		$chunk = substr($src, $postPos, 1800);
		$this->assertStringContainsString('reconcileKnownSharedMail', $chunk);
		$this->assertStringContainsString('promoteOpenSharedMail', $chunk);
		$promotePos = strpos($src, 'function promoteOpenSharedMail');
		$this->assertNotFalse($promotePos);
		$promoteChunk = substr($src, $promotePos, 1800);
		$this->assertStringContainsString('unmatchedPage', $promoteChunk);
		$this->assertStringContainsString('REVIEW_SHARED_MAIL_SYNC_MAX', $promoteChunk);
		$this->assertStringContainsString('if ($added >= $maxPromote)', $promoteChunk);
		$this->assertStringNotContainsString('unmatched($workspaceId, 200)', $promoteChunk);
		$constants = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Constants.php');
		$this->assertStringContainsString('public const REVIEW_SHARED_MAIL_SYNC_PAGE = 200;', $constants);
	}

	public function testPersonCreateTriggersRematch(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonApiController.php');
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($src, 'reconcileKnownSharedMail'),
			'create + update must rematch open shared mail after a person email lands',
		);
	}

	public function testSyncNowPromotesReviewAfterMailboxSync(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('function syncAllMailboxes', $src);
		$this->assertStringContainsString('syncWorkspace($workspaceId)', $src);
		$this->assertStringContainsString('postMailboxSync($workspaceId)', $src);
		$this->assertStringContainsString('formatMailboxSyncSummary', $src);
		$repair = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('reconcileArchivedCompanyMail', $repair);
		$this->assertStringContainsString('reconcileMisfiledTeamForwards', $repair);
		$this->assertStringContainsString('backfillForwardHintsFromPreview', $repair);
		$syncSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('function tallyIngest', $syncSrc);
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/mail-sync-now.js');
		$this->assertStringContainsString('syncMessage', $js);
	}

	public function testDisplayNameFromHeadersNormalizeForExactMatch(): void
	{
		$this->assertSame('ada@acme.test', MailMatchLogic::normalizeFromAddress('Ada Lovelace <ada@acme.test>'));
		$this->assertSame('ada@acme.test', ReviewMatchLogic::normalizeEmail('Ada Lovelace <ada@acme.test>'));
		$persons = [['companyId' => 9, 'email' => 'ada@acme.test']];
		$r = MailMatchLogic::match(null, 'Ada Lovelace <ada@acme.test>', $persons);
		$this->assertSame(9, $r['companyId']);
		$this->assertSame('person_email', $r['how']);

		$companies = [[
			'id' => 9,
			'name' => 'Acme',
			'emails' => ['ada@acme.test'],
			'domains' => ['acme.test'],
		]];
		$sug = ReviewMatchLogic::suggest('Ada Lovelace <ada@acme.test>', null, $companies);
		$this->assertSame(100, $sug[0]['score']);
		$this->assertSame('person_email_exact', $sug[0]['reasonCode']);
	}

	public function testEmptyStateExplainsAutoFile(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/persona.js');
		$this->assertStringContainsString(
			'Only mail we cannot place on a customer yet shows here. Known contacts are filed automatically.',
			$js,
		);
		$this->assertStringNotContainsString(
			'When shared mail or other sources need a company, they show up here.',
			$js,
		);
	}

	public function testTriageAssignAcceptsMatchedHow(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString(
			'function triageAssign(int $messageId, int $workspaceId, int $companyId, string $matchedHow = \'manual\')',
			$src,
		);
		$this->assertStringContainsString("\$qb->createNamedParameter(\$matchedHow)", $src);
		$this->assertStringContainsString('function refreshOpenIndexedMessage', $src);
		$this->assertStringContainsString('// Unique (mailbox, folder, uid) — already indexed.', $src);
		$this->assertStringContainsString("->andWhere(\$upd->expr()->eq('triage_status', \$upd->createNamedParameter('open')))", $src);
	}

	public function testReconcileUsesMatchedHowFromMailRow(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString("'matched_how'", $src);
		$this->assertStringContainsString("\$how = trim((string)(\$mail['matched_how'] ?? ''));", $src);
	}

	public function testReconcileUsesForwardHintsFromStoredOrPreview(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('MailForwardLogic::customerEmailForAssignSuggest', $src);
		$this->assertStringContainsString('loadSharedMailRowsById', $src);
		$this->assertStringContainsString('forward_from_hints', $src);
		$forward = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailForwardLogic.php');
		$this->assertStringContainsString('function customerEmailForAssignSuggest', $forward);
	}

	public function testIngestPersistsForwardFromHints(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString("'forward_from_hints'", $src);
		$this->assertStringContainsString('originalFromHints', $src);
	}

	public function testIngestNormalizesAddressesBeforeStore(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('MailMatchLogic::normalizeFromAddress', $src);
		$this->assertStringContainsString('MailMatchLogic::normalizeAddressList', $src);
		$matchLogic = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailMatchLogic.php');
		$this->assertStringContainsString('function normalizeAddressList', $matchLogic);
	}
}
