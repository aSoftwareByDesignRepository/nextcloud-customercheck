<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Db;

use OCA\CustomerCheck\Db\HoursMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Focused GDPR anonymisation helper: empty agreement id lists must no-op
 * without touching the database (CompanyService::gdprErase edge path).
 */
final class HoursAnonymiseHelperTest extends TestCase
{
	public function testAnonymiseByAgreementsReturnsZeroForEmptyIds(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$mapper = new HoursMapper($db);
		$this->assertSame(0, $mapper->anonymiseByAgreements([]));
		$this->assertSame(0, $mapper->anonymiseByAgreements([0, -1]));
	}
}
