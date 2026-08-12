<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ImapLiveProbe;
use PHPUnit\Framework\TestCase;

final class ImapLiveProbePreviewParseTest extends TestCase
{
	public function testParseFetchBodyLiteralFromBraceForm(): void
	{
		$lines = [
			'* 1 FETCH (UID 42 BODY[TEXT] {11}',
			"Hello world",
			'P030 OK Fetch completed.',
		];
		$this->assertSame('Hello world', ImapLiveProbe::parseFetchBodyLiteral($lines));
	}

	public function testParseFetchBodyLiteralFromQuotedInline(): void
	{
		$lines = [
			'* 1 FETCH (UID 7 BODY.PEEK[TEXT] "hi\\"there")',
			'P030 OK',
		];
		$this->assertSame('hi"there', ImapLiveProbe::parseFetchBodyLiteral($lines));
	}

	public function testParseFetchBodyLiteralEmptyWhenMissing(): void
	{
		$this->assertSame('', ImapLiveProbe::parseFetchBodyLiteral(['P030 OK nothing']));
	}
}
