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
		$rows = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-identity-rows.php');
		$this->assertStringContainsString('id="crm-identity-links"', $html);
		$this->assertStringContainsString('crm-identity-rows.php', $html);
		$this->assertStringContainsString('crm-badge--', $rows);
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/identity-links.css');
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('common/identity-links', (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php'));

		$this->assertStringContainsString('data-crm-identity-create="pc"', $rows);
		$this->assertStringContainsString('data-crm-identity-link="pc"', $rows);
		$this->assertStringContainsString('data-crm-identity-unlink="pc"', $rows);
		$this->assertStringContainsString('data-crm-identity-unlink="tc"', $rows);
		// Healthy linked rows must expose Unlink (not only broken links).
		$this->assertMatchesRegularExpression(
			'/\$pcState === \'linked\'.*?data-crm-identity-unlink="pc"/s',
			$rows,
		);
		$this->assertMatchesRegularExpression(
			'/\$tcState === \'linked\'.*?data-crm-identity-unlink="tc"/s',
			$rows,
		);
		$this->assertStringContainsString('role="alert"', $rows);
		$this->assertStringContainsString('Create in ProjectCheck', $rows);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringNotContainsString('style="', $html);
		$this->assertStringNotContainsString('style="', $rows);
	}

	public function testCompanyDetailJsWiresIdentity(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('crm-identity-links', $js);
		$this->assertStringContainsString('Idempotency-Key', $js);
		$this->assertStringContainsString('pendingKeys', $js);
		$this->assertStringContainsString('setIdentityBusy', $js);
		$this->assertStringContainsString('unlink/projectcheck', $js);
		// Bare Api/C/t/promptFields after the page IIFE close → ReferenceError (seen in browser console).
		$this->assertDoesNotMatchRegularExpression(
			'/^\}\)\(\);\s*(?:\/\/[^\n]*\n\s*)*\(function wireIdentityLinks/m',
			$js,
			'wireIdentityLinks must stay inside the company-detail IIFE so Api stays in scope',
		);
	}

	public function testSupportRunbookNotShippedInPublicRepo(): void
	{
		$this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/docs/IDENTITY-SUPPORT-RUNBOOK.md');
		$readme = (string)file_get_contents(dirname(__DIR__, 2) . '/README.md');
		$this->assertStringNotContainsString('IDENTITY-SUPPORT-RUNBOOK.md', $readme);
		$this->assertStringContainsString('identity-health', $readme);
		$this->assertStringContainsString('silent merge', $readme);
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
