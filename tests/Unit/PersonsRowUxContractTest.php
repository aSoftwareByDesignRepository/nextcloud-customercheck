<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Bachus: person row actions stay flat — no More/scroll-trap in People. */
final class PersonsRowUxContractTest extends TestCase
{
	public function testPersonsTableExposesVisibleColumnsAndPrimaryCta(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = strpos($html, 'id="crm-persons-table"');
		$end = strpos($html, 'id="crm-deals"', (int)$start);
		$chunk = substr($html, (int)$start, (int)$end - (int)$start);
		foreach (['Action', 'Log talk', 'Not linked', 'Remove', 'Last touch', 'Address book', 'Role in buying'] as $needle) {
			$this->assertStringContainsString("\$l->t('" . $needle . "')", $chunk, $needle);
		}
		$this->assertStringContainsString('crm-person-more', $chunk);
		$this->assertStringContainsString('data-crm-remove-person', $chunk);
		$this->assertStringContainsString('crm-persons-role', $chunk);
		$this->assertStringContainsString('crm-btn--primary', $chunk);
		$this->assertStringNotContainsString('crm-more-menu', $chunk);
		$this->assertStringNotContainsString("\$l->t('Last touch')); ?></th>", $chunk);
		$this->assertStringNotContainsString("\$l->t('Role in buying')); ?></th>", $chunk);
	}

	public function testCssPreventsTableMoreMenuAbsoluteScrollTrap(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.table .crm-more-menu__panel', $css);
		$this->assertStringContainsString('position: static', $css);
		$this->assertStringContainsString('.crm-persons-table-wrap', $css);
		$this->assertStringContainsString('overflow: visible', $css);
	}
}
