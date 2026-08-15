<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** My customers page — primary Add customer CTA in page header + empty state. */
final class MyAccountsAddCustomerUxContractTest extends TestCase
{
	public function testMyAccountsTemplateExposesAddCustomerHeaderAction(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/my-accounts.php');
		$this->assertStringContainsString('crm-page-actions-source', $html);
		$this->assertStringContainsString('id="crm-my-accounts-add"', $html);
		$this->assertStringContainsString("\$_['urls']['companyNew']", $html);
		$this->assertStringContainsString("\$l->t('Add customer')", $html);
		$this->assertStringContainsString("!empty(\$_['canWrite'])", $html);
	}

	public function testMyAccountsEmptyStateLinksToCompanyNew(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertMatchesRegularExpression(
			"/kind === 'my-accounts'[\s\S]{0,200}loadAccounts/",
			$js,
		);
		$fnStart = strpos($js, 'async function loadAccounts(mount)');
		$this->assertNotFalse($fnStart);
		$fnEnd = strpos($js, 'function userPickerFieldHtml', $fnStart);
		$this->assertNotFalse($fnEnd);
		$fn = substr($js, $fnStart, $fnEnd - $fnStart);
		$this->assertStringContainsString("/apps/customercheck/companies/new", $fn);
		$this->assertStringContainsString("t('Add customer')", $fn);
		$this->assertStringNotContainsString("t('Browse companies')", $fn);
	}
}
