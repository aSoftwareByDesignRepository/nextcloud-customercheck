<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

/**
 * Render of the Support & Us partial (escaped HTML contract).
 */
final class SupportUsSectionRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 3) . '/tests/Unit/Support/template_stubs.php';
	}

	public function testRenderShowsPartnerOffersForCustomerCheck(): void
	{
		$html = $this->renderSection(
			new SupportUsLinks('CustomerCheck', false, null),
			'en'
		);
		self::assertStringContainsString('data-support-us="1"', $html);
		self::assertStringContainsString('data-support-us-presentation="page"', $html);
		self::assertStringContainsString('crm-support-us--page', $html);
		self::assertStringContainsString('Check Partner', $html);
		self::assertStringContainsString('Ask for a partner offer', $html);
		self::assertStringContainsString('mailto:info@software-by-design.de?subject=', $html);
		self::assertStringContainsString(rawurlencode('CustomerCheck: partner / care retainer'), $html);
		self::assertStringContainsString('noopener noreferrer', $html);
		self::assertStringNotContainsString('Official mobile & terminal licenses', $html);
		self::assertStringNotContainsString('490', $html);
		self::assertStringNotContainsString('<script', $html);
		self::assertNotSame('', trim($html));
	}

	public function testRenderIncludesMobileLicenseWhenConfigured(): void
	{
		$html = $this->renderSection(
			new SupportUsLinks(
				'CustomerCheck',
				true,
				'/apps/customercheck/app-settings/license#section-license'
			),
			'en'
		);
		$plain = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		self::assertStringContainsString('Official mobile & terminal licenses', $plain);
		self::assertStringContainsString('/apps/customercheck/app-settings/license#section-license', $html);
		self::assertStringContainsString('official mobile licenses', $plain);
	}

	public function testEmptyWhenWrongLinksClass(): void
	{
		require_once dirname(__DIR__, 3) . '/tests/Unit/Support/template_stubs.php';
		$l = new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			public function t(string $text, array $parameters = []): string
			{
				return $text;
			}
		};
		$supportUsLinks = new \stdClass();
		$supportUsCssPrefix = 'crm';
		$supportUsPresentation = 'page';
		ob_start();
		include dirname(__DIR__, 3) . '/templates/parts/support-us-section.php';
		$html = (string)ob_get_clean();
		self::assertSame('', trim($html));
	}

	/**
	 * @param array<string, string> $map
	 */
	private function renderSection(SupportUsLinks $supportUsLinks, string $lang, array $map = []): string
	{
		$l = new class ($lang, $map) {
			/** @param array<string, string> $map */
			public function __construct(private string $lang, private array $map)
			{
			}

			public function getLanguageCode(): string
			{
				return $this->lang;
			}

			public function t(string $text, array $parameters = []): string
			{
				$out = $this->map[$text] ?? $text;
				if ($parameters !== []) {
					$out = str_replace('%s', (string)$parameters[0], $out);
				}
				return $out;
			}
		};

		$supportUsCssPrefix = 'crm';
		$supportUsBtnPrimaryClass = 'crm-btn crm-btn--primary';
		$supportUsBtnSecondaryClass = 'crm-btn crm-btn--secondary';
		$supportUsLanguageCode = $lang;
		$supportUsPresentation = 'page';

		ob_start();
		include dirname(__DIR__, 3) . '/templates/parts/support-us-section.php';
		$html = (string)ob_get_clean();
		self::assertNotSame('', trim($html));
		return $html;
	}
}
