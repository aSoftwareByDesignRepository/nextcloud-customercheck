<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Product §4.8 / §14 — Private-to-you badge + shared disclosure always present in code.
 */
final class MailPrivacyDisclosureContractTest extends TestCase
{
	public function testPrivateToYouWiredInCommandServiceAndUi(): void
	{
		$cmd = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyCommandService.php');
		$this->assertStringContainsString("l10n->t('Private to you')", $cmd);
		$this->assertStringContainsString("'private' => true", $cmd);
		$this->assertStringContainsString("'privacyBadge'", $cmd);
		$this->assertStringContainsString('disclosure', $cmd);

		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("t('Private to you')", $js);
		$this->assertStringContainsString('privacyBadge', $js);
		$this->assertStringContainsString('feedDisclosure', $js);

		$triage = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/mail-triage.php');
		$this->assertStringContainsString('role="status"', $triage);
		$this->assertStringContainsString('shared mailbox', $triage);
	}

	public function testMailboxDisclosureTemplateUsesBothLabels(): void
	{
		$mbox = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString(
			'These messages come from the shared mailbox %1$s. Everyone in workspace %2$s who can read mail can see them.',
			$mbox,
		);
	}
}
