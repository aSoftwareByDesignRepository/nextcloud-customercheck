<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailAclLogic;
use PHPUnit\Framework\TestCase;

final class MailAclLogicTest extends TestCase
{
	public function testPrivateOnlyOwner(): void
	{
		$msg = [
			'visibility' => MailAclLogic::VISIBILITY_PRIVATE,
			'ownerUserId' => 'alex',
			'companyId' => 26,
		];
		$this->assertTrue(MailAclLogic::canReadMessage('alex', $msg, true, true));
		$this->assertFalse(MailAclLogic::canReadMessage('teammate', $msg, true, true));
		$this->assertFalse(MailAclLogic::canReadMessage('teammate', $msg, false, false));
	}

	public function testSharedLinkedRequiresCompanyRead(): void
	{
		$msg = [
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'alex',
			'companyId' => 26,
		];
		$this->assertTrue(MailAclLogic::canReadMessage('teammate', $msg, true, false));
		$this->assertFalse(MailAclLogic::canReadMessage('teammate', $msg, false, true));
		$this->assertTrue(MailAclLogic::canReadMessage('alex', $msg, false, false), 'owner always');
	}

	public function testSharedUnmatchedRequiresMailRights(): void
	{
		// Legacy callers without mailboxKind default to team → mail.read is enough.
		$msg = [
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'alex',
			'companyId' => null,
		];
		$this->assertTrue(MailAclLogic::canReadMessage('manager', $msg, false, true));
		$this->assertTrue(MailAclLogic::canReadMessage('viewer', $msg, false, false));
		$this->assertTrue(MailAclLogic::canReadMessage('alex', $msg, false, false));
	}

	public function testSharedUnmatchedTeamAllowsMailReadCaller(): void
	{
		$msg = [
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'alex',
			'companyId' => null,
			'mailboxKind' => MailAclLogic::KIND_TEAM,
		];
		// Team unmatched: controller already required mail.read — ACL allows viewers.
		$this->assertTrue(MailAclLogic::canReadMessage('viewer', $msg, false, false));
		$this->assertTrue(MailAclLogic::canReadMessage('manager', $msg, false, true));
		$this->assertTrue(MailAclLogic::canReadMessage('alex', $msg, false, false));
	}

	public function testSharedUnmatchedPersonalRequiresTriage(): void
	{
		$msg = [
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'alex',
			'companyId' => null,
			'mailboxKind' => MailAclLogic::KIND_PERSONAL,
		];
		// AS-12: personal unmatched bodies need write/triage, not bare mail.read.
		$this->assertTrue(MailAclLogic::canReadMessage('manager', $msg, false, true));
		$this->assertFalse(MailAclLogic::canReadMessage('viewer', $msg, false, false));
		$this->assertTrue(MailAclLogic::canReadMessage('alex', $msg, false, false), 'owner always');
	}

	public function testEmptyActorDenied(): void
	{
		$msg = [
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'alex',
			'companyId' => 1,
		];
		$this->assertFalse(MailAclLogic::canReadMessage('', $msg, true, true));
		$this->assertFalse(MailAclLogic::canReadMessage('  ', $msg, true, true));
	}

	public function testVisibilityForIngest(): void
	{
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, MailAclLogic::visibilityForIngest('team', false));
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, MailAclLogic::visibilityForIngest('personal', true));
		$this->assertSame(MailAclLogic::VISIBILITY_PRIVATE, MailAclLogic::visibilityForIngest('personal', false));
	}

	public function testNormalizeVisibility(): void
	{
		$this->assertSame(MailAclLogic::VISIBILITY_PRIVATE, MailAclLogic::normalizeVisibility('PRIVATE'));
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, MailAclLogic::normalizeVisibility('anything'));
	}

	public function testContractMigrationPersonalMailColumns(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Migration/Version1009Date20260815093000.php');
		$this->assertStringContainsString('kind', $src);
		$this->assertStringContainsString('share_with_workspace', $src);
		$this->assertStringContainsString('visibility', $src);
		$this->assertStringContainsString('body_text', $src);
		$this->assertStringContainsString('personal_mail_enabled', $src);
		$this->assertStringContainsString('forward_from_hints', $src);
	}

	public function testActorMayAccessFailedDraftFailClosed(): void
	{
		$this->assertTrue(MailAclLogic::actorMayAccessFailedDraft('ae', 'team', 'x', null));
		$this->assertTrue(MailAclLogic::actorMayAccessFailedDraft('ae', 'personal', 'ae', 'ae'));
		$this->assertTrue(MailAclLogic::actorMayAccessFailedDraft('ae', 'personal', 'other', 'ae'));
		$this->assertFalse(MailAclLogic::actorMayAccessFailedDraft('peer', 'personal', 'ae', 'ae'));
		// Orphan / empty kind: creator only (not world-readable as team).
		$this->assertTrue(MailAclLogic::actorMayAccessFailedDraft('ae', '', 'ae', null));
		$this->assertFalse(MailAclLogic::actorMayAccessFailedDraft('peer', '', 'ae', null));
		$this->assertFalse(MailAclLogic::actorMayAccessFailedDraft('peer', null, 'ae', null));
	}

	public function testRedactPersonalUnmatchedListItem(): void
	{
		$team = MailAclLogic::redactPersonalUnmatchedListItem([
			'mailboxKind' => 'team',
			'subject' => 'Keep',
			'from' => 'a@b.c',
			'snippet' => 'snip',
		], 'Needs triage');
		$this->assertSame('Keep', $team['subject']);

		$personal = MailAclLogic::redactPersonalUnmatchedListItem([
			'mailboxKind' => 'personal',
			'subject' => 'Secret deal',
			'from' => 'ceo@example.com',
			'snippet' => 'body leak',
			'preview' => 'full',
			'mailboxEmail' => 'alex@example.com',
			'mailboxLabel' => 'Alex',
			'forwardFromHints' => ['x@y.z'],
		], 'Needs triage');
		$this->assertSame('Needs triage', $personal['subject']);
		$this->assertSame('', $personal['from']);
		$this->assertSame('', $personal['snippet']);
		$this->assertSame('', $personal['preview']);
		$this->assertSame([], $personal['forwardFromHints']);
		$this->assertSame('', $personal['mailboxEmail']);
		$this->assertTrue($personal['redacted']);
	}
}
