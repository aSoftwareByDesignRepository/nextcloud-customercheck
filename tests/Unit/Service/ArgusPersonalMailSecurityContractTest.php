<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Argus FULL AUDIT 1.6.104 — Absolute No-Go + Must-Fix regression contracts.
 */
final class ArgusPersonalMailSecurityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testMobileMailControllerVerifiesBasicAuthNotPresenceOnly(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/MobileMailController.php');
		$channel = $this->extractMethod($ctrl, 'assertSafeMutationChannel');
		$this->assertStringContainsString('authorizationBasicAuthenticatesCurrentUser', $channel);
		$this->assertDoesNotMatchRegularExpression(
			'/preg_match\s*\(\s*[\'"]\/\^\(Basic\|Bearer\)/',
			$channel,
			'Must not accept Authorization presence alone as CSRF bypass',
		);
		$basic = $this->extractMethod($ctrl, 'authorizationBasicAuthenticatesCurrentUser');
		$this->assertStringContainsString('checkPassword', $basic);
		$this->assertStringContainsString('hash_equals', $basic);
		$this->assertStringContainsString('IUserManager', $ctrl);
	}

	public function testFailedDraftApisPassActorAndJoinMailbox(): void
	{
		$drafts = (string)file_get_contents($this->root . '/lib/Service/MailDraftService.php');
		$this->assertStringContainsString('function listFailed(int $workspaceId, string $workspaceName, string $actor', $drafts);
		$this->assertStringContainsString('actorMaySeeFailedDraft', $drafts);
		$this->assertStringContainsString('actorMayAccessFailedDraft', $drafts);
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('listFailed(', $ctrl);
		$this->assertMatchesRegularExpression(
			'/listFailed\(\s*\$workspaceId,\s*\(string\)\$ctx\[\'workspace\'\]->getName\(\),\s*\$uid/',
			$ctrl,
		);
		$this->assertStringContainsString('getFailed($workspaceId, $id, $uid)', $ctrl);
		$this->assertStringContainsString('deleteFailed($workspaceId, $id, $uid)', $ctrl);
	}

	public function testUnmatchedMailRedactsPersonalPreviewWithoutTriage(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$chunk = $this->extractMethod($ctrl, 'unmatchedMail');
		$this->assertStringContainsString('$canTriage', $chunk);
		$this->assertStringContainsString('redactPersonalUnmatchedListItem', $chunk);
		$acl = (string)file_get_contents($this->root . '/lib/Service/MailAclLogic.php');
		$this->assertStringContainsString('function redactPersonalUnmatchedListItem', $acl);
		$this->assertStringContainsString("\$item['subject'] = \$needsTriageLabel", $acl);
		$pm = (string)file_get_contents($this->root . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('bool $canTriage = false', $pm);
		$this->assertStringContainsString('redactPersonalUnmatchedListItem', $pm);
	}

	public function testImapConnectIsRateLimited(): void
	{
		$pm = (string)file_get_contents($this->root . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('pm_imap_connect:', $pm);
		$this->assertStringContainsString('PERSONAL_MAIL_IMAP_CONNECT_USER_LIMIT', $pm);
		$constants = (string)file_get_contents($this->root . '/lib/Constants.php');
		$this->assertStringContainsString('PERSONAL_MAIL_IMAP_CONNECT_USER_LIMIT', $constants);
	}

	public function testClaimFailedDraftEnforcesActorAcl(): void
	{
		$send = (string)file_get_contents($this->root . '/lib/Service/MailSendService.php');
		$this->assertMatchesRegularExpression(
			'/function claimFailedDraft[\s\S]{0,2500}actorMayAccessFailedDraft/',
			$send,
		);
	}

	public function testPersonalSendFailsClosedWithoutWorkspaceId(): void
	{
		$send = (string)file_get_contents($this->root . '/lib/Service/MailSendService.php');
		$this->assertMatchesRegularExpression(
			'/function assertMaySendAs[\s\S]{0,1400}workspaceId <= 0/',
			$send,
		);
	}

	public function testOAuthCompleteAuthorizeBindsSessionUser(): void
	{
		$oauth = (string)file_get_contents($this->root . '/lib/Service/PersonalMailOAuthService.php');
		$this->assertStringContainsString(
			'function completeAuthorize(string $state, string $code, string $sessionUserId',
			$oauth,
		);
		$this->assertStringContainsString('hash_equals($userId, $sessionUserId)', $oauth);
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$cb = $this->extractMethod($ctrl, 'personalMailOAuthCallback');
		$this->assertStringContainsString('isNativeOAuthState($state)', $cb);
		$this->assertStringContainsString('completeAuthorize($state, $code, $sessionUid)', $cb);
		$oauth = (string)file_get_contents($this->root . '/lib/Service/PersonalMailOAuthService.php');
		$this->assertStringContainsString('function isNativeOAuthState', $oauth);
	}

	public function testSendRespectsPersonalMailEnabled(): void
	{
		$send = (string)file_get_contents($this->root . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('personalMailEnabled', $send);
		$this->assertStringContainsString('WorkspaceService $workspaces', $send);
		$this->assertMatchesRegularExpression(
			'/function assertMaySendAs[\s\S]{0,1200}personalMailEnabled/',
			$send,
		);
		$this->assertMatchesRegularExpression(
			'/function listSendAccounts[\s\S]{0,900}personalMailEnabled/',
			$send,
		);
	}

	public function testTeamMailboxSaveLowercasesEmail(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/MailboxService.php');
		$pos = strpos($src, 'function save(int $workspaceId');
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos, 2500);
		$this->assertStringContainsString(
			"emailAddress = strtolower(trim((string)(\$data['emailAddress']",
			$chunk,
		);
	}

	private function extractMethod(string $src, string $name): string
	{
		$pos = strpos($src, 'function ' . $name);
		$this->assertNotFalse($pos, $name);
		$next = strpos($src, "\n\tfunction ", $pos + 10);
		if ($next === false) {
			$next = strpos($src, "\n\tprivate function ", $pos + 10);
		}
		if ($next === false) {
			$next = strlen($src);
		}

		return substr($src, (int)$pos, $next - (int)$pos);
	}
}
