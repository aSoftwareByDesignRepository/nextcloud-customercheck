<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Contract: Review assign creates person stubs; import country → anomaly; Apply once UX. */
final class ReviewAssignPersonStubContractTest extends TestCase
{
	public function testAssignEnsuresPersonStub(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('ensurePersonStubFromReview', $src);
		self::assertStringContainsString('contact_suggest', $src);
		self::assertStringContainsString('$this->persons->create', $src);
	}

	public function testImportEnqueuesUnmappedCountry(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ImportService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString("'country'", $src);
		self::assertStringContainsString("enqueueImportAnomaly(\$workspaceId, 'country'", $src);
	}

	public function testPersonaExposesApplyOnce(): void
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/pages/persona.js');
		self::assertNotFalse($js);
		self::assertStringContainsString('data-crm-review-apply-once', $js);
		self::assertStringContainsString('learn: !!learn', $js);
	}
}
