<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Today embeds CommandBoard → Coaching board. Guard against O(members×deals) regressions.
 */
final class CommandBoardPerfContractTest extends TestCase
{
	public function testCommandBoardDoesNotInlinePerMemberMyChase(): void
	{
		$board = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CommandBoardService.php');
		$coaching = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CoachingService.php');

		$this->assertStringContainsString('$this->coaching->board(', $board);
		$this->assertStringContainsString('chaseByOwnerForWorkspace', $coaching);
		$this->assertStringNotContainsString('->myChase(', $coaching);
		$this->assertStringNotContainsString('->myAccounts(', $coaching);
		$this->assertStringContainsString('gapCountsByAccountOwner', $coaching);
		$this->assertStringContainsString('missedCommitmentsByOwner', $coaching);
		$this->assertStringContainsString('listActive', $coaching);
	}

	public function testCommandBoardResolvesTicketCheckCustomerId(): void
	{
		$board = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CommandBoardService.php');
		$this->assertStringContainsString('TicketCompanyLink::resolveCompanyId', $board);
		$this->assertStringNotContainsString(
			"isset(\$ticket['companyId']) ? (int)\$ticket['companyId'] : 0;\n\t\t\t\tif (\$wsFilter !== null && (\$companyId <= 0",
			$board,
		);
	}

	public function testMyChaseUnownedUsesClaimCta(): void
	{
		$board = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CommandBoardService.php');
		$this->assertStringContainsString("'cta' => \$owner === '' ? 'claim' : 'open'", $board);
		$today = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/today.php');
		$this->assertStringContainsString("data-crm-claim-deal", $today);
		$this->assertStringContainsString("'claim' => \$l->t('Claim')", $today);
	}

	public function testMyAccountsDoesNotCallForCompanyPerRow(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CoverageService.php');
		$this->assertStringContainsString('evaluateCompaniesBatch', $src);
		$this->assertStringContainsString('planOwnersForCompanies', $src);
		// myAccounts body must not call forCompany (N×).
		$myAccounts = null;
		if (preg_match('/function myAccounts\(.*?\{(.*?)\n\tpublic function/s', $src, $m)) {
			$myAccounts = $m[1];
		}
		$this->assertNotNull($myAccounts);
		$this->assertStringNotContainsString('$this->forCompany(', (string)$myAccounts);
	}
}
