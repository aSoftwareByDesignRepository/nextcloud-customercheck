<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Sync now bar + workspace sync-all API contracts. */
final class MailSyncNowUxContractTest extends TestCase
{
	public function testFrontendMountsSharedMailSyncBar(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/mail-sync-now.js');
		$this->assertStringContainsString('crm-mail-sync-bar', $js);
		$this->assertStringContainsString("parts.push(t('%s shared mailboxes connected.'", $js);
		$this->assertStringContainsString('if (btn.disabled || syncing)', $js);
		$this->assertStringContainsString('syncMessage', $js);
		$this->assertStringContainsString("role', 'status'", $js);
		$this->assertStringContainsString('aria-live', $js);
	}

	public function testSyncAllRequiresWriteAndMailRead(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonaApiController.php');
		$pos = strpos($src, 'function syncAllMailboxes');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 700);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'write')", $chunk);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'mail.read')", $chunk);
		$this->assertStringContainsString('postMailboxSync($workspaceId)', $chunk);
		$this->assertStringContainsString('formatMailboxSyncSummary', $chunk);
	}
}
