<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\MimeHeader;
use PHPUnit\Framework\TestCase;

/**
 * RFC 2047 header decoding — subjects must never show as =?UTF-8?Q?…?= in the UI.
 */
final class MimeHeaderTest extends TestCase
{
	public function testDecodesQuotedPrintableUtf8Subject(): void
	{
		$raw = '=?UTF-8?Q?Anfrage_f=C3=BCr_1_w=C3=B6chiges_Praktikum?=';
		$this->assertSame('Anfrage für 1 wöchiges Praktikum', MimeHeader::decode($raw));
	}

	public function testDecodesBase64Utf8Subject(): void
	{
		$raw = '=?UTF-8?B?QW5mcmFnZSBmw7xyIFByYWt0aWt1bQ==?=';
		$this->assertSame('Anfrage für Praktikum', MimeHeader::decode($raw));
	}

	public function testLeavesPlainSubjectUntouched(): void
	{
		$this->assertSame('Hello team', MimeHeader::decode('Hello team'));
		$this->assertSame('', MimeHeader::decode(''));
		$this->assertSame('Already decoded für test', MimeHeader::decode('Already decoded für test'));
	}

	public function testDecodesAdjacentEncodedWords(): void
	{
		$raw = '=?UTF-8?Q?Hallo?= =?UTF-8?Q?_Welt?=';
		$this->assertSame('Hallo Welt', MimeHeader::decode($raw));
	}

	public function testImapLiveProbeSubjectUsesMimeDecode(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Support/ImapLiveProbe.php');
		$this->assertStringContainsString('MimeHeader::decode(trim($m[1]))', $src);
		$svc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('MimeHeader::decode((string)($msg[\'subject\'] ?? \'\'))', $svc);
		$this->assertStringContainsString('MimeHeader::decode((string)($row[\'subject\'] ?? \'\'))', $svc);
		$review = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('MimeHeader::decode((string)($row[\'subject\'] ?? \'\'))', $review);
		$this->assertStringContainsString("MimeHeader::decode((string)(\$data['subject'] ?? ''))", $review);
		$this->assertStringContainsString("MimeHeader::decode((string)(\$data['snippet'] ?? ''))", $review);
	}
}
