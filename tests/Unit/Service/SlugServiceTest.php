<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\SlugService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * PSD §4.9.1 / S13 — slug algorithm is the join key across the Check family.
 */
class SlugServiceTest extends TestCase
{
	public function testMuellerGmbhBecomesMuellerGmbh(): void
	{
		$this->assertSame('mueller-gmbh', SlugService::slugify('Müller GmbH'));
	}

	public function testSpacesAndPunctuationCollapse(): void
	{
		$this->assertSame('acme-corp', SlugService::slugify('  Acme   Corp!! '));
	}

	public function testShortNamePaddedWithCo(): void
	{
		$this->assertSame('ab-co', SlugService::slugify('AB'));
		$this->assertSame('a-co', SlugService::slugify('A'));
	}

	public function testLongNameTruncatedTo64(): void
	{
		$long = str_repeat('a', 80);
		$slug = SlugService::slugify($long);
		$this->assertLessThanOrEqual(64, strlen($slug));
		$this->assertTrue(SlugService::isValid($slug));
	}

	public function testEmptyBecomesCoPadded(): void
	{
		// Empty input becomes "co", then padded to the 3-char floor → "co-co".
		$this->assertSame('co-co', SlugService::slugify('!!!'));
	}

	public function testIsValidRejectsUppercaseAndShort(): void
	{
		$this->assertFalse(SlugService::isValid('Acme'));
		$this->assertFalse(SlugService::isValid('ab'));
		$this->assertTrue(SlugService::isValid('acme-gmbh'));
	}

	public function testSuggestUniqueReturnsBaseWhenFree(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('slugExists')->willReturn(false);
		$l10n = $this->createMock(IL10N::class);
		$service = new SlugService($mapper, $l10n);

		$this->assertSame('mueller-gmbh', $service->suggestUnique('Müller GmbH'));
	}

	public function testSuggestUniqueAppendsBase36SuffixOnCollision(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('slugExists')->willReturnCallback(
			static fn (string $slug): bool => in_array($slug, ['mueller-gmbh', 'mueller-gmbh-2'], true)
		);
		$l10n = $this->createMock(IL10N::class);
		$service = new SlugService($mapper, $l10n);

		$this->assertSame('mueller-gmbh-3', $service->suggestUnique('Müller GmbH'));
	}

	public function testValidateForCreateRejectsInvalidCharset(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$service = new SlugService($mapper, $l10n);

		$this->expectException(ValidationException::class);
		try {
			$service->validateForCreate('Acme GmbH');
		} catch (ValidationException $e) {
			$this->assertSame('SLUG_INVALID', $e->getErrorCode());
			throw $e;
		}
	}

	public function testValidateForCreateRejectsTaken(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('slugExists')->with('acme-gmbh')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$service = new SlugService($mapper, $l10n);

		$this->expectException(ValidationException::class);
		try {
			$service->validateForCreate('acme-gmbh');
		} catch (ValidationException $e) {
			$this->assertSame('SLUG_TAKEN', $e->getErrorCode());
			throw $e;
		}
	}
}
