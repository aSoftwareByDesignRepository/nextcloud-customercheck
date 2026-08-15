<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GDPR company erase must not build IN() lists from createNamedParameter() tokens —
 * Doctrine treats bare names (ceid0) as columns → SQLSTATE 42S22 / HTTP 500 on DELETE.
 */
final class GdprEraseInClauseContractTest extends TestCase
{
	public function testCancelOpenConflictsUsesIntArrayParameter(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$start = strpos($src, 'function cancelOpenConflicts(');
		$this->assertNotFalse($start);
		$chunk = substr($src, (int)$start, 2200);
		$this->assertStringContainsString('PARAM_INT_ARRAY', $chunk);
		$this->assertStringContainsString("'entity_id'", $chunk);
		$this->assertStringContainsString('$entityIds, IQueryBuilder::PARAM_INT_ARRAY', $chunk);
		$this->assertDoesNotMatchRegularExpression(
			"/createNamedParameter\(\\\$eid,\s*IQueryBuilder::PARAM_INT,\s*'ceid'/",
			$chunk,
		);
	}

	public function testErasePersonaPlanDeletesUseIntArrayParameter(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$start = strpos($src, 'function erasePersonaLedgers(');
		$this->assertNotFalse($start);
		$chunk = substr($src, (int)$start, 2800);
		$this->assertStringContainsString("delete('crm_map_step')", $chunk);
		$this->assertStringContainsString("delete('crm_plan_ms')", $chunk);
		$this->assertSame(2, substr_count($chunk, 'PARAM_INT_ARRAY'));
		$this->assertDoesNotMatchRegularExpression(
			"/createNamedParameter\(\\\$pid,\s*null,\s*'pid'/",
			$chunk,
		);
		$this->assertDoesNotMatchRegularExpression(
			"/createNamedParameter\(\\\$pid,\s*null,\s*'pmid'/",
			$chunk,
		);
	}

	public function testCoachingBulkFiltersUseArrayParameters(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CoachingService.php');
		$this->assertStringContainsString('use OCP\\DB\\QueryBuilder\\IQueryBuilder;', $src);
		$this->assertStringContainsString('PARAM_INT_ARRAY', $src);
		$this->assertStringContainsString('PARAM_STR_ARRAY', $src);
		$this->assertDoesNotMatchRegularExpression(
			"/createNamedParameter\(\\\$id,\s*null,\s*'did'/",
			$src,
		);
		$this->assertDoesNotMatchRegularExpression(
			"/createNamedParameter\(\\\$id,\s*null,\s*'rid'/",
			$src,
		);
	}

	public function testDestroyRoutesThroughGdprErase(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/CompanyApiController.php');
		$start = strpos($ctrl, 'public function destroy(int $id)');
		$this->assertNotFalse($start);
		$chunk = substr($ctrl, (int)$start, 500);
		$this->assertStringContainsString('gdprErase', $chunk);
		$this->assertStringContainsString('confirmSlug', $chunk);
	}
}
