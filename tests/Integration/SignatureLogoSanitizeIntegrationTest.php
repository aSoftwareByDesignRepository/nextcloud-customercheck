<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\HtmlSanitizeService;
use Test\TestCase;

/**
 * Live-container proof: instance hosts unlock same-origin http public-share logos.
 */
final class SignatureLogoSanitizeIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker).');
		}
	}

	public function testContainerResolvesHtmlSanitizeAndAllowsSameOriginHttpShare(): void
	{
		/** @var HtmlSanitizeService $svc */
		$svc = \OC::$server->get(HtmlSanitizeService::class);
		$this->assertInstanceOf(HtmlSanitizeService::class, $svc);

		$urlGenerator = \OC::$server->get(\OCP\IURLGenerator::class);
		$abs = $urlGenerator->getAbsoluteURL('/');
		$host = parse_url($abs, PHP_URL_HOST);
		$port = parse_url($abs, PHP_URL_PORT);
		$this->assertNotFalse($host);
		$this->assertNotNull($host);
		$hostPort = $host . ($port ? (':' . $port) : '');

		$src = 'http://' . $hostPort . '/s/IntegTok99/download';
		$out = $svc->sanitizeSignature('<p>Sig</p><img src="' . $src . '" alt="Logo" width="180">');
		$this->assertStringContainsString('<img', strtolower($out));
		$this->assertStringContainsString($src, $out);

		$evil = $svc->sanitizeSignature('<img src="http://evil.example/s/IntegTok99/download" alt="x">');
		$this->assertStringNotContainsString('<img', strtolower($evil));

		$body = $svc->sanitize('<img src="' . $src . '" alt="x">');
		$this->assertStringNotContainsString('<img', strtolower($body));
	}
}
