<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AC-CC-14 — no direct SQL into sibling / filecache tables.
 */
final class NoSiblingSqlContractTest extends TestCase
{
	public function testLibHasNoForeignTableSql(): void
	{
		$root = dirname(__DIR__, 2) . '/lib';
		$forbidden = ['pc_', 'ic_', 'tc_', 'filecache', 'oc_filecache'];
		$hits = [];
		$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($rii as $file) {
			if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
				continue;
			}
			$src = (string)file_get_contents($file->getPathname());
			foreach ($forbidden as $needle) {
				if (preg_match('/\bfrom\s+[\'`]?' . preg_quote($needle, '/') . '/i', $src)
					|| preg_match('/->from\(\s*[\'"]' . preg_quote($needle, '/') . '/', $src)
				) {
					$hits[] = $file->getFilename() . ':' . $needle;
				}
			}
		}
		$this->assertSame([], $hits, 'Forbidden foreign-table SQL: ' . implode(', ', $hits));
	}

	public function testCompaniesListHasHealthColumns(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		foreach (['Last touch', 'Next expiry', 'Money', 'Callbacks'] as $col) {
			$this->assertStringContainsString($col, $html);
		}
	}
}
