<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ImapLiveProbe;
use PHPUnit\Framework\TestCase;

/**
 * Pure wire-protocol probe helpers — no network in unit suite.
 */
final class ImapLiveProbeTest extends TestCase
{
	public function testParseListLinesExtractsFolderNames(): void
	{
		$folders = ImapLiveProbe::parseListLines([
			'* LIST (\\HasNoChildren) "/" INBOX',
			'* LIST () "/" "Sent Items"',
			'* LIST (\\Noselect) "/" Archives',
			'A050 OK LIST completed',
			'* LIST () "/" INBOX',
		], 20);
		$this->assertSame(['INBOX', 'Sent Items', 'Archives'], $folders);
	}

	public function testParseRfc822HeadersAndExists(): void
	{
		$parsed = ImapLiveProbe::parseRfc822Headers(
			"From: Ada Lovelace <ada@example.com>\r\n" .
			"Subject: Hello team\r\n" .
			"Date: Wed, 12 Aug 2026 10:00:00 +0000\r\n" .
			"Message-ID: <abc@example.com>\r\n"
		);
		$this->assertSame('ada@example.com', $parsed['from']);
		$this->assertSame('Hello team', $parsed['subject']);
		$this->assertSame('<abc@example.com>', $parsed['messageId']);
		$this->assertNotNull($parsed['sentAt']);
		$this->assertSame('ada@example.com', ImapLiveProbe::extractEmailAddress('Ada <ada@example.com>'));
		$this->assertSame(42, ImapLiveProbe::parseExistsCount(['* 42 EXISTS', 'A OK']));

		$encoded = ImapLiveProbe::parseRfc822Headers(
			"From: praxis@example.com\r\n" .
			"Subject: =?UTF-8?Q?Anfrage_f=C3=BCr_1_w=C3=B6chiges_Praktikum?=\r\n" .
			"Date: Wed, 12 Aug 2026 10:00:00 +0000\r\n"
		);
		$this->assertSame('Anfrage für 1 wöchiges Praktikum', $encoded['subject']);
	}

	public function testParseFetchHeaderResponsesWithLiteral(): void
	{
		$header = "From: bob@acme.test\nSubject: Quote\nDate: Wed, 12 Aug 2026 12:00:00 +0000\n";
		$msgs = ImapLiveProbe::parseFetchHeaderResponses([
			'* 1 FETCH (UID 99 BODY[HEADER.FIELDS (FROM SUBJECT DATE MESSAGE-ID)] {' . strlen($header) . '}',
			$header,
			')',
			'S030 OK FETCH completed',
		]);
		$this->assertCount(1, $msgs);
		$this->assertSame('99', $msgs[0]['uid']);
		$this->assertSame('bob@acme.test', $msgs[0]['from']);
		$this->assertSame('Quote', $msgs[0]['subject']);
	}

	public function testRejectsIncompleteWithoutNetwork(): void
	{
		$result = ImapLiveProbe::probeLogin('', 993, '', '');
		$this->assertFalse($result['ok']);
		$this->assertFalse($result['reachable']);
		$this->assertStringContainsString('required', $result['detail']);
	}

	public function testNormalizesUsernameBeforeConnectAttempt(): void
	{
		// Empty host → fails before socket; proves normalize does not throw on All-Inkl paste.
		$result = ImapLiveProbe::probeLogin(
			'',
			993,
			'info@software-by-design.de (m06367b4)',
			'not-a-real-password',
		);
		$this->assertFalse($result['ok']);
		$this->assertFalse($result['reachable']);
	}

	public function testDeleteUidRejectsIncompleteWithoutNetwork(): void
	{
		$result = ImapLiveProbe::deleteUid('', 993, '', '', 'INBOX', '');
		$this->assertFalse($result['ok']);
		$this->assertFalse($result['alreadyGone']);
		$this->assertStringContainsString('required', $result['detail']);

		$badFolder = ImapLiveProbe::deleteUid('mail.example.com', 993, 'u', 'p', 'INBOX{evil}', '12');
		$this->assertFalse($badFolder['ok']);
		$this->assertSame('protocol', $badFolder['reason']);
	}
}
