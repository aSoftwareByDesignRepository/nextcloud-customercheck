<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * List pages must not search when the resolved workspace id is 0.
 */
final class ListWorkspaceGuardContractTest extends TestCase
{
	public function testListPagesGuardBeforeSearch(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('use OCA\\CustomerCheck\\Support\\ListWorkspaceGuard;', $src);
		$this->assertGreaterThanOrEqual(6, substr_count($src, 'ListWorkspaceGuard::isListable'));

		$companies = $this->methodChunk($src, 'function companies(): TemplateResponse');
		$this->assertStringContainsString('ListWorkspaceGuard::isListable($wsId)', $companies);
		$searchPos = strpos($companies, 'companies->search');
		$guardPos = strpos($companies, 'ListWorkspaceGuard::isListable');
		$this->assertNotFalse($searchPos);
		$this->assertNotFalse($guardPos);
		$this->assertLessThan($searchPos, $guardPos, 'companies must guard before search');

		$pipeline = $this->methodChunk($src, 'function pipeline(): TemplateResponse');
		$this->assertStringContainsString('ListWorkspaceGuard::isListable($wsId)', $pipeline);
		$this->assertLessThan(
			(int)strpos($pipeline, 'deals->search'),
			(int)strpos($pipeline, 'ListWorkspaceGuard::isListable'),
		);

		$agreements = $this->methodChunk($src, 'function agreements(): TemplateResponse');
		$this->assertLessThan(
			(int)strpos($agreements, 'agreements->search'),
			(int)strpos($agreements, 'ListWorkspaceGuard::isListable'),
		);

		$money = $this->methodChunk($src, 'function money(): TemplateResponse');
		$this->assertStringContainsString('ListWorkspaceGuard::isListable($wsId) ? $wsId : 0', $money);

		$activities = $this->methodChunk($src, 'function activities(): TemplateResponse');
		$this->assertLessThan(
			(int)strpos($activities, 'activities->search'),
			(int)strpos($activities, 'ListWorkspaceGuard::isListable'),
		);

		$today = $this->methodChunk($src, 'function today(): TemplateResponse');
		$this->assertStringContainsString(
			"\$companyCount = ListWorkspaceGuard::isListable(\$wsId)\n\t\t\t? \$this->companies->count(['archived' => false, 'workspaceId' => \$wsId])",
			$today,
		);
	}

	private function methodChunk(string $src, string $needle): string
	{
		$start = strpos($src, $needle);
		$this->assertNotFalse($start, $needle);
		return substr($src, $start, 3500);
	}
}
