<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ReviewMatchLogic;
use PHPUnit\Framework\TestCase;

final class ReviewMatchLogicTest extends TestCase
{
	public function testExactPersonEmailScores100(): void
	{
		$companies = [[
			'id' => 3,
			'name' => 'Acme',
			'emails' => ['ada@acme.test'],
			'domains' => ['acme.test'],
		]];
		$r = ReviewMatchLogic::suggest('ada@acme.test', null, $companies);
		$this->assertCount(1, $r);
		$this->assertSame(100, $r[0]['score']);
		$this->assertSame('person_email_exact', $r[0]['reasonCode']);
		$this->assertSame(3, $r[0]['companyId']);
		$this->assertSame('Acme', $r[0]['companyName']);
	}

	public function testExactPersonEmailFromDisplayHeaderScores100(): void
	{
		$companies = [[
			'id' => 3,
			'name' => 'Acme',
			'emails' => ['ada@acme.test'],
			'domains' => ['acme.test'],
		]];
		$r = ReviewMatchLogic::suggest('Ada Lovelace <ada@acme.test>', null, $companies);
		$this->assertSame(100, $r[0]['score']);
		$this->assertSame('person_email_exact', $r[0]['reasonCode']);
	}

	public function testDomainUniqueScores95(): void
	{
		$companies = [[
			'id' => 7,
			'name' => 'Partner',
			'emails' => ['a@partner.example'],
			'domains' => ['partner.example'],
		]];
		$r = ReviewMatchLogic::suggest('ceo@partner.example', null, $companies);
		$this->assertSame(95, $r[0]['score']);
		$this->assertSame('domain_unique', $r[0]['reasonCode']);
	}

	public function testSharedDomainYieldsNoDomainUnique(): void
	{
		$companies = [
			['id' => 1, 'name' => 'A', 'emails' => [], 'domains' => ['shared.example']],
			['id' => 2, 'name' => 'B', 'emails' => [], 'domains' => ['shared.example']],
		];
		$r = ReviewMatchLogic::suggest('x@shared.example', null, $companies);
		$this->assertSame([], $r);
	}

	public function testFreemailNeverDomainMatched(): void
	{
		$companies = [[
			'id' => 1,
			'name' => 'Gmail Co',
			'emails' => ['a@gmail.com'],
			'domains' => ['gmail.com'],
		]];
		$r = ReviewMatchLogic::suggest('stranger@gmail.com', null, $companies);
		$this->assertSame([], $r);
	}

	public function testOrgNameUniqueScores70(): void
	{
		$companies = [[
			'id' => 9,
			'name' => 'Acme GmbH',
			'emails' => [],
			'domains' => [],
		]];
		$r = ReviewMatchLogic::suggest(null, 'Acme AG', $companies);
		$this->assertSame(70, $r[0]['score']);
		$this->assertSame('org_name_unique', $r[0]['reasonCode']);
		$this->assertSame('Acme GmbH', $r[0]['companyName']);
	}

	public function testArchivedCompaniesSkippedByDefaultButIncludedWhenRequested(): void
	{
		$companies = [[
			'id' => 1,
			'name' => 'Acme',
			'emails' => ['ada@acme.test'],
			'domains' => ['acme.test'],
			'archived' => true,
		]];
		$this->assertSame([], ReviewMatchLogic::suggest('ada@acme.test', 'Acme', $companies));
		$with = ReviewMatchLogic::suggest('ada@acme.test', null, $companies, true);
		$this->assertCount(1, $with);
		$this->assertSame(1, $with[0]['companyId']);
		$this->assertSame('Acme', $with[0]['companyName']);
	}

	public function testNormalizeStripsLegalSuffixes(): void
	{
		$this->assertSame('acme', ReviewMatchLogic::normalizeOrgName('Acme GmbH'));
		$this->assertSame('acme', ReviewMatchLogic::normalizeOrgName('ACME Inc.'));
	}

	public function testFuzzyMatchRequiresTokenLength(): void
	{
		$this->assertFalse(ReviewMatchLogic::fuzzyOrgMatch('ab', 'ac'));
		$this->assertTrue(ReviewMatchLogic::fuzzyOrgMatch('acme', 'acmee'));
	}
}
