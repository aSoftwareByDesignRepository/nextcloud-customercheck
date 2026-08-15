<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Forward filing: Resent-From + gated body From after marker. */
final class MailForwardMatchContractTest extends TestCase
{
	public function testForwardLogicAndMatchWired(): void
	{
		$fwd = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailForwardLogic.php');
		$this->assertStringContainsString('function hintsFromRedirectHeaders', $fwd);
		$this->assertStringContainsString('RFC 5322 Resent-From is the redirector', $fwd);
		$this->assertStringContainsString('hintsFromBodyPrefix', $fwd);
		$this->assertStringContainsString('begin forwarded message', $fwd);
		$this->assertStringContainsString('FORWARD_MARKERS', $fwd);

		$match = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailMatchLogic.php');
		$this->assertStringContainsString('forward_from', $match);
		$this->assertStringContainsString('forward_domain', $match);
		$this->assertStringContainsString('$originalFromHints = []', $match);

		$sync = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('attachOriginalFromHints', $sync);
		$this->assertStringContainsString('peekTextPrefixesForUids', $sync);
		$this->assertStringContainsString('MAIL_FORWARD_BODY_PROBE_CAP', $sync);
		$this->assertStringContainsString('imap_fetchheader', $sync);
		$this->assertStringContainsString('parseRfc822Headers', $sync);
		$this->assertStringNotContainsString('@imap_body', $sync);

		$probe = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/ImapLiveProbe.php');
		$this->assertStringContainsString('RESENT-FROM X-ORIGINAL-FROM', $probe);
		$this->assertStringContainsString('function peekTextPrefixesForUids', $probe);
		preg_match_all(
			'/BODY\.PEEK\[HEADER\.FIELDS \([^)]+\)\]/',
			$probe,
			$headerFetches,
		);
		$this->assertNotEmpty($headerFetches[0], 'expected at least one IMAP header peek fetch');
		foreach ($headerFetches[0] as $fetch) {
			$this->assertStringContainsString('REPLY-TO', $fetch, 'header peek must request Reply-To: ' . $fetch);
			$this->assertStringContainsString('RESENT-FROM', $fetch, 'header peek must request Resent-From: ' . $fetch);
			$this->assertStringContainsString('X-ORIGINAL-FROM', $fetch, 'header peek must request X-Original-From: ' . $fetch);
		}
	}

	public function testGrannyCopyMentionsForward(): void
	{
		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('forward their mail', $persona);
		$this->assertStringContainsString('original sender inside a forward', $persona);

		$settings = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString('forward their mail here', $settings);
	}
}
