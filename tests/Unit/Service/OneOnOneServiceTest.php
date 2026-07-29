<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\OneOnOneService;
use OCA\CustomerCheck\Service\PromiseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class OneOnOneServiceTest extends TestCase
{
	public function testPrepRequiresReportUser(): void
	{
		$svc = $this->service();
		$this->expectException(ValidationException::class);
		$svc->prep(1, 'mgr', '  ');
	}

	public function testPrepReturnsChaseAndPromisesForReport(): void
	{
		$chase = $this->createMock(ChaseService::class);
		$chase->method('myChase')->willReturn([
			['title' => 'Acme quote', 'primaryReason' => 'no_next_action'],
		]);
		$promises = $this->createMock(PromiseService::class);
		$promises->method('dueSoon')->willReturn([
			['title' => 'Send deck', 'ownerUserId' => 'alex', 'dueOn' => '2026-07-20'],
			['title' => 'Other', 'ownerUserId' => 'sam', 'dueOn' => '2026-07-21'],
		]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new OneOnOneService($db, $chase, $promises, $time, $l10n);
		$prep = $svc->prep(9, 'mgr', 'alex');
		$this->assertSame('alex', $prep['reportUserId']);
		$this->assertCount(1, $prep['chase']);
		$this->assertCount(1, $prep['promisesDue']);
		$this->assertSame('Send deck', $prep['promisesDue'][0]['title']);
		$this->assertNull($prep['notes']);
	}

	private function service(): OneOnOneService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return new OneOnOneService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ChaseService::class),
			$this->createMock(PromiseService::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);
	}
}
