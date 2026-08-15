<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\MailPreviewText;
use PHPUnit\Framework\TestCase;

final class MailPreviewTextTest extends TestCase
{
	public function testStripsHtmlAndDecodesEntities(): void
	{
		$html = '<html><body><p>Hello&nbsp;<b>World</b></p></body></html>';
		$out = MailPreviewText::toPlainPreview($html, 1000);
		$this->assertStringContainsString('Hello', $out);
		$this->assertStringContainsString('World', $out);
		$this->assertStringNotContainsString('<', $out);
	}

	public function testCapsLengthWithEllipsis(): void
	{
		$raw = str_repeat('a', 2000);
		$out = MailPreviewText::toPlainPreview($raw, 600);
		$this->assertLessThanOrEqual(620, strlen($out));
		$this->assertStringEndsWith('…', $out);
	}

	public function testPlainTextPassesThrough(): void
	{
		$this->assertSame("Line one\n\nLine two", MailPreviewText::toPlainPreview("Line one\n\n\nLine two", 500));
	}

	public function testQuotedPrintableSoftBreaks(): void
	{
		$raw = "Hello=\r\n world";
		$out = MailPreviewText::toPlainPreview($raw, 500);
		$this->assertStringContainsString('Hello', $out);
		$this->assertStringContainsString('world', $out);
	}

	public function testScriptTagsNeverSurvive(): void
	{
		$html = '<html><body><script>alert(1)</script><p onclick="x">Safe</p></body></html>';
		$out = MailPreviewText::toPlainPreview($html, 2000);
		$this->assertStringContainsString('Safe', $out);
		$this->assertStringNotContainsString('<script', strtolower($out));
		$this->assertStringNotContainsString('alert(1)', $out);
		$this->assertStringNotContainsString('onclick', strtolower($out));
	}

	public function testEmptyAfterStripReturnsEmpty(): void
	{
		$this->assertSame('', MailPreviewText::toPlainPreview('<html><body>   </body></html>', 500));
		$this->assertSame('', MailPreviewText::toPlainPreview(" \n\t ", 500));
	}

	public function testUtf8TruncationDoesNotEmitInvalidBytes(): void
	{
		$raw = str_repeat('ü', 400);
		$out = MailPreviewText::toPlainPreview($raw, 50);
		$this->assertTrue(mb_check_encoding($out, 'UTF-8'));
		$this->assertStringEndsWith('…', $out);
	}

	public function testInvalidUtf8BytesBecomeJsonSafe(): void
	{
		$bad = "hello \xB1\xB2 world";
		$out = MailPreviewText::toPlainPreview($bad, 2000);
		$this->assertTrue(mb_check_encoding($out, 'UTF-8'), 'preview must be valid UTF-8');
		$json = json_encode(['preview' => $out], JSON_THROW_ON_ERROR | JSON_HEX_TAG);
		$this->assertIsString($json);
		$this->assertStringContainsString('hello', $out);
		$this->assertStringContainsString('world', $out);
	}

	public function testEnsureUtf8DropsOrReinterpretsInvalidSequences(): void
	{
		$bad = "ok\xFF\xFEnot";
		$fixed = MailPreviewText::ensureUtf8($bad);
		$this->assertTrue(mb_check_encoding($fixed, 'UTF-8'));
		json_encode(['x' => $fixed], JSON_THROW_ON_ERROR);
		$this->assertSame('', MailPreviewText::ensureUtf8(''));
		$this->assertSame('already fine', MailPreviewText::ensureUtf8('already fine'));
	}

	public function testMixedValidUtf8WithGarbageKeepsValidParts(): void
	{
		$mixed = "Grüße \xB1 Team";
		$fixed = MailPreviewText::ensureUtf8($mixed);
		$this->assertTrue(mb_check_encoding($fixed, 'UTF-8'));
		$this->assertStringContainsString('Grüße', $fixed);
		json_encode(['preview' => $fixed], JSON_THROW_ON_ERROR);
	}

	public function testStripHtmlPublicHelperRejectsMarkup(): void
	{
		$this->assertStringNotContainsString('<b>', MailPreviewText::stripHtml('<b>Hi</b>'));
		$this->assertStringContainsString('Hi', MailPreviewText::stripHtml('<b>Hi</b>'));
		$inline = MailPreviewText::stripHtml('Hello <b>World</b>!');
		$this->assertStringContainsString('Hello', $inline);
		$this->assertStringContainsString('World', $inline);
		$this->assertStringNotContainsString('<', $inline);
	}

