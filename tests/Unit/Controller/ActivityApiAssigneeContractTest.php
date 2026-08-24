<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/** API / mobile DTOs must expose assigneeUserId for UI + companion. */
final class ActivityApiAssigneeContractTest extends TestCase
{
	public function testWebAndMobileSerialiseAssignee(): void
	{
		$root = dirname(__DIR__, 3);
		$web = (string)file_get_contents($root . '/lib/Controller/ActivityApiController.php');
		$mobile = (string)file_get_contents($root . '/lib/Service/MobileApiService.php');
		$this->assertStringContainsString("'assigneeUserId' => \$a->getAssigneeUserId()", $web);
		$this->assertStringContainsString("'createdBy' => \$a->getCreatedBy()", $web);
		$this->assertStringContainsString("'assigneeUserId' => \$a->getAssigneeUserId()", $mobile);
	}

	public function testActivityServiceGatesAssigneeMembership(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ActivityService.php');
		$this->assertStringContainsString('findMembershipOrNull', $src);
		$this->assertStringContainsString('NOTIFY_ACTIVITY_ASSIGNED', $src);
		$this->assertStringContainsString('createdByOrAssignee', $src);
		$this->assertStringContainsString("activity.assigned", $src);
	}
}
