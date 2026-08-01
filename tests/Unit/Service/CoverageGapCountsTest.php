<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\OooCoverService;
use OCA\CustomerCheck\Service\QuotaService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Team-board coverage gaps — buying-role CoverageLogic (§12.2), O(companies+people).
 */
final class CoverageGapCountsTest extends TestCase
{
	public function testCountsKeyAccountsMissingBuyingRoles(): void
	{
		$keyGap = new Company();
		$keyGap->setId(1);
		$keyGap->setAccountOwnerUserId('alex');
		$keyGap->setKamTier('key');
		$keyGap->setKamTierLock(0);

		$keyCovered = new Company();
		$keyCovered->setId(2);
		$keyCovered->setAccountOwnerUserId('alex');
		$keyCovered->setKamTier('key');
		$keyCovered->setKamTierLock(0);

		$standard = new Company();
		$standard->setId(3);
		$standard->setAccountOwnerUserId('sam');
		$standard->setKamTier('standard');
		$standard->setKamTierLock(0);

		$unowned = new Company();
		$unowned->setId(4);
		$unowned->setAccountOwnerUserId('');
		$unowned->setKamTier('key');
		$unowned->setKamTierLock(0);

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('search')->with(['workspaceId' => 7], 500)->willReturn([
			$keyGap,
			$keyCovered,
			$standard,
			$unowned,
		]);

		$people = $this->createMock(PersonMapper::class);
		$people->method('findGroupedByCompanyIds')->willReturn([
			1 => [],
			2 => [
				$this->person(10, 2, 'economic_buyer'),
				$this->person(11, 2, 'champion'),
				$this->person(12, 2, 'day_to_day'),
			],
			3 => [],
			4 => [],
		]);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new CoverageService(
			$mapper,
			$people,
			$agreements,
			$this->createMock(CompanyService::class),
			$this->createMock(OooCoverService::class),
			new DisplayLabelService($l10n),
			$this->createMock(QuotaService::class),
			$time,
			$this->createMock(IDBConnection::class),
			$l10n,
		);

		$counts = $svc->gapCountsByAccountOwner(7);
		$this->assertSame(['alex' => 1], $counts);
	}

	public function testEmptyWorkspaceReturnsEmptyMap(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('search')->willReturn([]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new CoverageService(
			$mapper,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(CompanyService::class),
			$this->createMock(OooCoverService::class),
			new DisplayLabelService($l10n),
			$this->createMock(QuotaService::class),
			$time,
			$this->createMock(IDBConnection::class),
			$l10n,
		);

		$this->assertSame([], $svc->gapCountsByAccountOwner(1));
	}

	private function person(int $id, int $companyId, string $role): Person
	{
		$p = new Person();
		$p->setId($id);
		$p->setCompanyId($companyId);
		$p->setName('P' . $id);
		$p->setBuyingRole($role);
		$p->setStatus('active');

		return $p;
	}
}
