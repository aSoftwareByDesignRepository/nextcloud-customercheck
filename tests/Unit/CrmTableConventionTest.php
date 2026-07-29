<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CrmTableConventionTest extends TestCase
{
	public function testListTablesUseResponsiveContainer(): void
	{
		$root = dirname(__DIR__, 2) . '/templates';
		foreach (['companies.php', 'pipeline.php', 'agreements.php', 'money.php', 'activities.php', 'company-detail.php', 'import.php'] as $file) {
			$html = (string)file_get_contents($root . '/' . $file);
			$this->assertStringContainsString('table-container', $html, $file);
			$this->assertStringContainsString('table table--hover', $html, $file);
		}
	}
}
