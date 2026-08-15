<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ImapConnection;
use OCA\CustomerCheck\Support\ImapLiveProbe;
use PHPUnit\Framework\TestCase;

/**
 * Spam folder discovery — SPECIAL-USE first, never INBOX, never unsafe paths.
 */
final class ImapJunkFolderPickTest extends TestCase
{
	public function testPrefersSpecialUseJunkOverNameHeuristics(): void
	{
		$picked = ImapConnection::pickJunkFolder([
			['name' => 'INBOX', 'flags' => []],
			['name' => 'Spam', 'flags' => []],
			['name' => 'Junk', 'flags' => ['\\HasNoChildren', '\\Junk']],
		]);
		$this->assertSame('Junk', $picked);
	}

	public function testFallsBackToCommonNames(): void
	{
		$this->assertSame('Spam', ImapConnection::pickJunkFolder(['INBOX', 'Sent', 'Spam']));
		$this->assertSame('INBOX.Junk', ImapConnection::pickJunkFolder(['INBOX', 'INBOX.Junk']));
		$this->assertSame('[Gmail]/Spam', ImapConnection::pickJunkFolder(['INBOX', '[Gmail]/Spam']));
	}

	public function testNeverReturnsInboxOrUnsafe(): void
	{
		$this->assertNull(ImapConnection::pickJunkFolder(['INBOX']));
		$this->assertNull(ImapConnection::pickJunkFolder([
			['name' => 'INBOX', 'flags' => ['\\Junk']],
		]));
		$this->assertNull(ImapConnection::pickJunkFolder(['Junk{evil}']));
		$this->assertNull(ImapConnection::pickJunkFolder([]));
	}

	public function testParseListEntriesCapturesFlags(): void
	{
		$entries = ImapLiveProbe::parseListEntries([
			'* LIST (\\HasNoChildren \\Junk) "/" Junk',
			'* LIST () "/" "Sent Items"',
			'* LIST (\\Noselect) "/" Archives',
			'A050 OK LIST completed',
		], 20);
		$this->assertCount(3, $entries);
		$this->assertSame('Junk', $entries[0]['name']);
		$this->assertContains('\\Junk', $entries[0]['flags']);
		$this->assertSame('Sent Items', $entries[1]['name']);
		$this->assertSame('Junk', ImapConnection::pickJunkFolder($entries));
	}

	public function testMoveUidToJunkRejectsIncompleteWithoutNetwork(): void
	{
		$result = ImapLiveProbe::moveUidToJunk('', 993, '', '', 'INBOX', '1');
		$this->assertFalse($result['ok']);
		$this->assertSame('unknown', $result['reason']);
		$this->assertStringContainsString('required', $result['detail']);
	}
}
