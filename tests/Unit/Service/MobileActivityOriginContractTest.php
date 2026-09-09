<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MobileApiService;
use PHPUnit\Framework\TestCase;

/**
 * Companion activity creates must never accept client-supplied system/sequence origins.
 */
final class MobileActivityOriginContractTest extends TestCase
{
	public function testNormaliseActivityBodyForcesUserOrigin(): void
	{
		$out = MobileApiService::normaliseActivityBody([
			'companyId' => 1,
			'type' => 'call',
			'summary' => 'Ping',
			'dueOn' => '2026-08-30',
			'origin' => 'sequence_quoted',
		]);
		$this->assertSame('user', $out['origin']);
		$this->assertSame('Ping', $out['title']);
		$this->assertSame('2026-08-30', $out['dueDate']);
		$this->assertArrayNotHasKey('summary', $out);
	}

	public function testMissingOriginStillForcedToUser(): void
	{
		$out = MobileApiService::normaliseActivityBody([
			'title' => 'Hi',
			'dueDate' => '2026-08-30',
		]);
		$this->assertSame('user', $out['origin']);
	}
}
