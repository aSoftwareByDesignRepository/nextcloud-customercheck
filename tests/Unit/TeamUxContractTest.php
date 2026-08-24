<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Team page — clear board vs capacity tools, no jargon-only empty dump (product §13).
 */
final class TeamUxContractTest extends TestCase
{
	public function testTeamTemplateSplitsBoardAndCapacity(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/team.php');
		$this->assertStringContainsString('id="crm-team-board"', $html);
		$this->assertStringContainsString('data-crm-persona-mount="team"', $html);
		$this->assertStringContainsString('id="capacity"', $html);
		$this->assertStringContainsString('data-crm-team-capacity', $html);
		$this->assertStringContainsString('crm-card--collapsible', $html);
		$this->assertStringContainsString('crm-team-capacity-shell', $html);
		$this->assertStringNotContainsString('crm-advanced', $html);
		$this->assertStringContainsString('Manager tools', $html);
		$this->assertStringContainsString('Who needs help', $html);
		$this->assertStringContainsString('crm-collapsible-disclosure.php', $html);
		$this->assertStringContainsString('On track = fine. Needs help = coach them.', $html);
		$this->assertStringContainsString('Tap Coach', $html);
		$this->assertStringNotContainsString('Capacity tools', $html);
		$this->assertStringNotContainsString('Green = fine. Amber = coach.', $html);
		$this->assertStringNotContainsString('No team rows to show.', $html);
	}

	public function testPersonaTeamRendersHumanColumnsAndCapacityCards(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-team-capacity__stack', $js);
		$this->assertStringContainsString('crm-team-capacity__step', $js);
		$this->assertStringContainsString('crm-team-capacity__intro', $js);
		$this->assertStringContainsString("'<details class=\"crm-team-capacity__panel\"'", $js);
		$this->assertStringContainsString('teamCapacityPanel', $js);
		$this->assertStringContainsString('One step at a time', $js);
		$this->assertStringContainsString('crm-team-capacity__options', $js);
		$this->assertStringContainsString('crm-team-capacity__confirm', $js);
		$this->assertStringContainsString('crm-team-capacity__pickers', $js);
		$this->assertStringContainsString('CustomerCheckTeamCapacityUi', $js);
		$this->assertStringContainsString('capacityUi.bindExclusiveAccordion(capacityHost, 0);', $js);
		$this->assertStringContainsString('withSubmitGuard', $js);
		$this->assertStringContainsString('validateOooCoverInput', $js);
		$this->assertStringContainsString('validateBulkReassignInput', $js);
		$this->assertStringNotContainsString('crm-team-capacity__grid', $js);
		$this->assertStringNotContainsString('crm-team-capacity__card--wide', $js);
		$this->assertStringNotContainsString('crm-team-capacity__card', $js);
		$this->assertStringNotContainsString('Three separate steps', $js);
		$this->assertStringContainsString("querySelector('details.crm-team-capacity-shell')", $js);
		$this->assertStringNotContainsString('#capacity details.crm-team-capacity-shell', $js);
		$this->assertStringContainsString('Cover while away', $js);
		$this->assertStringContainsString('Move work to someone else', $js);
		$this->assertStringContainsString('Hand deals, accounts, and open tasks to another colleague.', $js);
		$this->assertStringContainsString('value="activities"', $js);
		$this->assertStringContainsString('value="checklist"', $js);
		$this->assertStringContainsString("t('Open to-dos')", $js);
		$this->assertStringContainsString('Escalate for help', $js);
		$this->assertStringContainsString('Needs help', $js);
		$this->assertStringContainsString('On track', $js);
		$this->assertStringContainsString('displayName', $js);
		$this->assertStringContainsString('roleLabel', $js);
		$this->assertStringContainsString('data-label="', $js);
		$this->assertStringContainsString('crm-table--responsive', $js);
		$this->assertStringContainsString('Open deals', $js);
		$this->assertStringContainsString('Missing coverage', $js);
		$this->assertStringContainsString("t('Coach')", $js);
		$this->assertStringNotContainsString("t('1:1 prep')", $js);
		$this->assertStringNotContainsString("t('No open chase items.')", $js);
		$this->assertStringContainsString('No teammates here yet', $js);
		$this->assertStringNotContainsString('Open chase', $js);
		$this->assertStringNotContainsString('Commit pipeline', $js);
		$this->assertStringNotContainsString('No team rows to show.', $js);
		$this->assertStringNotContainsString('Quote ≥14', $js);
		$this->assertStringNotContainsString('Set OOO cover', $js);
		$this->assertStringNotContainsString('Bulk reassign', $js);
	}

	public function testTeamCapacityCssUsesVerticalStack(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-team-capacity__stack', $css);
		$this->assertStringContainsString('flex-direction: column', $css);
		$this->assertMatchesRegularExpression('/\.crm-team-capacity__stack[\s\S]*max-width:\s*none/', $css);
		$this->assertStringContainsString('.crm-team-capacity {', $css);
		$this->assertStringContainsString('.crm-team-capacity__step', $css);
		$this->assertStringContainsString('.crm-team-capacity__intro', $css);
		$this->assertMatchesRegularExpression('/\.crm-team-capacity__panel\s*\{[^}]*border-radius:/s', $css);
		$this->assertStringContainsString('.crm-team-capacity__options-fieldset', $css);
		$this->assertStringContainsString('minmax(11rem, 1fr)', $css);
		$this->assertStringNotContainsString('.crm-team-capacity__grid', $css);
		$this->assertStringNotContainsString('crm-team-capacity__card--wide', $css);
		$this->assertStringNotContainsString('.crm-team-capacity__card', $css);
		$this->assertStringContainsString('.crm-form--stack', $css);
		$this->assertStringContainsString('.crm-form-row-2', $css);
		$this->assertMatchesRegularExpression('/\.crm-team-capacity__actions[\s\S]*justify-content:\s*flex-end/', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-team-capacity__dates \.crm-datepicker-host--flex \{[^}]*align-items:\s*stretch;[^}]*width:\s*100%;/',
			$css,
		);
		$this->assertMatchesRegularExpression('/min-height:\\s*44px/', $css);
	}

	public function testCoachingBoardReturnsSummaryAndLabels(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CoachingService.php');
		$this->assertStringContainsString("'displayName'", $src);
		$this->assertStringContainsString("'roleLabel'", $src);
		$this->assertStringContainsString("'summary'", $src);
		$this->assertStringContainsString('ensureViewerIsMember', $src);
		$this->assertStringContainsString('Planning labels — not expected cash.', $src);
		$this->assertStringContainsString('if (!$isAppAdmin)', $src);
		// O(members × myChase) must never return — Today embeds this board.
		$this->assertStringContainsString('chaseByOwnerForWorkspace', $src);
		$this->assertStringNotContainsString('->myChase(', $src);
		$this->assertStringContainsString('gapCountsByAccountOwner', $src);
		$this->assertStringNotContainsString('->myAccounts(', $src);
		$this->assertStringContainsString('filterActivityIdsInWorkspace', $src);
		$this->assertStringContainsString('notifyActivityBulkReassign', $src);
		$this->assertStringContainsString("'activities' => \$activityAffected", $src);
	public function testTeamCapacityUiExportsValidation(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/team-capacity-ui.js');
		$this->assertStringContainsString('validateOooCoverInput: validateOooCoverInput', $js);
		$this->assertStringContainsString('validateBulkReassignInput: validateBulkReassignInput', $js);
		$this->assertStringContainsString('other.open = false', $js);
	}
}
