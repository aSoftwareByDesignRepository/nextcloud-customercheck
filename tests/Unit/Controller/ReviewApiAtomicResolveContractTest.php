<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Contract: conflict resolve wraps mark+apply in one DB transaction (GNQ §6.4).
 */
final class ReviewApiAtomicResolveContractTest extends TestCase
{
	public function testResolveConflictUsesOuterTransactionAndTimeline(): void
	{
		$src = file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/ReviewApiController.php',
		);
		self::assertNotFalse($src);
		self::assertStringContainsString('beginTransaction()', $src);
		self::assertStringContainsString('applyConflictResolution', $src);
		self::assertStringContainsString("conflict.resolved", $src);
		self::assertStringContainsString('reopenAfterFailedApply', $src);
		// Apply failure inside an open txn must roll back — not only compensator.
		self::assertMatchesRegularExpression(
			'/beginTransaction\(\).*?resolve\(/s',
			$src,
		);
		self::assertMatchesRegularExpression(
			'/inTransaction\(\).*?rollBack\(\)/s',
			$src,
		);
	}
}
