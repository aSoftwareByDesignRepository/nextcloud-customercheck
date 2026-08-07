<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Archive/restore API must pass optimistic-lock precondition (PHP 8 arity). */
final class CompanyArchiveApiContractTest extends TestCase
{
	public function testArchiveAndRestorePassUpdatedAtPrecondition(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/CompanyApiController.php');
		$this->assertStringContainsString('updatedAtPrecondition($body)', $src);
		$this->assertGreaterThanOrEqual(2, substr_count($src, 'companies->archive(') + substr_count($src, '->archive('));
		$this->assertStringContainsString("\$this->companies->archive(", $src);
		$this->assertStringContainsString("\$this->companies->restore(", $src);
		$this->assertStringContainsString("\$this->updatedAtPrecondition(\$body)", $src);
		// Two-arg archive/restore calls are a PHP 8 ArgumentCountError.
		$this->assertDoesNotMatchRegularExpression(
			'/companies->archive\(\s*\$id,\s*\$ctx\[[\'"]uid[\'"]\]\s*\)/',
			$src,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/companies->restore\(\s*\$id,\s*\$ctx\[[\'"]uid[\'"]\]\s*\)/',
			$src,
		);
	}

	public function testGdprExportIncludesBuyingRoleAndForecast(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CompanyService.php');
		$this->assertStringContainsString("'buyingRole'", $src);
		$this->assertStringContainsString("'forecastBucket'", $src);
		$this->assertStringContainsString("'lostReasonCode'", $src);
		$this->assertStringContainsString("'ownerUserId'", $src);
	}

	public function testMailSendUsesWorkspaceNameAndLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('ILockingProvider', $src);
		$this->assertStringContainsString('workspaceName(', $src);
		$this->assertStringNotContainsString("disclosure((string)\$box['label'], 'workspace')", $src);
	}

	public function testSequenceDismissIncludesAllSequenceOrigins(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ActivityService.php');
		$this->assertStringContainsString("str_starts_with(\$origin, 'sequence_')", $src);
		$this->assertStringNotContainsString("['sequence_quoted', 'sequence_won']", $src);
	}

	public function testCancelPromiseRouteExists(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertStringContainsString('persona_api#cancelPromise', $routes);
		$this->assertStringContainsString('/promises/{id}/cancel', $routes);
	}
}
