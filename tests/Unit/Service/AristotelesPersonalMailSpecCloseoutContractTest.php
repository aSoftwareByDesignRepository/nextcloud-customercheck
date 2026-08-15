<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Spec closeout contracts — owner notify, OAuth complete, sync_fail_rate, FAC-09 companion HTML.
 */
final class AristotelesPersonalMailSpecCloseoutContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testPersonalAuthQuarantineNotifiesOwnerOnly(): void
	{
		$mailbox = (string)file_get_contents($this->root . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('function notifyMailboxAuthQuarantine', $mailbox);
		$this->assertStringContainsString('notifyMailboxAuthQuarantine($mailboxId)', $mailbox);
		$this->assertMatchesRegularExpression(
			'/if \(\$status === \'error\' && \$next === 3\) \{\s+\$this->notifyMailboxAuthQuarantine/',
			$mailbox,
		);
		$this->assertMatchesRegularExpression(
			'/KIND_PERSONAL[\s\S]{0,400}notifyUser/',
			$mailbox,
		);
		$this->assertMatchesRegularExpression(
			'/function notifyMailboxAuthQuarantine[\s\S]{0,1400}notifyWorkspaceUsers/',
			$mailbox,
		);
		// Live-probe / OAuth must not skip counting — notify is centralized on recordAuthFailure.
		$sync = (string)file_get_contents($this->root . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('OAuth resolve already counted', $sync);
		$this->assertStringNotContainsString('function notifyMailboxAuthQuarantine', $sync);
	}

	public function testSyncFailRateAlertEmitted(): void
	{
		$sync = (string)file_get_contents($this->root . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('personal_mail.sync_fail_rate', $sync);
		$this->assertStringContainsString('emitSyncFailRateAlert', $sync);
		$this->assertMatchesRegularExpression(
			'/\$rate <= 0\.10/',
			$sync,
		);
	}

	public function testMobileOAuthCompleteRouteAndWorkspaceBind(): void
	{
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/MobileMailController.php');
		$oauth = (string)file_get_contents($this->root . '/lib/Service/PersonalMailOAuthService.php');
		$persona = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('completePersonalMailOAuth', $routes);
		$this->assertStringContainsString('oauth/complete', $routes);
		$this->assertStringContainsString('function completePersonalMailOAuth', $ctrl);
		$this->assertStringContainsString('callbackUrl', $oauth);
		$this->assertStringContainsString('function isNativeOAuthState', $oauth);
		$this->assertStringContainsString("'client' => \$client", $oauth);
		$this->assertStringContainsString('isNativeOAuthState($state)', $persona);
		$this->assertMatchesRegularExpression(
			'/if \(\$this->personalMailOAuth->isNativeOAuthState\(\$state\)\) \{/',
			$persona,
		);
		$this->assertStringContainsString('DataDisplayResponse', $persona);
		$this->assertMatchesRegularExpression(
			'/expectedWorkspaceId[\s\S]{0,200}does not match this workspace/',
			$oauth,
		);
		$this->assertStringContainsString("'kind' => MailAclLogic::KIND_PERSONAL", $oauth);
		$this->assertStringContainsString("'sharedFolderCount' => 0", $oauth);
	}

	public function testCompanionMarkdownLiteFac09Tags(): void
	{
		// Web rich composer (FAC-09) — companion markdown-lite mirrors these semantics (Jest).
		$web = (string)file_get_contents($this->root . '/js/common/rich-mail-composer.js');
		$this->assertStringContainsString("'bold'", $web);
		$this->assertStringContainsString("'italic'", $web);
		$this->assertStringContainsString('insertUnorderedList', $web);
		$this->assertStringContainsString('createLink', $web);
		$this->assertStringContainsString('blockquote', $web);
	}
}
