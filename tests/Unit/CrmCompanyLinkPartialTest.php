<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WCAG: never emit empty /companies/0 anchors from list tables.
 */
final class CrmCompanyLinkPartialTest extends TestCase
{
	public function testRendersNamedLinkWhenIdPositive(): void
	{
		require_once __DIR__ . '/Support/template_stubs.php';
		$l = new class {
			public function t(string $text, array $parameters = []): string
			{
				return $text;
			}
		};
		$urlGenerator = new class {
			public function linkToRoute(string $route, array $params = []): string
			{
				return '/apps/customercheck/companies/' . (int)($params['id'] ?? 0);
			}
		};
		$crmCompanyLinkId = 42;
		$crmCompanyLinkLabel = 'Acme GmbH';
		ob_start();
		include dirname(__DIR__, 2) . '/templates/common/crm-company-link.php';
		$html = (string)ob_get_clean();
		$this->assertStringContainsString('href="/apps/customercheck/companies/42"', $html);
		$this->assertStringContainsString('Acme GmbH', $html);
	}

	public function testFallsBackWithoutHrefWhenIdMissing(): void
	{
		require_once __DIR__ . '/Support/template_stubs.php';
		$l = new class {
			public function t(string $text, array $parameters = []): string
			{
				return $text;
			}
		};
		$urlGenerator = new class {
			public function linkToRoute(string $route, array $params = []): string
			{
				return '/apps/customercheck/companies/' . (int)($params['id'] ?? 0);
			}
		};
		$crmCompanyLinkId = 0;
		$crmCompanyLinkLabel = '';
		ob_start();
		include dirname(__DIR__, 2) . '/templates/common/crm-company-link.php';
		$html = (string)ob_get_clean();
		$this->assertStringNotContainsString('<a ', $html);
		$this->assertStringNotContainsString('/companies/0', $html);
		$this->assertStringContainsString('Unknown company', $html);
	}
}
