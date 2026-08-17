<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\CompanyNotesPresenter;
use PHPUnit\Framework\TestCase;

final class CompanyNotesPresenterTest extends TestCase
{
	public function testEmptyAndNullAreBlank(): void
	{
		$this->assertSame('', CompanyNotesPresenter::publicHeadline(null));
		$this->assertSame('', CompanyNotesPresenter::publicHeadline(''));
		$this->assertSame('', CompanyNotesPresenter::publicHeadline("  \n\t  "));
	}

	public function testWebsiteOrderFingerprintIsHidden(): void
	{
		$raw = 'website-order f47aea259c4646dab904b06b | starts 2026-08-10 | term 12m | lang en';
		$this->assertTrue(CompanyNotesPresenter::isMachineLine($raw));
		$this->assertSame('', CompanyNotesPresenter::publicHeadline($raw));
	}

	public function testWebsiteOrderFingerprintWithVatIsHidden(): void
	{
		$raw = 'website-order abc-1 | starts 2026-08-10 | term 12m | lang de | VAT DE123456789';
		$this->assertSame('', CompanyNotesPresenter::publicHeadline($raw));
	}

	public function testImportServiceWebsiteOrderLineIsHidden(): void
	{
		$this->assertTrue(CompanyNotesPresenter::isMachineLine('Website order ORD-99'));
		$this->assertSame('', CompanyNotesPresenter::publicHeadline("Website order ORD-99\n"));
	}

	public function testHumanNotesSurvive(): void
	{
		$this->assertSame('VIP — call after 10.', CompanyNotesPresenter::publicHeadline('VIP — call after 10.'));
		$this->assertFalse(CompanyNotesPresenter::isMachineLine('Please confirm the website-order is delayed'));
		$this->assertSame(
			'Please confirm the website-order is delayed',
			CompanyNotesPresenter::publicHeadline('Please confirm the website-order is delayed'),
		);
	}

	public function testMixedLinesKeepOnlyHumanCopy(): void
	{
		$raw = "VIP customer\nwebsite-order f47aea259c4646dab904b06b | starts 2026-08-10 | term 12m | lang en\nCall on Monday.";
		$this->assertSame("VIP customer\nCall on Monday.", CompanyNotesPresenter::publicHeadline($raw));
	}

	public function testCrlfMachineLineIsHidden(): void
	{
		$raw = "website-order abc | starts 2026-01-01 | term 12m | lang en\r\nKeep me";
		$this->assertSame('Keep me', CompanyNotesPresenter::publicHeadline($raw));
	}

	public function testStartsTermLangTriadWithoutPrefixIsHidden(): void
	{
		$raw = 'starts 2026-08-10 | term 12m | lang en';
		$this->assertTrue(CompanyNotesPresenter::isMachineLine($raw));
		$this->assertSame('', CompanyNotesPresenter::publicHeadline($raw));
	}

	public function testEmptyLineIsNotMachine(): void
	{
		$this->assertFalse(CompanyNotesPresenter::isMachineLine(''));
		$this->assertFalse(CompanyNotesPresenter::isMachineLine('   '));
	}
}
