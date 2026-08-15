<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Aristoteles P0 hardening — personal IMAP never mutated from Review/team admin;
 * reply/preview/triage ACL; team sync scoped to kind=team.
 */
final class PersonalMailHardeningContractTest extends TestCase
{
	public function testDeleteIndexedMessageRefusesPersonalMailbox(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('assertRemoteMutateAllowed', $src);
		$this->assertStringContainsString('Personal mailbox messages cannot be deleted or junked from Assign', $src);
		$this->assertStringContainsString('Only the mailbox owner can delete or mark spam on personal mail', $src);
		$this->assertMatchesRegularExpression(
			'/function deleteIndexedMessage[\s\S]{0,1200}assertRemoteMutateAllowed/',
			$src,
		);
		$this->assertMatchesRegularExpression(
			'/function moveIndexedMessageToJunk[\s\S]{0,1200}assertRemoteMutateAllowed/',
			$src,
		);
		$this->assertStringContainsString('ImapConnection::isLiveUid', $src);
		$this->assertStringContainsString('$mailboxId <= 0 || !ImapConnection::isLiveUid($uidRaw)', $src);
		// Argus MF-01: ownership before CRM-only return.
		$this->assertMatchesRegularExpression(
			'/assertRemoteMutateAllowed[\s\S]{0,1200}mailboxId <= 0 \|\| !ImapConnection::isLiveUid/',
			$src,
		);
		$this->assertMatchesRegularExpression(
			'/assertRemoteMutateAllowed[\s\S]{0,800}assertMailMutateRateAllowed[\s\S]{0,400}mailboxId <= 0 \|\| !ImapConnection::isLiveUid/',
			$src,
		);
		// Argus MF-02: blank-password test refuses personal.
		$this->assertStringContainsString('KIND_PERSONAL', $src);
		$this->assertStringContainsString('Leaving the password blank only reuses credentials for team mailboxes', $src);
		// Argus SF-02 / SF-03: mutate rate limit + denial audit events.
		$this->assertStringContainsString('assertMailMutateRateAllowed', $src);
		$this->assertStringContainsString('MAIL_MUTATE_USER_LIMIT', $src);
		$this->assertStringContainsString('personal_mail.mutate_denied', $src);
		$this->assertStringContainsString('mail.mutate_rate_limited', $src);
		$this->assertStringContainsString('personal_mail.test_blank_password_refused', $src);
		$this->assertStringContainsString('function deleteIndexedMessageFromInbox', $src);
		$this->assertStringContainsString('function markIndexedMessageSpamFromInbox', $src);
		$this->assertStringContainsString('function finishInboxRemoteMutate', $src);
		$this->assertStringContainsString('Best-effort compensate', $src);
		$this->assertStringContainsString('MAILBOX_SYNC_SLICE_SECONDS', (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Constants.php'));
		$this->assertStringContainsString('outbound_index_deferred', (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailSendService.php'));
		$this->assertStringContainsString('purgeExpiredKeys', (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RateLimitService.php'));
		$this->assertStringContainsString('mail_msg_id', (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php'));
		$this->assertStringNotContainsString("str_starts_with(\$uidRaw, 'outbound:')", $src);
	}

	public function testTeamMailboxDeleteRefusesPersonalWithoutAllowFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('function delete(int $workspaceId, int $mailboxId, bool $allowPersonal = false)', $src);
		$this->assertStringContainsString('Personal mailboxes can only be disconnected by their owner under My email.', $src);
		$this->assertStringContainsString("KIND_PERSONAL && !\$allowPersonal", $src);
		$personal = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('deleteMailbox($workspaceId, $mailboxId, true)', $personal);
	}

	public function testReplyComposeEnforcesMailAcl(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertMatchesRegularExpression(
			'/function replyComposeIndexedMessage\([\s\S]*?MailAclLogic::canReadMessage/',
			$src,
		);
		$ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('replyComposeIndexedMessage(', $ctrl);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'write')", $ctrl);
		$this->assertStringNotContainsString(
			'replyComposeIndexedMessage($workspaceId, $id)',
			$ctrl,
		);
	}

	public function testPreviewPassesWriteAsTriageFlagNotHardcodedTrue(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonaApiController.php');
		$mobile = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileMailController.php');
		$this->assertStringNotContainsString(
			"previewIndexedMessage(\n\t\t\t\t\$workspaceId,\n\t\t\t\t\$id,\n\t\t\t\t\$uid,\n\t\t\t\t\$canReadCompany,\n\t\t\t\ttrue,",
			$ctrl,
		);
		$this->assertStringContainsString('$canTriage', $ctrl);
		$this->assertStringContainsString('previewIndexedMessage(', $ctrl);
		$this->assertStringContainsString('$canTriage', $mobile);
		$this->assertStringContainsString('PERSONAL_MAIL_SYNC_USER_LIMIT', $mobile);
	}

	public function testTriageAssignRefusesPrivateVisibility(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$pos = strpos($src, 'function triageAssign');
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos, 2500);
		$this->assertStringContainsString('VISIBILITY_SHARED', $chunk);
		$this->assertStringContainsString("visibility', \$qb->createNamedParameter('')", $chunk);
	}

	public function testSyncWorkspaceIsTeamOnlyAndPeerSyncRejectsPersonal(): void
	{
		$sync = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		// Workspace sync may include personal mailboxes when personalMailEnabled;
		// disabled workspaces must skip (not dial) personal secrets.
		$this->assertStringContainsString('$kind === MailAclLogic::KIND_PERSONAL', $sync);
		$this->assertStringContainsString('personal_mail.sync_skip', $sync);
		$this->assertStringContainsString("'reason' => 'disabled'", $sync);
		$ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('resolveMailboxKind($workspaceId, $id)', $ctrl);
		$this->assertStringContainsString('Sync personal mail from My email or Inbox', $ctrl);
	}

	public function testReviewDiscardSkipsImapForPersonalSharedUnmatched(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString("sourceKind === 'personal_mail_shared_unmatched'", $src);
		$this->assertMatchesRegularExpression(
			'/function discard[\s\S]{0,900}personal_mail_shared_unmatched[\s\S]{0,200}\$deletedRemote = false/',
			$src,
		);
		$this->assertMatchesRegularExpression(
			'/function markSpam[\s\S]{0,600}personal_mail_shared_unmatched/',
			$src,
		);
	}

	public function testSalesInboxPreviewGenerationGuard(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('previewGeneration', $js);
		$this->assertStringContainsString('if (gen !== previewGeneration || selectedId !== id) return', $js);
		$this->assertStringContainsString('mailboxId: composeItem && composeItem.mailboxId', $js);
		$this->assertStringContainsString('if (ok) autoSyncedOnce = true', $js);
	}
}
