<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** AC-CC-6 / AC-CC-20 — Documents unlink + Tasks one-way copy. */
final class DocumentsAndTasksContractTest extends TestCase
{
	public function testDocumentsUnlinkControlAndApiPresent(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$picker = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/folder-picker.js');
		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('id="crm-unlink-folder"', $html);
		$this->assertStringContainsString('crm-unlink-folder', $js);
		$this->assertStringContainsString("Api.del('/companies/'", $js);
		$this->assertStringContainsString('files-folder', $js);
		$this->assertStringContainsString('deleteFilesFolder', $routes);
		$this->assertStringContainsString('Unlink folder', $js);
		$this->assertStringContainsString('CustomerCheckFolderPicker', $js);
		$this->assertStringContainsString('pickFolder', $js);
		$this->assertStringContainsString('allowDirectoryChooser', $picker);
		$this->assertStringContainsString('common/folder-picker', $controller);
		$this->assertStringContainsString('Browse folders', $html);
		$this->assertStringContainsString('linkFolderViaSearchFallback', $js);
		$this->assertStringContainsString('unavailable', $js);
	}

	public function testOneWayTasksCopyAndOpenInTasks(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('Done in CustomerCheck is what counts', $html);
		$this->assertStringContainsString('Open in Tasks', $js);
		$this->assertStringContainsString('/tasks', $js);
	}

	public function testPersonDeleteRequiresOptimisticLock(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PersonService.php');
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonApiController.php');
		$this->assertStringContainsString('OptimisticLock::assertFresh', $svc);
		$this->assertMatchesRegularExpression('/function delete\([^)]*updatedAtPrecondition/', $svc);
		$this->assertStringContainsString('updatedAtPrecondition($body)', $ctrl);
	}
}
