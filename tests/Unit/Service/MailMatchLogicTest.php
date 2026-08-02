<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailMatchLogic;
use PHPUnit\Framework\TestCase;

/**
 * Shared-mail matching — product §14.2 / §18.3 (unit, no DB).
 */
class MailMatchLogicTest extends TestCase
{
	public function testIsFreemailNormalisesCaseAndWhitespace(): void
	{
		$this->assertTrue(MailMatchLogic::isFreemail(' Gmail.COM '));
		$this->assertFalse(MailMatchLogic::isFreemail('acme.example'));
	}

	public function testDomainOfRejectsMalformed(): void
	{
		$this->assertNull(MailMatchLogic::domainOf(''));
		$this->assertNull(MailMatchLogic::domainOf('no-at'));
		$this->assertNull(MailMatchLogic::domainOf('@nodomain'));
		$this->assertNull(MailMatchLogic::domainOf('trailing@'));
		$this->assertSame('acme.example', MailMatchLogic::domainOf(' Ada@Acme.Example '));
	}

	public function testDomainOfTrailingAtUsesLastAt(): void
	{
		// local-part may contain @ in quoted forms; we take the last @.
		$this->assertSame('acme.example', MailMatchLogic::domainOf('weird@part@acme.example'));
	}

	public function testManualZeroIsNotManualOverride(): void
	{
		$persons = [['companyId' => 9, 'email' => 'ada@acme.test']];
		$r = MailMatchLogic::match(0, 'ada@acme.test', $persons);
		$this->assertSame('person_email', $r['how']);
		$this->assertSame(9, $r['companyId']);
	}

	public function testManualNegativeFallsThrough(): void
	{
		$persons = [['companyId' => 9, 'email' => 'ada@acme.test']];
		$r = MailMatchLogic::match(-1, 'ada@acme.test', $persons);
		$this->assertSame('person_email', $r['how']);
	}

	public function testManualPositiveWinsEvenWhenFromUnknown(): void
	{
		$r = MailMatchLogic::match(42, 'nobody@elsewhere.test', []);
		$this->assertSame('manual', $r['how']);
		$this->assertSame(42, $r['companyId']);
	}

	public function testBlankOrAtLessFromIsUnmatched(): void
	{
		$this->assertSame('unmatched', MailMatchLogic::match(null, '   ', [])['how']);
		$this->assertSame('unmatched', MailMatchLogic::match(null, 'not-an-email', [])['how']);
	}

	public function testExactPersonMatchNormalisesWhitespaceAndCase(): void
	{
		$persons = [['companyId' => 3, 'email' => ' Ada@Acme.TEST ']];
		$r = MailMatchLogic::match(null, ' ada@acme.test ', $persons);
		$this->assertSame('person_email', $r['how']);
		$this->assertSame(3, $r['companyId']);
	}

	public function testEmptyPersonEmailSkipped(): void
	{
		$persons = [
			['companyId' => 1, 'email' => ''],
			['companyId' => 2, 'email' => 'ada@acme.test'],
		];
		$r = MailMatchLogic::match(null, 'ada@acme.test', $persons);
		$this->assertSame(2, $r['companyId']);
	}

	public function testFreemailNeverDomainMatched(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'a@gmail.com'],
			['companyId' => 2, 'email' => 'b@acme.test'],
		];
		$r = MailMatchLogic::match(null, 'stranger@gmail.com', $persons);
		$this->assertSame('unmatched', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testUniqueDomainMatch(): void
	{
		$persons = [
			['companyId' => 7, 'email' => 'a@partner.example'],
			['companyId' => 8, 'email' => 'b@other.example'],
		];
		$r = MailMatchLogic::match(null, 'ceo@partner.example', $persons);
		$this->assertSame('domain', $r['how']);
		$this->assertSame(7, $r['companyId']);
	}

	public function testAmbiguousDomainUnmatched(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'a@acme.test'],
			['companyId' => 2, 'email' => 'b@acme.test'],
		];
		$r = MailMatchLogic::match(null, 'x@acme.test', $persons);
		$this->assertSame('unmatched', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testSameCompanyTwoPeopleStillUniqueDomain(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'a@acme.test'],
			['companyId' => 1, 'email' => 'b@acme.test'],
		];
		$r = MailMatchLogic::match(null, 'x@acme.test', $persons);
		$this->assertSame('domain', $r['how']);
		$this->assertSame(1, $r['companyId']);
	}

	public function testAuthFailThreshold(): void
	{
		$this->assertSame('ok', MailMatchLogic::nextAuthStatus(0));
		$this->assertSame('ok', MailMatchLogic::nextAuthStatus(2));
		$this->assertSame('error', MailMatchLogic::nextAuthStatus(3));
		$this->assertSame('error', MailMatchLogic::nextAuthStatus(99));
	}
}
