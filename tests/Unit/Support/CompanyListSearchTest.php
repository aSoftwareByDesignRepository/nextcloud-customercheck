<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\CompanyListSearch;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class CompanyListSearchTest extends TestCase
{
	public function testNormalizeTrimsAndCapsLength(): void
	{
		$this->assertSame('', CompanyListSearch::normalize('   '));
		$this->assertSame('Acme', CompanyListSearch::normalize("  Acme \n"));
		$this->assertSame(100, CompanyListSearch::MAX_LEN);
		$long = str_repeat('a', 140);
		$normalized = CompanyListSearch::normalize($long);
		$this->assertSame(100, mb_strlen($normalized));
		$this->assertSame(str_repeat('a', 100), $normalized);
	}

	public function testMatchingStatusCodesUsesLabelsAndCodes(): void
	{
		$labels = [
			'lead' => 'Lead',
			'active' => 'Aktiv',
			'paused' => 'Paused',
			'churned' => 'Churned',
		];
		$this->assertSame(['active'], CompanyListSearch::matchingStatusCodes('aktiv', static fn (string $c): string => $labels[$c]));
		$this->assertSame(['lead'], CompanyListSearch::matchingStatusCodes('lead', static fn (string $c): string => $labels[$c]));
		$this->assertSame([], CompanyListSearch::matchingStatusCodes('zzz', static fn (string $c): string => $labels[$c]));
	}

	public function testMatchingOwnerIdsScopesToDisplayNameAndUid(): void
	{
		$owners = [
			['id' => 'alice', 'displayName' => 'Alice Owner'],
			['id' => 'bob', 'displayName' => 'Bob'],
		];
		$this->assertSame(['alice'], CompanyListSearch::matchingOwnerIds('alice owner', $owners));
		$this->assertSame(['bob'], CompanyListSearch::matchingOwnerIds('bob', $owners));
		$this->assertSame([], CompanyListSearch::matchingOwnerIds('carol', $owners));
	}

	public function testWantsOpenCallbacksRequiresClearKeyword(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$this->assertFalse(CompanyListSearch::wantsOpenCallbacks('ca', $l10n));
		$this->assertTrue(CompanyListSearch::wantsOpenCallbacks('callback', $l10n));
		$this->assertTrue(CompanyListSearch::wantsOpenCallbacks('Callbacks', $l10n));
		$this->assertFalse(CompanyListSearch::wantsOpenCallbacks('acme', $l10n));
	}
}
