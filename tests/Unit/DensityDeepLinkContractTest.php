<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** WP-S1-CUC-360 — density tiles deep-link to siblings when linked. */
final class DensityDeepLinkContractTest extends TestCase
{
	public function testCompanyDetailTilesUseDensityHrefs(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$list = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-glance-density-list.php');
		$this->assertStringContainsString('GlanceDensityPresenter::tiles', $tpl);
		$this->assertStringContainsString('crm-glance-density-list.php', $tpl);
		$this->assertStringContainsString('$tileHref', $list);
		$this->assertStringContainsString('href="<?php p($tileHref); ?>"', $list);
	}

	public function testPageControllerBuildsSiblingDeepLinks(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'projectsHref'", $src);
		$this->assertStringContainsString('projectcheck.customer.show', $src);
		$this->assertStringContainsString('invoicecheck.page.receivables', $src);
		$this->assertStringContainsString('invoicecheck.page.show', $src);
		$this->assertStringContainsString('firstUnpaidInvoiceId', $src);
		$this->assertStringContainsString('ticketcheck.customer.show', $src);
		$this->assertStringContainsString('#crm-projects', $src);
		$this->assertStringContainsString('elseif ($unpaidInvoiceCount > 0)', $src);
		$this->assertStringContainsString("\$density['invoicesHref'] = '#crm-invoices'", $src);
	}
}
