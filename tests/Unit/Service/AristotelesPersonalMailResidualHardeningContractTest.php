<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Residual hardening: non-auth IMAP must not quarantine; OAuth network must not bump.
 */
final class AristotelesPersonalMailResidualHardeningContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testPhpImapOpenOnlyQuarantinesOnAuthReason(): void
	{
		$sync = (string)file_get_contents($this->root . '/lib/Service/MailboxSyncService.php');
		$this->assertMatchesRegularExpression(
			'/if \(\$stream === false\) \{\s+\$err = ImapConnection::collectErrors[\s\S]{0,500}'
			. '\$errReason === \'auth\'[\s\S]{0,200}recordAuthFailure/',
			$sync,
		);
		$this->assertStringContainsString("status' => \$errReason === 'network' ? 'network' : 'error'", $sync);
		$this->assertStringContainsString("'auth', 'error', 'no_imap', 'network'", $sync);
	}

	public function testOAuthRefreshNetworkDoesNotBumpAuth(): void
	{
		$auth = (string)file_get_contents($this->root . '/lib/Service/MailboxAuthService.php');
		$this->assertStringContainsString("return ['status' => 'network'];", $auth);
		$this->assertStringContainsString("return ['status' => 'auth'];", $auth);
		$this->assertMatchesRegularExpression(
			'/if \(\$fresh\[\'status\'\] === \'auth\'\) \{\s+\$this->bumpOAuthAuthFailure/',
			$auth,
		);
		// Must not bump on network in the same branch.
		$this->assertDoesNotMatchRegularExpression(
			'/\$fresh\[\'status\'\] === \'auth\' \|\| \$fresh\[\'status\'\] === \'network\'/',
			$auth,
		);
	}

	public function testLiveProbeAuthReturnsQuarantineStatus(): void
	{
		$sync = (string)file_get_contents($this->root . '/lib/Service/MailboxSyncService.php');
		$pos = strpos($sync, 'function doSyncViaLiveProbe');
		$this->assertNotFalse($pos);
		$chunk = substr($sync, (int)$pos, 4000);
		$this->assertStringContainsString("(\$result['reason'] ?? '') === 'auth'", $chunk);
		$this->assertStringContainsString("\$status === 'error' ? 'error' : 'auth'", $chunk);
		$this->assertStringContainsString('recordAuthFailure', $chunk);
	}
}
