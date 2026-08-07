<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Support\OptimisticLock;
use PHPUnit\Framework\TestCase;

/**
 * User-facing updates must require updatedAt (GNQ / OptimisticLock doctrine).
 */
final class OptimisticLockRequiredContractTest extends TestCase
{
	public function testMissingPreconditionFailsWhenRequired(): void
	{
		$this->expectException(ConflictException::class);
		OptimisticLock::assertFresh(new \DateTimeImmutable('2026-08-01T12:00:00+00:00'), null, true);
	}

	public function testMissingPreconditionAllowedWhenSkipped(): void
	{
		OptimisticLock::assertFresh(new \DateTimeImmutable('2026-08-01T12:00:00+00:00'), null, false);
		$this->addToAssertionCount(1);
	}

	public function testCompanyPersonAgreementUpdateRequireSkipOptimisticFlag(): void
	{
		$company = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CompanyService.php');
		$person = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PersonService.php');
		$agreement = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AgreementService.php');
		foreach ([$company, $person, $agreement] as $src) {
			$this->assertStringContainsString('bool $skipOptimistic = false', $src);
			$this->assertStringContainsString('$requirePrecondition = !$skipOptimistic', $src);
			$this->assertStringNotContainsString(
				'$requirePrecondition = $updatedAtPrecondition !== null && trim($updatedAtPrecondition) !== \'\'',
				$src,
			);
		}
		$this->assertStringContainsString('update($id, $input, $actor, null, $canReassignOwners, true)', $company);
		$this->assertStringContainsString('update($id, $appliedPatch, $actor, null, true)', $person);
		$this->assertStringContainsString('update($id, $input, $actor, null, true)', $agreement);
	}
}
