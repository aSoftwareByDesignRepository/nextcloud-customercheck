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
		$money = $this->extractMethod($src, 'function money(): TemplateResponse');
		$this->assertStringContainsString('$wsId = $this->resolvedWorkspaceId();', $money);
		$this->assertStringContainsString(
			'ListWorkspaceGuard::isListable($wsId) ? $wsId : 0',
			$money,
			'Money SSR must fail closed when workspace id is 0',
		);
		$this->assertStringNotContainsString(
			'$this->money->overview(200, $state !== \'\' ? $state : null);',
			$money,
			'Unscoped Money overview call must not return',
		);
	}

	private function extractMethod(string $src, string $needle): string
	{
		$start = strpos($src, $needle);
		$this->assertNotFalse($start, $needle);
		return substr($src, $start, 2500);
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
