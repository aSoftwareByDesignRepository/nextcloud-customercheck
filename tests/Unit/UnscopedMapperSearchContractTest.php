<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mapper search() without a workspace, company, or deal must be 1=0 — never every book.
 */
final class UnscopedMapperSearchContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testCompanySearchFailClosesWithoutWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/CompanyMapper.php');
		$this->assertStringContainsString('} elseif ($workspaceId > 0) {', $src);
		$this->assertStringContainsString('// Fail closed: unscoped company search is every book.', $src);
		$this->assertMatchesRegularExpression(
			'/elseif \(\$workspaceId > 0\) \{.*\}\s+else \{\s+\/\/ Fail closed: unscoped company search is every book\./s',
			$src,
		);
	}

	public function testDealSearchFailClosesWithoutWorkspaceOrCompany(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/DealMapper.php');
		$this->assertStringContainsString('} elseif ($workspaceId > 0) {', $src);
		$this->assertStringContainsString('} elseif ($companyId <= 0) {', $src);
		$this->assertStringContainsString(
			'$qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0))',
			$src,
		);
	}

	public function testActivitySearchFailClosesWithoutWorkspaceCompanyOrDeal(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/ActivityMapper.php');
		$this->assertStringContainsString('} elseif ($workspaceId > 0) {', $src);
		$this->assertStringContainsString('} elseif ($companyId <= 0 && $dealId <= 0) {', $src);
		$this->assertStringContainsString('restrictToWorkspaces($qb, $workspaceIds)', $src);
	}

	public function testAgreementSearchFailClosesWithoutWorkspaceOrCompany(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/AgreementMapper.php');
		$apply = $this->extractMethod($src, 'private function applyFilters');
		$this->assertStringContainsString('if (is_array($workspaceIds)) {', $apply);
		$this->assertStringNotContainsString('if (false && is_array($workspaceIds)) {', $apply);
		$this->assertStringContainsString('restrictToWorkspaces($qb, $workspaceIds)', $apply);
		$this->assertStringContainsString('} elseif ($companyId <= 0 && $companyIds === []) {', $apply);
		$this->assertStringContainsString(
			'$qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0))',
			$apply,
		);
	}

	public function testCompanyNameLookupRequiresWorkspace(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/CompanyMapper.php');
		$fn = $this->extractMethod($src, 'function findByNameCaseInsensitive', 700);
		$this->assertStringContainsString('function findByNameCaseInsensitive(string $name, int $workspaceId)', $fn);
		$this->assertStringContainsString('if ($workspaceId <= 0) {', $fn);
		$this->assertStringContainsString("->andWhere(\$qb->expr()->eq('workspace_id'", $fn);
		$this->assertStringContainsString("->andWhere(\$qb->expr()->isNull('archived_at'))", $fn);
		$this->assertStringNotContainsString('private function applyFilters', $fn);
	}

	private function extractMethod(string $src, string $needle, int $limit = 2500): string
	{
		$start = strpos($src, $needle);
		$this->assertNotFalse($start, $needle);
		return substr($src, $start, $limit);
	}

	public function testPersonSearchFailClosesWithoutWorkspaceOrCompany(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Db/PersonMapper.php');
		$this->assertStringContainsString('} elseif ($companyId <= 0) {', $src);
		$this->assertStringContainsString(
			'// Fail closed: a contact search without a book or company is every person.',
			$src,
		);
	}

	public function testProductionDoesNotCallUnscopedMapperSearch(): void
	{
		$lib = $this->root . '/lib';
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS)
		);
		$offenders = [];
		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$src = (string)file_get_contents($file->getPathname());
			if (preg_match('/->search\(\s*\[\s*\]/', $src) === 1) {
				$offenders[] = substr($file->getPathname(), strlen($this->root) + 1);
			}
			if (preg_match("/->search\(\s*\[\s*'openOnly'\s*=>\s*true\s*\]/", $src) === 1) {
				$offenders[] = substr($file->getPathname(), strlen($this->root) + 1) . ' (openOnly-only)';
			}
		}
		$this->assertSame([], $offenders, 'unscoped search() in production lib/');
	}
}
