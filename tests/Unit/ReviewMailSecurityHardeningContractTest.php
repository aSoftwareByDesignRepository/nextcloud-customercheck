<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Security hardening for Review discard / reopen / person-remove mail return.
 * Behavioral contracts that mutation testing must kill.
 */
final class ReviewMailSecurityHardeningContractTest extends TestCase
{
	public function testDiscardCompensatesRemoteDeleteAfterCrmConflict(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$start = strpos($svc, 'public function discard(int $workspaceId, int $id, string $actor)');
		$this->assertNotFalse($start);
		$chunk = substr($svc, (int)$start, 4200);
		$this->assertStringContainsString('deleteIndexedMessage', $chunk);
		$this->assertStringContainsString('IMAP before CRM close', $chunk);
		$this->assertStringContainsString('markRemoteDeleted', $chunk);
		$this->assertStringContainsString('IMAP already succeeded', $chunk);
		$this->assertStringContainsString('Best-effort compensate', $chunk);
		$catchPos = strpos($chunk, 'catch (\Throwable $e)');
		$this->assertNotFalse($catchPos);
		$afterCatch = substr($chunk, $catchPos);
		$this->assertStringContainsString('markRemoteDeleted', $afterCatch);
	}

	public function testReopenBlocksRemoteDeletedBeforeMutatingReview(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$start = strpos($svc, 'public function reopen(int $workspaceId, int $id, string $actor)');
		$this->assertNotFalse($start);
		$chunk = substr($svc, (int)$start, 2200);
		$remotePos = strpos($chunk, 'isRemoteDeleted');
		$updatePos = strpos($chunk, "->update('crm_review_item')");
		$this->assertNotFalse($remotePos);
		$this->assertNotFalse($updatePos);
		$this->assertLessThan(
			$updatePos,
			$remotePos,
			'remote_deleted must be checked before reopening the Review row',
		);
		$this->assertStringContainsString('cannot be reopened', $chunk);
		$this->assertStringContainsString('restoreDiscardedMessage', $chunk);
	}

	public function testMarkRemoteDeletedClearsMatchAndSkipsAlreadyPurged(): void
	{
		$mail = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$start = strpos($mail, 'public function markRemoteDeleted');
		$this->assertNotFalse($start);
		$chunk = substr($mail, (int)$start, 900);
		$this->assertStringContainsString("'remote_deleted'", $chunk);
		$this->assertStringContainsString("neq('triage_status'", $chunk);
		$this->assertStringContainsString("set('company_id'", $chunk);
		$this->assertStringContainsString('function isRemoteDeleted', $mail);
		$restore = strpos($mail, 'public function restoreDiscardedMessage');
		$this->assertNotFalse($restore);
		$restoreChunk = substr($mail, (int)$restore, 700);
		$this->assertStringContainsString("'discarded'", $restoreChunk);
		$this->assertStringNotContainsString("'remote_deleted'", $restoreChunk);
	}

	public function testPersonDestroyDeletesBeforeUnmatchingMail(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonApiController.php');
		$start = strpos($ctrl, 'public function destroy(int $id)');
		$this->assertNotFalse($start);
		$chunk = substr($ctrl, (int)$start, 1600);
		$deletePos = strpos($chunk, '$this->persons->delete(');
		$unmatchPos = strpos($chunk, 'unmatchByCompanyAndFrom');
		$this->assertNotFalse($deletePos);
		$this->assertNotFalse($unmatchPos);
		$this->assertLessThan(
			$unmatchPos,
			$deletePos,
			'person delete must run before unmatch so lock conflicts leave mail untouched',
		);
		$this->assertStringContainsString('returnMailToReview', $chunk);
		$this->assertStringContainsString('contactsKept', $chunk);
	}

	public function testListItemsWidensDateFetchAndSortsBySent(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$start = strpos($svc, 'public function listItems(');
		$this->assertNotFalse($start);
		$chunk = substr($svc, (int)$start, 4500);
		$this->assertStringContainsString('$dateOnlyQuery', $chunk);
		$this->assertStringContainsString(
			'$fetchLimit = $dateOnlyQuery ? max($limit, min(500, $limit * 5)) : $limit;',
			$chunk,
		);
		$this->assertStringContainsString('enrichSentAt', $chunk);
		$this->assertStringContainsString('usort', $chunk);
		$this->assertStringContainsString('sentAt', $chunk);
		$this->assertStringContainsString('array_slice', $chunk);
	}
}
