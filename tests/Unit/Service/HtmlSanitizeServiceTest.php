<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizeServiceTest extends TestCase
{
	private HtmlSanitizeService $svc;

	protected function setUp(): void
	{
		$this->svc = new HtmlSanitizeService();
	}

	public function testStripsScriptAndHandlers(): void
	{
		$html = '<p onclick="alert(1)">Hi</p><script>evil()</script><a href="javascript:alert(1)">x</a>';
		$out = $this->svc->sanitize($html);
		$this->assertStringNotContainsString('<script', strtolower($out));
		$this->assertStringNotContainsString('onclick', strtolower($out));
		$this->assertStringNotContainsString('javascript:', strtolower($out));
		$this->assertStringContainsString('<p>', $out);
		$this->assertStringContainsString('Hi', $out);
	}

	public function testKeepsSafeLinkAndEmphasis(): void
	{
		$html = '<p>Hello <strong>world</strong> — <a href="https://example.com/x">link</a></p>';
		$out = $this->svc->sanitize($html);
		$this->assertStringContainsString('<strong>', $out);
		$this->assertStringContainsString('href="https://example.com/x"', $out);
		$this->assertStringContainsString('rel="noopener noreferrer"', $out);
	}

	public function testRejectsDataAndVbscriptUrls(): void
	{
		$out = $this->svc->sanitize('<a href="data:text/html,x">bad</a><a href="vbscript:msg">bad2</a>');
		$this->assertStringNotContainsString('data:', $out);
		$this->assertStringNotContainsString('vbscript:', $out);
	}

	public function testHtmlToPlainProducesAlternative(): void
	{
		$plain = $this->svc->htmlToPlain('<p>Line one</p><p>Line <em>two</em></p>');
		$this->assertStringContainsString('Line one', $plain);
		$this->assertStringContainsString('Line two', $plain);
		$this->assertStringNotContainsString('<', $plain);
	}

	public function testEmptyInput(): void
	{
		$this->assertSame('', $this->svc->sanitize(null));
		$this->assertSame('', $this->svc->sanitize('   '));
		$this->assertSame('', $this->svc->htmlToPlain(''));
	}

	public function testNormativeAllowlistDropsH1AndCode(): void
	{
		$out = $this->svc->sanitize('<h1>Title</h1><h2>Ok</h2><pre>x</pre><code>y</code><p>z</p>');
		$this->assertStringNotContainsString('<h1', strtolower($out));
		$this->assertStringNotContainsString('<pre', strtolower($out));
		$this->assertStringNotContainsString('<code', strtolower($out));
		$this->assertStringContainsString('<h2>', $out);
		$this->assertStringContainsString('Title', $out);
		$this->assertStringContainsString('z', $out);
	}

	public function testBodySanitizeStripsImages(): void
	{
		$out = $this->svc->sanitize('<p>Hi</p><img src="https://example.com/a.png" alt="x">');
		$this->assertStringNotContainsString('<img', strtolower($out));
		$this->assertStringContainsString('Hi', $out);
	}

	public function testSignatureAllowsHttpHttpsImgNotData(): void
	{
		$ok = $this->svc->sanitizeSignature('<p>Sig</p><img src="https://cdn.example.com/logo.png" alt="Co" width="200">');
		$this->assertStringContainsString('<img', strtolower($ok));
		$this->assertStringContainsString('https://cdn.example.com/logo.png', $ok);
		$this->assertStringContainsString('alt="Co"', $ok);
		$this->assertStringContainsString('width="200"', $ok);

		// http allowed for self-hosted Nextcloud without TLS (public File links).
		$http = $this->svc->sanitizeSignature('<img src="http://files.local/logo.png">');
		$this->assertStringContainsString('<img', strtolower($http));
		$this->assertStringContainsString('http://files.local/logo.png', $http);

		$data = $this->svc->sanitizeSignature('<img src="data:image/png;base64,xxxx">');
		$this->assertStringNotContainsString('<img', strtolower($data));

		$js = $this->svc->sanitizeSignature('<img src="javascript:alert(1)">');
		$this->assertStringNotContainsString('<img', strtolower($js));
	}
}
