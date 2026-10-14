<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** WP-S1-CUC-PKG / AC-S1.1 — Store copy must not say internal-only. */
final class PublicPackagingContractTest extends TestCase
{
	public function testComposerDescriptionIsPublic(): void
	{
		$composer = (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json');
		$this->assertStringNotContainsString('Internal customer', $composer);
		$this->assertStringContainsString('command center for customer work', $composer);
	}

	public function testInfoXmlIsStoreReady(): void
	{
		$xml = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$this->assertStringNotContainsString('Internal distribution only', $xml);
		$this->assertStringNotContainsString('internal only', strtolower($xml));
		$this->assertStringContainsString('<id>customercheck</id>', $xml);
		$this->assertStringContainsString('CRM for Nextcloud', $xml);
		$this->assertStringContainsString('CRM für Nextcloud', $xml);
		$this->assertMatchesRegularExpression('/<licence>\s*agpl/i', $xml);
	}
}
