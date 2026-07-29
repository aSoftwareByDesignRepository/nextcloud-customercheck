<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * PSD §11 S1 — primary promotion must lock the company row before mutating
 * persons, so concurrent writers cannot leave two primaries.
 */
final class PersonPrimaryLockTest extends TestCase
{
	public function testCreatePrimaryLocksCompanyBeforeClearAndInsert(): void
	{
		$order = [];

		$companyService = $this->createMock(CompanyService::class);
		$companyService->expects($this->once())->method('get');
		$companyService->expects($this->once())
			->method('lockRow')
			->with(42)
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'lock';
			});

		$personMapper = $this->createMock(PersonMapper::class);
		$personMapper->expects($this->once())
			->method('clearPrimaryFor')
			->with(42, null)
			->willReturnCallback(static function () use (&$order): int {
				$order[] = 'clear';
				return 1;
			});
		$personMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (Person $person) use (&$order): Person {
				$order[] = 'insert';
				$person->setId(7);
				return $person;
			});

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction')
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'begin';
			});
		$db->expects($this->once())->method('commit')
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'commit';
			});

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25T12:00:00+00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$service = new PersonService(
			$personMapper,
			$companyService,
			$timeline,
			$db,
			$time,
			$l10n,
		);

		$stored = $service->create(42, [
			'name' => 'Ada',
			'email' => 'ada@example.com',
			'isPrimary' => true,
		], 'admin');

		$this->assertSame(7, (int)$stored->getId());
		$this->assertSame(['begin', 'lock', 'clear', 'insert', 'commit'], $order);
	}
}
