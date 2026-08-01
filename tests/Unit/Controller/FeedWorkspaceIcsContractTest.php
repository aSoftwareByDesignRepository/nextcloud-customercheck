<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\FeedController;
use Test\TestCase;

/** Contract: workspace ICS route must not reuse the Default-only global token. */
final class FeedWorkspaceIcsContractTest extends TestCase
{
	public function testWorkspaceRenewalsUsesIndependentTokenMatch(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/FeedController.php',
		);
		$this->assertStringContainsString('function workspaceRenewals', $src);
		$this->assertStringContainsString('workspaceIcsTokenMatches', $src);
		$this->assertDoesNotMatchRegularExpression(
			'/function workspaceRenewals[\s\S]*?tokenMatches\(Constants::CONFIG_ICS_TOKEN/s',
			$src,
		);
		$this->assertSame(FeedController::class, FeedController::class);
	}
}
