<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CC-shared-mailbox filing: From for inbound, unique To/CC for outbound copies.
 */
final class MailMatchCcSafetyContractTest extends TestCase
{
	public function testMatchLogicSupportsRecipientFallbackWithoutFanOut(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailMatchLogic.php');
		$this->assertStringContainsString('recipient_email', $src);
		$this->assertStringContainsString('recipient_domain', $src);
		$this->assertStringContainsString('matchRecipients', $src);
		$this->assertStringContainsString('never fan out', $src);
		$this->assertStringContainsString('array $recipientAddresses = []', $src);
	}

	public function testSyncParsesToAndCcIntoIngest(): void
	{
		$probe = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/ImapLiveProbe.php');
		$this->assertStringContainsString('HEADER.FIELDS (FROM TO CC REPLY-TO RESENT-FROM X-ORIGINAL-FROM SUBJECT DATE MESSAGE-ID)', $probe);
		$this->assertStringContainsString('extractEmailAddresses', $probe);
		$this->assertStringContainsString("'to' => \$parsed['to']", $probe);
		$this->assertStringContainsString("'cc' => \$parsed['cc']", $probe);

		$sync = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('imapAddressList', $sync);
		$this->assertStringContainsString("'to' => \$to", $sync);
		$this->assertStringContainsString("'cc' => \$cc", $sync);

		$mbox = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString("isset(\$msg['cc'])", $mbox);
		$this->assertStringContainsString('$recipients', $mbox);
	}

	public function testGrannyCopyExplainsCcSharedMailboxFlow(): void
	{
		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($persona, 'CC the shared mailbox'),
			'Review disclosure must teach the CC filing pattern',
		);
		$this->assertStringContainsString('unique To/CC contact', $persona);
		$this->assertStringContainsString('never onto every recipient', $persona);
		$this->assertStringContainsString('forward their mail', $persona);

		$settings = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString('forward their mail here', $settings);
		$this->assertStringContainsString('never on every recipient', $settings);

		$help = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString('forward to the shared box', $help);
	}
}
