<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Bachus: one To-do card — not peer Promises/Reminders/Phone tasks. */
final class BachusNounCollapseUxContractTest extends TestCase
{
	public function testCompany360HasSingleTodoCardNotPeerWorkNouns(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-next-steps"', $detail);
		$this->assertStringContainsString("\$l->t('To-do')", $detail);
		$this->assertStringContainsString("\$l->t('Add to-do')", $detail);
		$this->assertStringContainsString("\$l->t('Told the customer')", $detail);
		$this->assertStringContainsString("\$l->t('Also on your phone')", $detail);
		$this->assertStringContainsString("\$l->t('Licenses & renewals')", $detail);
		$this->assertStringContainsString("\$l->t('Next steps for this customer')", $detail);
		$this->assertStringContainsString('<details class="crm-next-steps__extra-details" id="crm-promises">', $detail);
		$this->assertStringContainsString('crm-next-steps__extra-head', $detail);
		$this->assertStringNotContainsString('crm-next-steps__block', $detail);
		$nextChunkStart = (int)strpos($detail, 'id="crm-next-steps"');
		$nextChunkEnd = (int)strpos($detail, 'id="crm-deals"', $nextChunkStart);
		$nextChunk = substr($detail, $nextChunkStart, max(0, $nextChunkEnd - $nextChunkStart));
		$this->assertStringNotContainsString('crm-more-menu', $nextChunk);
		$this->assertStringContainsString('crm-todo-list', $nextChunk);
		$this->assertStringNotContainsString("'href' => '#crm-promises'", $detail);
		$this->assertStringNotContainsString("'href' => '#crm-activities'", $detail);
		$this->assertStringNotContainsString("'href' => '#crm-open-tasks'", $detail);
		$this->assertStringNotContainsString("\$l->t('Phone tasks')", $detail);
		$this->assertStringNotContainsString("\$l->t('Reminders')", $detail);
		$this->assertStringNotContainsString("\$l->t('Customer promises')", $detail);
		$this->assertStringNotContainsString("\$l->t('Get started')", $detail);
	}

	public function testNavUsesTodosAndLicensesNotActivitiesAgreements(): void
	{
		$nav = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString("\$l->t('To-dos')", $nav);
		$this->assertStringContainsString("\$l->t('Licenses & renewals')", $nav);
		$this->assertStringNotContainsString("\$l->t('Activities')", $nav);
		$this->assertStringNotContainsString("\$l->t('Agreements')", $nav);
	}

	public function testNestedIdsKeptForHydrationAndDeepLinks(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		foreach (['crm-activities', 'crm-promises', 'crm-open-tasks', 'crm-promises-body', 'crm-open-tasks-body', 'crm-add-activity', 'crm-add-promise'] as $id) {
			$this->assertStringContainsString('id="' . $id . '"', $detail, $id);
		}
		$next = strpos($detail, 'id="crm-next-steps"');
		$act = strpos($detail, 'id="crm-activities"');
		$prom = strpos($detail, 'id="crm-promises"');
		$tasks = strpos($detail, 'id="crm-open-tasks"');
		$this->assertNotFalse($next);
		$this->assertGreaterThan($next, $act);
		$this->assertGreaterThan($act, $prom);
		$this->assertGreaterThan($prom, $tasks);
	}
}
