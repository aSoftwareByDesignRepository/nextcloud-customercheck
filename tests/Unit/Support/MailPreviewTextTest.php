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

	public function testStripHtmlPublicHelperRejectsMarkup(): void
	{
		$this->assertStringNotContainsString('<b>', MailPreviewText::stripHtml('<b>Hi</b>'));
		$this->assertStringContainsString('Hi', MailPreviewText::stripHtml('<b>Hi</b>'));
		$inline = MailPreviewText::stripHtml('Hello <b>World</b>!');
		$this->assertStringContainsString('Hello', $inline);
		$this->assertStringContainsString('World', $inline);
		$this->assertStringNotContainsString('<', $inline);
	}
}
