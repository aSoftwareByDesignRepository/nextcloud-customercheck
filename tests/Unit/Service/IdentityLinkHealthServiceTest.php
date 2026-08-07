<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IdentityLinkHealthServiceTest extends TestCase
{
	public function testClassifyBrokenWhenPcMissing(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('projectcheckAvailable')->willReturn(true);
		$facades->method('getProjectCheckCustomer')->with(42)->willReturn(null);
		$facades->method('ticketcheckAvailable')->willReturn(false);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => $s);

		$svc = new IdentityLinkHealthService(
			$mapper,
			$facades,
			$this->createMock(IConfig::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$company = new Company();
		$company->setPcCustomerId(42);
		$result = $svc->classifyCompany($company);
		$this->assertSame(IdentityLinkHealthService::STATE_BROKEN, $result['pc']['state']);
	}

	public function testClassifyNotLinkedWhenNull(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('projectcheckAvailable')->willReturn(true);
		$facades->method('ticketcheckAvailable')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$svc = new IdentityLinkHealthService(
			$mapper,
			$facades,
			$this->createMock(IConfig::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$company = new Company();
		$result = $svc->classifyCompany($company);
		$this->assertSame(IdentityLinkHealthService::STATE_NOT_LINKED, $result['pc']['state']);
		$this->assertSame(IdentityLinkHealthService::STATE_NOT_LINKED, $result['tc']['state']);
	}

	public function testAdminHealthCountsIntegers(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('listLinkedPcCustomerIds')->willReturn([1, 2, 3]);
		$mapper->method('listLinkedTcCustomerIds')->willReturn([9]);
		$mapper->method('countSearch')->willReturn(10);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('projectcheckAvailable')->willReturn(true);
		$facades->method('ticketcheckAvailable')->willReturn(true);
		$facades->method('projectCheckCustomerIdSet')->willReturn([1 => true, 2 => true]);
		$facades->method('ticketCheckCustomerIdSet')->willReturn([9 => true]);
		$facades->method('maintenanceIdentityCounts')->willReturn([
			'mnLinkedPc' => 1,
			'mnLinkedCrm' => 2,
			'mnUnlinked' => 3,
		]);

		$l10n = $this->createMock(IL10N::class);
		$svc = new IdentityLinkHealthService(
			$mapper,
			$facades,
			$this->createMock(IConfig::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$counts = $svc->adminHealthCounts();
		$this->assertSame(2, $counts['pcLinked']);
		$this->assertSame(1, $counts['pcBroken']);
		$this->assertSame(7, $counts['pcUnlinked']);
		$this->assertSame(1, $counts['tcLinked']);
		$this->assertSame(0, $counts['tcBroken']);
		$this->assertSame(9, $counts['tcUnlinked']);
		$this->assertSame(1, $counts['mnLinkedPc']);
		$this->assertSame(2, $counts['mnLinkedCrm']);
		$this->assertSame(3, $counts['mnUnlinked']);
	}
}
