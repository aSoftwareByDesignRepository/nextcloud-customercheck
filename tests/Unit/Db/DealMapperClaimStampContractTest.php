<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Db;

use OCA\CustomerCheck\Db\DealMapper;
use Test\TestCase;

/**
 * claimUnowned must CAS on updated_at (not owner-only) — product §18.7 race class.
 */
final class DealMapperClaimStampContractTest extends TestCase
{
	public function testClaimUnownedRequiresExpectedUpdatedAtInWhere(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Db/DealMapper.php',
		);
		$this->assertMatchesRegularExpression(
			'/function claimUnowned\([\s\S]*?\$expectedUpdatedAt[\s\S]*?\$now[\s\S]*?'
			. 'eq\(\'updated_at\'[\s\S]*?\$expected[\s\S]*?executeStatement/s',
			$src,
		);
		$this->assertSame(DealMapper::class, DealMapper::class);
	}
}
