<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailMatchLogic;
use PHPUnit\Framework\TestCase;

/**
 * Shared-mail matching — From for inbound; To/CC for CC-shared-mailbox filing.
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

	public function testNormalizeAddressListTrimsDedupesAndLowercases(): void
	{
		$list = MailMatchLogic::normalizeAddressList([
			' Ada Lovelace <ada@acme.test> ',
			' ADA@acme.test ',
			'  ',
			'bob@partner.example',
		]);
		$this->assertSame(['ada@acme.test', 'bob@partner.example'], $list);
	}

	public function testNormalizeFromAddressStripsNbsp(): void
	{
		$nbsp = " \xc2\xa0 ada@acme.test \xc2\xa0 ";
		$this->assertSame('ada@acme.test', MailMatchLogic::normalizeFromAddress($nbsp));
	}

	public function testExactPersonMatchFromDisplayNameHeader(): void
	{
		$persons = [['companyId' => 3, 'email' => 'ada@acme.test']];
		$r = MailMatchLogic::match(null, 'Ada <ada@acme.test>', $persons);
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

	public function testCcSharedMailboxFilesUniqueCustomerOnTo(): void
	{
		// You write From yourself, To customer, CC shared — From is team.
		$persons = [
			['companyId' => 1, 'email' => 'you@office.example'],
			['companyId' => 2, 'email' => 'buyer@customer.example'],
		];
		$exclude = ['you@office.example', 'shared@office.example'];
		$r = MailMatchLogic::match(
			null,
			'you@office.example',
			$persons,
			$exclude,
			['buyer@customer.example', 'shared@office.example'],
		);
		$this->assertSame('recipient_email', $r['how']);
		$this->assertSame(2, $r['companyId']);
	}

	public function testCcSharedMailboxFilesUniqueCustomerDomainOnTo(): void
	{
		$persons = [
			['companyId' => 2, 'email' => 'ops@customer.example'],
		];
		$r = MailMatchLogic::match(
			null,
			'you@office.example',
			$persons,
			['you@office.example', 'shared@office.example'],
			['ceo@customer.example', 'shared@office.example'],
		);
		$this->assertSame('recipient_domain', $r['how']);
		$this->assertSame(2, $r['companyId']);
	}

	public function testTwoCustomerCompaniesOnToCcStayAmbiguous(): void
	{
		$persons = [
			['companyId' => 10, 'email' => 'a@acme.example'],
			['companyId' => 20, 'email' => 'b@beta.example'],
		];
		$r = MailMatchLogic::match(
			null,
			'you@office.example',
			$persons,
			['you@office.example', 'shared@office.example'],
			['a@acme.example', 'b@beta.example', 'shared@office.example'],
		);
		$this->assertSame('ambiguous', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testTeamFromWithoutCustomerRecipientStaysUnmatched(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'you@office.example'],
			['companyId' => 2, 'email' => 'buyer@customer.example'],
		];
		$r = MailMatchLogic::match(
			null,
			'you@office.example',
			$persons,
			['you@office.example', 'shared@office.example'],
			['shared@office.example', 'colleague@office.example'],
		);
		$this->assertSame('unmatched', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testInboundCustomerFromStillWinsOverRecipients(): void
	{
		$persons = [
			['companyId' => 2, 'email' => 'buyer@customer.example'],
			['companyId' => 9, 'email' => 'other@elsewhere.example'],
		];
		$r = MailMatchLogic::match(
			null,
			'buyer@customer.example',
			$persons,
			['shared@office.example'],
			['shared@office.example', 'other@elsewhere.example'],
		);
		$this->assertSame('person_email', $r['how']);
		$this->assertSame(2, $r['companyId']);
	}

	public function testSharedMailboxAddressAsFromFallsThroughToRecipients(): void
	{
		$persons = [['companyId' => 9, 'email' => 'buyer@acme.example']];
		$r = MailMatchLogic::match(
			null,
			'shared@office.example',
			$persons,
			['shared@office.example'],
			['buyer@acme.example'],
		);
		$this->assertSame('recipient_email', $r['how']);
		$this->assertSame(9, $r['companyId']);
	}

	public function testAmbiguousPersonEmailAcrossCompaniesIsUnmatched(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'shared-contact@acme.test'],
			['companyId' => 2, 'email' => 'shared-contact@acme.test'],
		];
		$r = MailMatchLogic::match(null, 'shared-contact@acme.test', $persons);
		$this->assertSame('ambiguous', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testExcludeEmailsDoNotBlockUnrelatedCustomerDomain(): void
	{
		$persons = [['companyId' => 7, 'email' => 'ops@partner.example']];
		$r = MailMatchLogic::match(
			null,
			'ceo@partner.example',
			$persons,
			['you@office.example', 'shared@office.example'],
		);
		$this->assertSame('domain', $r['how']);
		$this->assertSame(7, $r['companyId']);
	}

	public function testManualOverrideBeatsTeamExclusion(): void
	{
		$r = MailMatchLogic::match(42, 'you@office.example', [], ['you@office.example']);
		$this->assertSame('manual', $r['how']);
		$this->assertSame(42, $r['companyId']);
	}

	public function testForwardedMailMatchesOriginalFromHint(): void
	{
		$persons = [['companyId' => 5, 'email' => 'buyer@acme.example']];
		$r = MailMatchLogic::match(
			null,
			'you@office.example',
			$persons,
			['you@office.example', 'shared@office.example'],
			['shared@office.example'],
			['buyer@acme.example'],
		);
		$this->assertSame('forward_from', $r['how']);
		$this->assertSame(5, $r['companyId']);
	}

	public function testForwardedMailMatchesOriginalDomainHint(): void
	{
		$persons = [['companyId' => 5, 'email' => 'ops@acme.example']];
		$r = MailMatchLogic::match(
			null,
			'you@office.example',
			$persons,
			['you@office.example', 'shared@office.example'],
			['shared@office.example'],
			['ceo@acme.example'],
		);
		$this->assertSame('forward_domain', $r['how']);
		$this->assertSame(5, $r['companyId']);
	}

	public function testTwoOriginalFromHintsStayAmbiguous(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'a@one.example'],
			['companyId' => 2, 'email' => 'b@two.example'],
		];
		$r = MailMatchLogic::match(
			null,
			'you@office.example',
			$persons,
			['you@office.example', 'shared@office.example'],
			['shared@office.example'],
			['a@one.example', 'b@two.example'],
		);
		$this->assertSame('ambiguous', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testTeamDomainsIgnoreFreemailMemberAddresses(): void
	{
		$lookup = MailMatchLogic::emailLookup(['you@gmail.com', 'shared@office.example']);
		$domains = MailMatchLogic::teamDomainsFromLookup($lookup);
		$this->assertArrayNotHasKey('gmail.com', $domains);
		$this->assertArrayHasKey('office.example', $domains);
	}

	public function testTeamDomainFromDoesNotPersonEmailMatchEvenWhenListed(): void
	{
		$persons = [
			['companyId' => 18, 'email' => 'alex@software-by-design.de'],
			['companyId' => 26, 'email' => 'gattinger@banss.at'],
		];
		$exclude = ['support@software-by-design.de', 'info@software-by-design.de'];
		$r = MailMatchLogic::match(
			null,
			'alex@software-by-design.de',
			$persons,
			$exclude,
			['support@software-by-design.de'],
			[],
		);
		$this->assertSame('unmatched', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testTeamForwarderPersonContactDoesNotBeatForwardHints(): void
	{
		$persons = [
			['companyId' => 18, 'email' => 'alex@software-by-design.de'],
			['companyId' => 26, 'email' => 'gattinger@banss.at'],
		];
		$exclude = ['support@software-by-design.de'];
		$r = MailMatchLogic::match(
			null,
			'alex@software-by-design.de',
			$persons,
			$exclude,
			['support@software-by-design.de'],
			['gattinger@banss.at'],
		);
		$this->assertSame('forward_from', $r['how']);
		$this->assertSame(26, $r['companyId']);
	}
}
