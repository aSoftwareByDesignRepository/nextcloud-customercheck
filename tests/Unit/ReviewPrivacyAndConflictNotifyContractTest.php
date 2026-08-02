<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** 1.6.8 — import_anomaly DSGVO, conflict notify, includeArchived. */
final class ReviewPrivacyAndConflictNotifyContractTest extends TestCase
{
	public function testImportAnomalyDefaultsOffUntilDsgvo(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/WorkspaceService.php');
		$this->assertStringContainsString("'import_anomaly' => ['enabled' => false, 'dsgvoAckAt' => null]", $src);
		$review = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString("empty(\$sources['import_anomaly']['dsgvoAckAt'])", $review);
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-review-sources.php');
		$this->assertStringContainsString('data-review-kind="import_anomaly"', $tpl);
	}

	public function testEditConflictNotifiesActors(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/EditConflictService.php');
		$this->assertStringContainsString('notifyConflictActors', $src);
		$this->assertStringContainsString('NOTIFY_EDIT_CONFLICT', $src);
		$this->assertStringContainsString("withdraw('edit_conflict'", $src);
		$constants = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Constants.php');
		$this->assertStringContainsString("NOTIFY_EDIT_CONFLICT = 'crm_edit_conflict'", $constants);
		$notifier = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Notification/Notifier.php');
		$this->assertStringContainsString('NOTIFY_EDIT_CONFLICT', $notifier);
		$this->assertStringContainsString('customercheck.page.conflicts', $notifier);
	}

	public function testIncludeArchivedSuggestionsAreOptIn(): void
	{
		$logic = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewMatchLogic.php');
		$this->assertStringContainsString('bool $includeArchived = false', $logic);
		$mapper = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Db/CompanyMapper.php');
		$this->assertStringContainsString("\$filters['includeArchived']", $mapper);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-review-include-archived', $js);
		$this->assertStringContainsString('includeArchived=1', $js);
	}
}
