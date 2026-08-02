<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Support\OptimisticLock;
use PHPUnit\Framework\TestCase;

final class OptimisticLockConflictApplyTest extends TestCase
{
	public function testEmptyPreconditionAllowedWhenNotRequired(): void
	{
		OptimisticLock::assertFresh(new \DateTimeImmutable('2026-08-01'), null, false);
		OptimisticLock::assertFresh(new \DateTimeImmutable('2026-08-01'), '', false);
		$this->assertTrue(true);
	}

	public function testEmptyPreconditionRejectedWhenRequired(): void
	{
		$this->expectException(ConflictException::class);
		OptimisticLock::assertFresh(new \DateTimeImmutable('2026-08-01'), null, true);
	}
}
