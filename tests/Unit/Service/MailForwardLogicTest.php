<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailForwardLogic;
use OCA\CustomerCheck\Service\MailMatchLogic;
use PHPUnit\Framework\TestCase;

/**
 * Forwarded-mail body parsing — Outlook Von/Gesendet/An and nested team quotes.
 */
final class MailForwardLogicTest extends TestCase
{
	/** @var list<string> */
	private array $teamExclude = [
		'alex@software-by-design.de',
		'info@software-by-design.de',
	];

	public function testOutlookForwardBlockFindsCustomerFrom(): void
	{
		$body = <<<'TEXT'
Von: Daniel Gattinger - Banss <Gattinger@banss.at>
Gesendet: Donnerstag, 14. August 2026 09:30
An: Alexander Mäule <alex@software-by-design.de>
Betreff: AW: AW: AW: Angebot ArbeitszeitCheck Lizenz

Sehr geehrter Herr Mäule,
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testOutlookBlockUsesAnWhenVonIsTeam(): void
	{
		$body = <<<'TEXT'
Von: Alexander Mäule <alex@software-by-design.de>
Gesendet: Dienstag, 11. August 2026 20:46
An: Daniel Gattinger - Banss <Gattinger@banss.at>
Betreff: Re: AW: AW: Angebot ArbeitszeitCheck Lizenz
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testOutlookVonAnWithoutGesendetLine(): void
	{
		$body = <<<'TEXT'
Von: Daniel Gattinger - Banss <Gattinger@banss.at>
An: Alexander Mäule <alex@software-by-design.de>
Betreff: AW: Angebot ArbeitszeitCheck Lizenz
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testOutlookVonAnTeamOnVonUsesCustomerOnAnWithoutGesendet(): void
	{
		$body = <<<'TEXT'
Von: Alexander Mäule <alex@software-by-design.de>
An: Daniel Gattinger - Banss <Gattinger@banss.at>
Betreff: WG: Angebot
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testForwardMarkerSkipsTeamFromAndFindsCustomerLater(): void
	{
		$body = <<<'TEXT'
---------- Forwarded message ----------
From: Alexander Mäule <alex@software-by-design.de>
To: info@software-by-design.de

From: Daniel Gattinger - Banss <Gattinger@banss.at>
To: Alexander Mäule <alex@software-by-design.de>
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testForwardMarkerSkipsTeamFromWhenOutlookBlocksDoNotApply(): void
	{
		$body = <<<'TEXT'
---------- Forwarded message ----------
From: Alexander Mäule <alex@software-by-design.de>
Subject: team wrapper

From: Daniel Gattinger - Banss <Gattinger@banss.at>
Subject: customer thread
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testOutlookForwardMatchesKnownContactAtIngestAndRematch(): void
	{
		$body = <<<'TEXT'
Von: Daniel Gattinger - Banss <Gattinger@banss.at>
Gesendet: Donnerstag, 14. August 2026 09:30
An: Alexander Mäule <alex@software-by-design.de>
Betreff: AW: AW: AW: Angebot ArbeitszeitCheck Lizenz
TEXT;
		$persons = [['companyId' => 99, 'email' => 'gattinger@banss.at']];
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$r = MailMatchLogic::match(
			null,
			'alex@software-by-design.de',
			$persons,
			$this->teamExclude,
			['info@software-by-design.de'],
			$hints,
		);
		$this->assertSame('forward_from', $r['how']);
		$this->assertSame(99, $r['companyId']);
	}

	public function testNeedsBodyProbeWhenTeamForwardsToSharedOnly(): void
	{
		$this->assertTrue(MailForwardLogic::needsBodyProbe(
			'alex@software-by-design.de',
			['info@software-by-design.de'],
			[],
			'AW: AW: AW: Angebot ArbeitszeitCheck Lizenz',
			$this->teamExclude,
		));
	}

	public function testHintsForRematchUsesPreviewWhenStoredHintsEmpty(): void
	{
		$body = <<<'TEXT'
Von: Daniel Gattinger - Banss <Gattinger@banss.at>
Gesendet: Donnerstag, 14. August 2026 09:30
An: Alexander Mäule <alex@software-by-design.de>
TEXT;
		$hints = MailForwardLogic::hintsForRematch(null, $body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testHintsForRematchPrefersStoredHintsOverPreview(): void
	{
		$stored = json_encode(['buyer@acme.example'], JSON_THROW_ON_ERROR);
		$body = <<<'TEXT'
Von: Daniel Gattinger - Banss <Gattinger@banss.at>
Gesendet: Donnerstag, 14. August 2026 09:30
An: Alexander Mäule <alex@software-by-design.de>
TEXT;
		$hints = MailForwardLogic::hintsForRematch($stored, $body, $this->teamExclude);
		$this->assertSame(['buyer@acme.example'], $hints);
	}

	public function testBodyHintDropsTeamOnlyOutlookBlock(): void
	{
		$body = <<<'TEXT'
Von: Alexander Mäule <alex@software-by-design.de>
Gesendet: Donnerstag, 14. August 2026 09:30
An: info@software-by-design.de
Betreff: Intern
TEXT;
		$this->assertSame([], MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude));
	}

	public function testBareSignatureFromLineIsIgnoredWithoutForwardContext(): void
	{
		$body = "Mit freundlichen Grüßen\nDaniel Gattinger\nTel. 123\n";
		$this->assertSame([], MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude));
	}

	public function testRedirectHeadersUseReplyToNotResentFrom(): void
	{
		$hints = MailForwardLogic::hintsFromRedirectHeaders(null, 'Daniel Gattinger <gattinger@banss.at>');
		$this->assertSame(['gattinger@banss.at'], $hints);

		$legacy = MailForwardLogic::hintsFromResentHeaders('alex@software-by-design.de', null);
		$this->assertSame([], $legacy, 'Resent-From is the redirector — never a customer hint');

		$withX = MailForwardLogic::hintsFromRedirectHeaders('buyer@acme.example', 'alex@software-by-design.de');
		$this->assertEqualsCanonicalizing(['buyer@acme.example', 'alex@software-by-design.de'], $withX);
	}

	public function testResentFromTeamDoesNotSkipBodyProbeWhenReplyToEmpty(): void
	{
		$teamResentOnly = MailForwardLogic::hintsFromRedirectHeaders(null, null);
		$this->assertTrue(MailForwardLogic::needsBodyProbe(
			'alex@software-by-design.de',
			['info@software-by-design.de'],
			[],
			'WG: Angebot',
			$this->teamExclude,
			$teamResentOnly,
		));
	}

	public function testThunderbirdRedirectReplyToFilesCustomer(): void
	{
		$persons = [['companyId' => 99, 'email' => 'gattinger@banss.at']];
		$hints = MailForwardLogic::hintsFromRedirectHeaders(null, 'gattinger@banss.at');
		$r = MailMatchLogic::match(
			null,
			'alex@software-by-design.de',
			$persons,
			$this->teamExclude,
			['info@software-by-design.de'],
			$hints,
		);
		$this->assertSame('forward_from', $r['how']);
		$this->assertSame(99, $r['companyId']);
	}

	public function testThunderbirdForwardMarkerEightDashes(): void
	{
		$body = <<<'TEXT'
-------- Forwarded Message --------
From: Daniel Gattinger - Banss <Gattinger@banss.at>
To: Alexander Mäule <alex@software-by-design.de>
Subject: Angebot
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testRfcRedirectPreservesCustomerFromWithoutHints(): void
	{
		$persons = [['companyId' => 55, 'email' => 'gattinger@banss.at']];
		$r = MailMatchLogic::match(
			null,
			'gattinger@banss.at',
			$persons,
			$this->teamExclude,
			['info@software-by-design.de'],
			[],
		);
		$this->assertSame('person_email', $r['how']);
		$this->assertSame(55, $r['companyId']);
	}

	public function testNeedsBodyProbeSkipsWhenActionableReplyToPresent(): void
	{
		$this->assertFalse(MailForwardLogic::needsBodyProbe(
			'alex@software-by-design.de',
			['info@software-by-design.de'],
			[],
			'WG: Angebot',
			$this->teamExclude,
			['gattinger@banss.at'],
		));
	}

	public function testThunderbirdForwardBlockFindsEmailInSignatureWhenFromHasNoAddress(): void
	{
		$body = <<<'TEXT'
-------- Forwarded Message --------
Subject: 	AW: AW: AW: Angebot ArbeitszeitCheck Lizenz
Date: 	Fri, 14 Aug 2026 07:30:00 +0000
From: 	Daniel Gattinger - Banss
To: 	Alexander Mäule

Sehr geehrter Herr Mäule,

vielen Dank für Ihre Nachricht.

Mit freundlichen Grüßen

Daniel Gattinger

Tel.: +43-(0)7242 67703

gattinger@banss.at

*Von:*Alexander Mäule
*Gesendet:* Dienstag, 11. August 2026 20:46
*An:* Daniel Gattinger - Banss
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($body, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);
	}

	public function testCustomerEmailForReviewSuggestUsesForwardHintNotTeamEnvelope(): void
	{
		$body = <<<'TEXT'
-------- Forwarded Message --------
From: Daniel Gattinger - Banss
To: Alexander Mäule

gattinger@banss.at
TEXT;
		$email = MailForwardLogic::customerEmailForReviewSuggest(
			'alex@software-by-design.de',
			$body,
			null,
			$this->teamExclude,
		);
		$this->assertSame('gattinger@banss.at', $email);
	}

	public function testCustomerEmailForReviewSuggestSuppressesTeamEnvelopeWithoutHints(): void
	{
		$this->assertSame('', MailForwardLogic::customerEmailForReviewSuggest(
			'alex@software-by-design.de',
			null,
			null,
			$this->teamExclude,
		));
	}
}
