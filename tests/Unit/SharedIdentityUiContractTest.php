<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** SHARED-IDENTITY AC-C-04/16 — 360 identity surfaces + support runbook. */
final class SharedIdentityUiContractTest extends TestCase
{
	public function testCompanyDetailHasIdentityCtas(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-identity-links"', $html);
		$this->assertStringContainsString('crm-badge--', $html);
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/identity-links.css');
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('common/identity-links', (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php'));

		$this->assertStringContainsString('data-crm-identity-create="pc"', $html);
		$this->assertStringContainsString('data-crm-identity-link="pc"', $html);
		$this->assertStringContainsString('role="alert"', $html);
		$this->assertStringContainsString('Create in ProjectCheck', $html);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringNotContainsString('style="', $html);
	}

	public function testCompanyDetailJsWiresIdentity(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('crm-identity-links', $js);
		$this->assertStringContainsString('Idempotency-Key', $js);
		$this->assertStringContainsString('pendingKeys', $js);
		$this->assertStringContainsString('setIdentityBusy', $js);
		$this->assertStringContainsString('unlink/projectcheck', $js);
	}

	public function testSupportRunbookExists(): void
	{
		$path = dirname(__DIR__, 2) . '/docs/IDENTITY-SUPPORT-RUNBOOK.md';
		$this->assertFileExists($path);
		$body = (string)file_get_contents($path);
		$this->assertStringContainsString('Broken link', $body);
		$this->assertStringContainsString('identity-health', $body);
		$this->assertTrue(
			str_contains(strtolower($body), 'no silent merge')
			|| str_contains(strtolower($body), 'never silent merge'),
			'Runbook must ban silent merge'
		);
		$this->assertStringContainsString('Unlink', $body);
		$readme = (string)file_get_contents(dirname(__DIR__, 2) . '/README.md');
		$this->assertStringContainsString('IDENTITY-SUPPORT-RUNBOOK.md', $readme);
		$help = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString('two Acmes', $help);
		$this->assertStringContainsString('identity support guide', $help);
	}

	public function testRoutesExposeIdentityHealthAndUnlink(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertStringContainsString('identity_health_api#summary', $routes);
		$this->assertStringContainsString('unlinkProjectcheck', $routes);
		$this->assertStringContainsString('createFieldCustomer', $routes);
	}

	public function testRenamePushFailureOffersOpenProjectCheckCta(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-form.js');
		$this->assertStringContainsString('renamePushWarning', $js);
		$this->assertStringContainsString('Open ProjectCheck', $js);
		$this->assertStringContainsString('/apps/projectcheck/customers/', $js);
		$this->assertStringContainsString('crm-rename-push-alert', $js);
		$this->assertStringContainsString('pendingKey', $js);
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringContainsString('id="crm-rename-push-alert"', $html);
		$this->assertMatchesRegularExpression('/crm-rename-push-alert[^>]*role="alert"/', $html);
	}
}
