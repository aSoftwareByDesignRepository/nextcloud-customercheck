<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Money SSR must pass resolvedWorkspaceId into overview — never unscoped (§15.3 / §20).
 */
final class MoneySsrWorkspaceAclContractTest extends TestCase
{
	public function testMoneyPagePassesResolvedWorkspaceId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertMatchesRegularExpression(
			'/function money\(\)[\s\S]*?overview\(\s*200,\s*\$state[^,]*,\s*\$this->resolvedWorkspaceId\(\)/',
			$src,
			'Money SSR must scope overview to the active workspace',
		);
		$this->assertStringNotContainsString(
			'$this->money->overview(200, $state !== \'\' ? $state : null);',
			$src,
			'Unscoped Money overview call must not return',
		);
	}

	public function testMoneyServiceFailsClosedWithoutWorkspace(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MoneyService.php');
		$this->assertStringContainsString(
			'if ($workspaceId === null || $workspaceId <= 0)',
			$src,
		);
		$this->assertStringContainsString('Fail closed', $src);
		$this->assertStringContainsString('all books', $src);
		$this->assertStringNotContainsString(
			'null = no workspace filter',
			$src,
			'Legacy unscoped companyIdsForWorkspace comment must not return',
		);
		// companyInScope must not treat empty/null as “all companies”.
		$this->assertStringNotContainsString(
			'if ($companyIds === null) {' . "\n\t\t\treturn true;",
			$src,
		);
	}
}