	public function testMultipartPrefersQuotedPrintablePlainText(): void
	{
		$raw = <<<'MIME'
--------=_MB90DD0DD7-84CC-4507-9670-B0B0429898A6
Content-Type: text/plain; charset=utf-8; format=flowed
Content-Transfer-Encoding: quoted-printable

Guten Tag,

ich bin am Wochenende zuf=C3=A4llig auf Ihre Nextcloud-App ArbeitszeitCheck
gestossen.

Herzliche Gr=C3=BC=C3=9Fe
Oliver Stahl

--------=_MB90DD0DD7-84CC-4507-9670-B0B0429898A6
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: quoted-printable

<html><body><p>Guten Tag,<script>alert(1)</script></p></body></html>
--------=_MB90DD0DD7-84CC-4507-9670-B0B0429898A6--
MIME;
		$out = MailPreviewText::toPlainPreview($raw, 4000);
		$this->assertStringContainsString('Guten Tag', $out);
		$this->assertStringContainsString('zufällig', $out);
		$this->assertStringContainsString('Oliver Stahl', $out);
		$this->assertStringNotContainsString('Content-Type:', $out);
		$this->assertStringNotContainsString('--------=_MB', $out);
		$this->assertStringNotContainsString('text/html', $out);
		$this->assertStringNotContainsString('alert(1)', $out);
		$this->assertStringNotContainsString('<script', strtolower($out));
	}

	public function testMultipartFallsBackToHtmlWhenPlainMissing(): void
	{
		$raw = <<<'MIME'
--bound123
Content-Type: text/html; charset=utf-8

<p>Only <b>HTML</b> part</p>
--bound123--
MIME;
		$out = MailPreviewText::toPlainPreview($raw, 1000);
		$this->assertStringContainsString('Only', $out);
		$this->assertStringContainsString('HTML', $out);
		$this->assertStringNotContainsString('<', $out);
		$this->assertStringNotContainsString('--bound123', $out);
	}

	public function testExtractBestMimePartPublicHelper(): void
	{
		$raw = "--b\r\nContent-Type: text/plain\r\n\r\nHello plain\r\n--b\r\nContent-Type: text/html\r\n\r\n<p>Hi</p>\r\n--b--";
		$part = MailPreviewText::extractBestMimePart($raw);
		$this->assertSame('Hello plain', trim(str_replace("\r", '', $part)));
	}

	public function testStripMimeDebrisCutsTrailingMultipartRelated(): void
	{
		$raw = <<<'TXT'
Sehr geehrter Herr Mäule,

vielen Dank für Ihre Nachricht.

Mit freundlichen Grüßen
Daniel Gattinger

--------------LNEkWOv0CvxEz6gPphCPK5Oc
Content-Type: multipart/related;
 boundary="------------pX2DXGGfcN7S87HjbirCiY3T"
TXT;
		$out = MailPreviewText::toPlainPreview($raw, 8000);
		$this->assertStringContainsString('Sehr geehrter Herr Mäule', $out);
		$this->assertStringContainsString('Daniel Gattinger', $out);
		$this->assertStringNotContainsString('Content-Type:', $out);
		$this->assertStringNotContainsString('multipart/related', $out);
		$this->assertStringNotContainsString('LNEkWOv0CvxEz6gPphCPK5Oc', $out);
		$this->assertStringNotContainsString('boundary=', $out);
	}

	public function testStripMimeDebrisHandlesQuotePrefixedMimeTail(): void
	{
		$raw = "> Hello customer\n> \n> --------------ABC123DEF456\n> Content-Type: multipart/related;\n>  boundary=\"------------xyz\"\n";
		$out = MailPreviewText::stripMimeDebris($raw);
		$this->assertStringContainsString('Hello customer', $out);
		$this->assertStringNotContainsString('Content-Type:', $out);
		$this->assertStringNotContainsString('ABC123DEF456', $out);
	}

	public function testStripMimeDebrisLeavesCleanPlainUnchanged(): void
	{
		$clean = "Line one\n\nLine two with dashes ---- still fine";
		$this->assertSame($clean, MailPreviewText::stripMimeDebris($clean));
	}
}
