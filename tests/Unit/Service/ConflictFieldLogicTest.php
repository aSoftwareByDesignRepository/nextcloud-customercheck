<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ConflictFieldLogic;
use PHPUnit\Framework\TestCase;

final class ConflictFieldLogicTest extends TestCase
{
	public function testTakeIncomingWhenCurrentEqualsBase(): void
	{
		$this->assertSame('take_incoming', ConflictFieldLogic::classify('A', 'A', 'B'));
	}

	public function testKeepCurrentWhenIncomingEqualsBase(): void
	{
		$this->assertSame('keep_current', ConflictFieldLogic::classify('A', 'B', 'A'));
	}

	public function testConflictWhenAllDiffer(): void
	{
		$this->assertSame('conflict', ConflictFieldLogic::classify('A', 'B', 'C'));
	}

	public function testNoopWhenIncomingEqualsCurrent(): void
	{
		$this->assertSame('noop', ConflictFieldLogic::classify('A', 'B', 'B'));
	}

	public function testClassifyPatchSplitsFields(): void
	{
		$r = ConflictFieldLogic::classifyPatch(
			['name' => 'A', 'status' => 'lead', 'owner' => 'u1'],
			['name' => 'B', 'status' => 'lead', 'owner' => 'u2'],
			['name' => 'C', 'status' => 'active', 'owner' => 'u1'],
		);
		$this->assertSame(['name'], $r['conflicts']);
		$this->assertSame(['status'], $r['takeIncoming']);
		$this->assertSame(['owner'], $r['keepCurrent']);
	}

	public function testNullNormalisesToEmpty(): void
	{
		$this->assertSame('take_incoming', ConflictFieldLogic::classify(null, null, 'x'));
	}
}
