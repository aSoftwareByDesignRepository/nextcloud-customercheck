<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\MailtoReplyBuilder;
use PHPUnit\Framework\TestCase;

final class MailtoReplyBuilderTest extends TestCase
{
	public function testExtractsAngleAddrAndBuildsMailto(): void
	{
		$pack = MailtoReplyBuilder::build(
			'Ada Lovelace <ada@acme.example>',
			'Quote request',
			"Hello\nWe need a quote.",
		);
		$this->assertNotNull($pack);
		$this->assertSame('ada@acme.example', $pack['to']);
		$this->assertSame('Re: Quote request', $pack['subject']);
		$this->assertStringStartsWith('mailto:ada%40acme.example?', $pack['mailtoUrl']);
		$this->assertStringContainsString('subject=', $pack['mailtoUrl']);
		$this->assertStringContainsString('body=', $pack['mailtoUrl']);
		$this->assertStringContainsString('> Hello', $pack['bodyText']);
		$this->assertStringNotContainsString('<b>', $pack['bodyText']);
		$this->assertStringNotContainsString('<script', strtolower($pack['bodyText']));
		$this->assertStringStartsWith('mailto:', $pack['mailtoUrl']);
		$this->assertStringNotContainsString('javascript:', strtolower($pack['mailtoUrl']));
	}

	public function testDoesNotDoubleRePrefix(): void
	{
		$pack = MailtoReplyBuilder::build('bob@example.com', 'Re: Already replied');
		$this->assertNotNull($pack);
		$this->assertSame('Re: Already replied', $pack['subject']);
	}

	public function testRejectsHeaderInjectionAndInvalidEmail(): void
	{
		$this->assertNull(MailtoReplyBuilder::extractEmail("evil@x.com\nbcc:victim@x.com"));
		$this->assertNull(MailtoReplyBuilder::extractEmail('not-an-email'));
		$this->assertNull(MailtoReplyBuilder::extractEmail('ada@acme.example?bcc=victim@x.com'));
		$this->assertNull(MailtoReplyBuilder::build('javascript:alert(1)', 'Hi'));
	}

	public function testCapsBodyLength(): void
	{
		$pack = MailtoReplyBuilder::build('a@b.c', 'S', str_repeat('word ', 800));
		$this->assertNotNull($pack);
		$this->assertLessThanOrEqual(MailtoReplyBuilder::BODY_MAX_CHARS, mb_strlen($pack['bodyText']));
	}

	public function testEmptySubjectGetsFallback(): void
	{
		$pack = MailtoReplyBuilder::build('a@b.c', '  ');
		$this->assertNotNull($pack);
		$this->assertSame('Re: Your message', $pack['subject']);
	}
}
