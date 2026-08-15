<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ImapUtf7;
use PHPUnit\Framework\TestCase;

/**
 * Modified UTF-7 ↔ UTF-8 for IMAP mailbox names (German umlauts / subfolders).
 */
final class ImapUtf7Test extends TestCase
{
	/**
	 * @dataProvider umlautVectors
	 */
	public function testRoundTrip(string $utf8, string $wire): void
	{
		$this->assertSame($wire, ImapUtf7::toWire($utf8));
		$this->assertSame($utf8, ImapUtf7::toUtf8($wire));
		$this->assertSame($wire, ImapUtf7::toWire(ImapUtf7::toUtf8($wire)));
		$this->assertSame($utf8, ImapUtf7::toUtf8(ImapUtf7::toWire($utf8)));
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	public function umlautVectors(): array
	{
		return [
			['ä', '&AOQ-'],
			['ö', '&APY-'],
			['ü', '&APw-'],
			['Ä', '&AMQ-'],
			['Ö', '&ANY-'],
			['Ü', '&ANw-'],
			['ß', '&AN8-'],
			['Büro', 'B&APw-ro'],
			['INBOX/Ärger', 'INBOX/&AMQ-rger'],
			['INBOX/Kunden/Quoten', 'INBOX/Kunden/Quoten'],
			['A & B', 'A &- B'],
		];
	}

	public function testAsciiPassthrough(): void
	{
		$this->assertSame('INBOX', ImapUtf7::toUtf8('INBOX'));
		$this->assertSame('INBOX', ImapUtf7::toWire('INBOX'));
		$this->assertSame('Sent Items', ImapUtf7::toWire('Sent Items'));
	}

	public function testFolderKeyCaseFoldsUmlauts(): void
	{
		$this->assertSame(
			ImapUtf7::folderKey('INBOX/Ärger'),
			ImapUtf7::folderKey('INBOX/ärger'),
		);
		$this->assertSame(
			ImapUtf7::folderKey('INBOX/&AMQ-rger'),
			ImapUtf7::folderKey('INBOX/Ärger'),
		);
	}

	public function testRejectsNullBytesSilently(): void
	{
		$this->assertSame('INBOX', ImapUtf7::toUtf8("INBOX\0"));
		$this->assertSame('INBOX', ImapUtf7::toWire("IN\0BOX"));
	}
}
