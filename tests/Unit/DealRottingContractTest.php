<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Constants;
use PHPUnit\Framework\TestCase;

final class DealRottingContractTest extends TestCase
{
	public function testNotificationSubjectsIncludeDealRotting(): void
	{
		$this->assertContains(Constants::NOTIFY_DEAL_ROTTING, Constants::NOTIFICATION_SUBJECTS);
		$this->assertSame('crm_deal_rotting', Constants::NOTIFY_DEAL_ROTTING);
	}

	public function testRenewalJobWiresDealRotting(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/BackgroundJob/RenewalReminderJob.php');
		$this->assertStringContainsString('DealRottingReminderService', $src);
		$this->assertStringContainsString('dealRotting->shouldRunNow()', $src);
		$this->assertStringContainsString('dealRotting->run()', $src);
	}

	public function testPipelineShowsNeedsFollowUpNotStaleJargon(): void
	{
		$partial = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-deal-freshness.php');
		$this->assertStringContainsString("\$l->t('Needs follow-up')", $partial);
		$this->assertStringNotContainsString("\$l->t('Stale')", $partial);
		$pipeline = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString('crm-deal-freshness.php', $pipeline);
		$company = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-deal-freshness.php', $company);
	}

	public function testDealServiceToArrayExposesFreshness(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/DealService.php');
		$this->assertStringContainsString('DealFreshnessLogic::snapshot', $src);
		$this->assertStringContainsString("'ageDays'", $src);
		$this->assertStringContainsString("'stageIdleDays'", $src);
		$this->assertStringContainsString("'rotting'", $src);
	}
}
