<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Coaching bulk activity reassign — company-join ACL, open-only, CAS (product §13.5 follow-up).
 */
final class CoachingActivityBulkContractTest extends TestCase
{
	public function testBulkActivityPathUsesCompanyJoinOpenOnlyAndCas(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CoachingService.php');
		$this->assertStringContainsString('filterActivityIdsInWorkspace', $src);
		$this->assertStringContainsString('notifyActivityBulkReassign', $src);
		$this->assertStringContainsString('assertWorkspaceMember', $src);
		$this->assertStringContainsString("in_array('activities', \$types, true)", $src);
		$this->assertStringContainsString("idsOwnedBy(\$workspaceId, 'crm_activity', 'assignee_user_id', \$from, true)", $src);
		$this->assertStringContainsString("if (\$table === 'crm_activity') {\n\t\t\t\t\$qb->andWhere(\$qb->expr()->isNull('d.done_at'));", $src);
		$this->assertStringContainsString("->andWhere(\$qb->expr()->isNull('a.done_at'))", $src);
		$this->assertStringContainsString("->andWhere(\$qb->expr()->eq('assignee_user_id', \$qb->createNamedParameter(\$from)))\n\t\t\t\t\t->andWhere(\$qb->expr()->isNull('done_at'))", $src);
		$this->assertStringContainsString('Constants::NOTIFY_ACTIVITY_ASSIGNED', $src);
		$this->assertStringContainsString("'activities' => \$activityAffected", $src);
		$this->assertStringContainsString('innerJoin(\'a\', \'crm_company\'', $src);
		$this->assertStringContainsString('Pick a different colleague to receive the work.', $src);
	}
}
