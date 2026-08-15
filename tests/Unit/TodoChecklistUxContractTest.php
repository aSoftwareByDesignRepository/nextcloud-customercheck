<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus: company 360 To-do card is one Add + checklist rows; promises/phone stay quiet extras.
 */
final class TodoChecklistUxContractTest extends TestCase
{
	public function testNextStepsHasOnePrimaryAddNoHeaderMoreMenu(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = (int)strpos($detail, 'id="crm-next-steps"');
		$end = (int)strpos($detail, 'id="crm-deals"', $start);
		$chunk = substr($detail, $start, max(0, $end - $start));

		$this->assertStringContainsString('id="crm-add-activity"', $chunk);
		$this->assertStringContainsString('crm-btn--lg', $chunk);
		$this->assertStringContainsString("\$l->t('What still needs doing.')", $chunk);
		$this->assertStringContainsString("\$l->t('Tap Add to-do. Pick a due date.')", $chunk);
		$this->assertStringNotContainsString('crm-more-menu', $chunk);
		$this->assertStringNotContainsString("\$l->t('Copy to phone Tasks')", $chunk);
		$this->assertStringNotContainsString('data-crm-trigger="crm-add-activity"', $chunk);
		$this->assertStringContainsString('class="visually-hidden"', $chunk);
		$this->assertStringContainsString('<details class="crm-next-steps__extra-details" id="crm-promises">', $chunk);
		$this->assertStringContainsString('crm-next-steps__extra-head', $chunk);
		$this->assertStringContainsString('id="crm-open-tasks"', $chunk);
		$this->assertStringContainsString('crm-todo-list', $chunk);
		$this->assertStringContainsString('data-crm-complete-activity', $chunk);
		$this->assertStringNotContainsString('data-crm-push-todo', $chunk);
		$this->assertStringNotContainsString("\$l->t('Send to Tasks')", $chunk);
		$this->assertStringContainsString('id="crm-add-promise"', $chunk);
		$this->assertStringContainsString('id="crm-new-task"', $chunk);
		$this->assertStringContainsString('crm-next-steps__extra-actions', $chunk);
	}

	public function testAddTodoDialogOffersPhoneAndToldCustomerUnderAdvanced(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("promptFields(t('Add to-do')", $js);
		$this->assertStringContainsString("name: 'alsoOnPhone'", $js);
		$this->assertStringContainsString("name: 'toldCustomer'", $js);
		$this->assertStringContainsString("t('Also send to Tasks')", $js);
		$this->assertStringContainsString("t('Told the customer')", $js);
		$this->assertStringContainsString("/companies/' + companyId + '/promises'", $js);
		$this->assertStringContainsString("/companies/' + companyId + '/tasks'", $js);
	}

	public function testPromisesAndPhonePaintChecklistNotTables(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString("crm-todo-list", $js);
		$this->assertStringContainsString("data-crm-promise-done", $js);
		$this->assertDoesNotMatchRegularExpression(
			"/crm-promises-body[\s\S]{0,800}table class=\"table/",
			$js,
		);
		$this->assertStringContainsString("Nothing told the customer yet", $js);
		$this->assertStringContainsString("Nothing open for this company.", $js);
		$this->assertStringNotContainsString("No open tasks", $js);
	}

	public function testCssDefinesTodoListTouchTargets(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-todo-list__item', $css);
		$this->assertStringContainsString('.crm-todo-list__actions .crm-btn--lg', $css);
		$this->assertStringContainsString('min-height: 48px', $css);
		$this->assertStringContainsString('.crm-next-steps__extra-head', $css);
		$this->assertStringContainsString('.crm-next-steps__extra-actions .crm-btn', $css);
	}
}
