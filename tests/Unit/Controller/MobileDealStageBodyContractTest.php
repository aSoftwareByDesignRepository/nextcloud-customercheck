<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\MobileController;
use Test\TestCase;

/**
 * Companion dealStage must forward the JSON body as DealService::$extra
 * so lostReasonCode reaches LOSS_CODE_REQUIRED validation (product §11.6).
 */
class MobileDealStageBodyContractTest extends TestCase {
	public function testDealStagePassesJsonBodyAsChangeStageExtra(): void {
		$src = file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/MobileController.php',
		);
		$this->assertNotFalse($src);
		$this->assertMatchesRegularExpression(
			'/function dealStage\(int \$workspaceId, int \$id\).*?\$this->deals->changeStage\(\s*'
			. '\$id,\s*'
			. '\(string\)\(\$body\[\'stage\'\] \?\? \'\'\),\s*'
			. 'isset\(\$body\[\'lostReason\'\]\).*?,\s*'
			. '\$this->preconditionFrom\(\$body\),\s*'
			. '\$uid,\s*'
			. '\$body,\s*'
			. '\);/s',
			$src,
			'MobileController::dealStage must pass $body as the 6th changeStage argument',
		);
		$this->assertSame(MobileController::class, MobileController::class);
	}
}
