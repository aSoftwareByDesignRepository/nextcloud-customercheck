<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\CompanyScopedFk;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class CompanyScopedFkTest extends TestCase
{
	public function testSameCompanyPasses(): void
	{
		$l10n = $this->createMock(IL10N::class);
		CompanyScopedFk::assertSameCompany(5, 5, 'personId', $l10n);
		$this->addToAssertionCount(1);
	}

	public function testDifferentCompanyFails(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		try {
			CompanyScopedFk::assertSameCompany(5, 9, 'personId', $l10n);
			$this->fail('must reject');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('personId', $e->getFields());
		}
	}
}
