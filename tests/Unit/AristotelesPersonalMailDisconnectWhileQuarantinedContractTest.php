<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Quarantine reconnect must keep Disconnect visible (erase / email-switch under MAX=1).
 */
final class AristotelesPersonalMailDisconnectWhileQuarantinedContractTest extends TestCase
{
	public function testReconnectHidesSyncNotDisconnect(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$this->assertStringContainsString('crm-personal-mail-disconnect', $js);
		$this->assertStringContainsString('if (syncBtn) syncBtn.hidden = !!needsReconnect;', $js);
		$this->assertStringContainsString('if (disconnectBtn) disconnectBtn.hidden = false;', $js);
		$this->assertStringNotContainsString('if (connectedActions) connectedActions.hidden = !!needsReconnect;', $js);
	}
}
