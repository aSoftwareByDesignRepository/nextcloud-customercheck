<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\CompanyDuplicateLogic;
use PHPUnit\Framework\TestCase;

final class CompanyDuplicateLogicTest extends TestCase
{
	public function testNameKeyTrimsAndLowercases(): void
	{
		$this->assertSame('acme gmbh', CompanyDuplicateLogic::nameKey('  Acme GmbH  '));
		$this->assertSame('', CompanyDuplicateLogic::nameKey('   '));
	}

	public function testDuplicateKeysFromNamesIsCaseInsensitive(): void
	{
		$keys = CompanyDuplicateLogic::duplicateKeysFromNames([
			'Acme GmbH',
			'acme gmbh',
			'Beta AG',
			'',
			'  ',
		]);
		$this->assertTrue(isset($keys['acme gmbh']));
		$this->assertArrayNotHasKey('beta ag', $keys);
		$this->assertCount(1, $keys);
	}

	public function testIsDuplicateName(): void
	{
		$keys = CompanyDuplicateLogic::duplicateKeysFromNames(['One', 'one', 'Two']);
		$this->assertTrue(CompanyDuplicateLogic::isDuplicateName('ONE', $keys));
		$this->assertFalse(CompanyDuplicateLogic::isDuplicateName('Two', $keys));
		$this->assertFalse(CompanyDuplicateLogic::isDuplicateName('', $keys));
	}
}
