<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Timeline must never dump machine event keys (email.logged) into the UI.
 */
final class TimelineHumanLabelsContractTest extends TestCase
{
	public function testCompanyDetailRendersLabeledTimelineRows(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('eventTypeLabel', $html);
		$this->assertStringContainsString('actorLabel', $html);
		$this->assertStringContainsString('createdAtLabel', $html);
		$this->assertStringContainsString('crm-timeline__what', $html);
		$this->assertStringContainsString('crm-timeline__when', $html);
		$this->assertStringContainsString("\$l->t('by %s'", $html);
		$this->assertStringNotContainsString("p(\$ev['eventType'])", $html);
		$this->assertStringContainsString('What happened on this account', $html);
	}

	public function testPageAndApiEnrichTimelineLabels(): void
	{
		$page = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$api = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/CompanyApiController.php');
		$labels = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/DisplayLabelService.php');
		$this->assertStringContainsString('timelineEventType', $page);
		$this->assertStringContainsString('eventTypeLabel', $page);
		$this->assertStringContainsString('timelineEventType', $api);
		$this->assertStringContainsString('eventTypeLabel', $api);
		$this->assertStringContainsString("'email.logged' => \$this->l10n->t('Email logged')", $labels);
		$this->assertStringContainsString("'review.assigned' => \$this->l10n->t('Item assigned')", $labels);
		$this->assertStringContainsString("default => \$this->l10n->t('Update')", $labels);
	}

	public function testCssDefinesReadableTimelineCards(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-timeline__card', $css);
		$this->assertStringContainsString('.crm-timeline__what', $css);
		$this->assertStringContainsString('@media (max-width: 767px)', $css);
	}
}
