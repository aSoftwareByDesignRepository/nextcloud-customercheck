<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Removing a company person can return matched shared mail to Assign (unmatch only).
 */
final class PersonRemoveReturnMailContractTest extends TestCase
{
	public function testDestroyOrchestratesUnmatchAndAssignReturn(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonApiController.php');
		$this->assertStringContainsString('returnMailToAssign', $ctrl);
		$this->assertStringContainsString('unmatchByCompanyAndFrom', $ctrl);
		$this->assertStringContainsString('returnSharedMailMessagesToAssign', $ctrl);
		$this->assertStringContainsString('clearPersonId', $ctrl);
		$this->assertStringContainsString("'contactsKept' => true", $ctrl);
		$this->assertStringContainsString('mailReturnedToAssign', $ctrl);
		$start = strpos($ctrl, 'public function destroy(int $id)');
		$this->assertNotFalse($start);
		$chunk = substr($ctrl, (int)$start, 1600);
		$deletePos = strpos($chunk, '$this->persons->delete(');
		$unmatchPos = strpos($chunk, 'unmatchByCompanyAndFrom');
		$this->assertNotFalse($deletePos);
		$this->assertNotFalse($unmatchPos);
		$this->assertLessThan($unmatchPos, $deletePos);

		$mail = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('function unmatchByCompanyAndFrom', $mail);
		$this->assertStringContainsString("'open'", $mail);

		$review = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('function returnSharedMailMessagesToAssign', $review);
		$this->assertStringContainsString('syncSharedMail', $review);

		$touches = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Db/EmailTouchMapper.php');
		$this->assertStringContainsString('function clearPersonId', $touches);
	}
}
