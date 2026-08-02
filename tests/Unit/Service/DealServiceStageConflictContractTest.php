<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Contract: deal stage participates in three-way conflict queue (GNQ §6 / §8.4). */
final class DealServiceStageConflictContractTest extends TestCase
{
	public function testSnapshotAndChangeStageQueueStage(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/DealService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString("'stage' => (string)\$deal->getStage()", $src);
		self::assertStringContainsString("'stage',", $src);
		self::assertStringContainsString('ERR_EDIT_CONFLICT', $src);
		self::assertStringContainsString("array_key_exists('stage', \$data) && \$skipOptimistic", $src);
		self::assertStringContainsString('recordOpenConflict', $src);
	}
}
