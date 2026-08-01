<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Support\TicketCompanyLink;
use PHPUnit\Framework\TestCase;

final class TicketCompanyLinkTest extends TestCase
{
	public function testResolveUsesTcCustomerIdWhenCompanyIdNull(): void
	{
		$linked = new Company();
		$linked->setId(42);
		$linked->setName('Acme');
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->expects($this->once())->method('findByTcCustomerId')->with(77)->willReturn($linked);
		$names = [];
		$id = TicketCompanyLink::resolveCompanyId(
			['id' => 1, 'companyId' => null, 'customerId' => 77, 'subject' => 'Wait'],
			$mapper,
			$names,
		);
		$this->assertSame(42, $id);
		$this->assertSame('Acme', $names[42]);
	}

	public function testBelongsToWorkspaceViaTcIndex(): void
	{
		$c = new Company();
		$c->setId(10);
		$c->setTcCustomerId(55);
		$index = TicketCompanyLink::tcIndexForWorkspace([$c]);
		$this->assertSame([55 => 10], $index);
		$this->assertTrue(TicketCompanyLink::belongsToWorkspace(
			['companyId' => null, 'customerId' => 55],
			$index,
			[10 => true],
		));
		$this->assertFalse(TicketCompanyLink::belongsToWorkspace(
			['companyId' => null, 'customerId' => 99],
			$index,
			[10 => true],
		));
		$this->assertFalse(TicketCompanyLink::belongsToWorkspace(
			['companyId' => 99, 'customerId' => 55],
			$index,
			[10 => true],
		));
	}
}
