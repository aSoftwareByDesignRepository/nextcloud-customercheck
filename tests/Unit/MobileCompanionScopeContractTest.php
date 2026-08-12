<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CUC-COMPANION-ROAD-1 — mobile API surface for road authoring.
 * Template CRUD stays web; checklist apply/assign/attach are mobile Must-Haves.
 */
final class MobileCompanionScopeContractTest extends TestCase
{
	public function testMobileRoutesCoverRoadAuthoringNotTemplateManage(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertStringContainsString('mobile#today', $routes);
		$this->assertStringContainsString('mobile#companies', $routes);
		$this->assertStringContainsString('mobile#companyDetail', $routes);
		$this->assertStringContainsString('mobile#createCompany', $routes);
		$this->assertStringContainsString('mobile#updateCompany', $routes);
		$this->assertStringContainsString('mobile#createPerson', $routes);
		$this->assertStringContainsString('mobile#updatePerson', $routes);
		$this->assertStringContainsString('mobile#createDeal', $routes);
		$this->assertStringContainsString('mobile#createActivity', $routes);
		$this->assertStringContainsString('mobile#doneActivity', $routes);
		$this->assertStringContainsString('mobile#doneChecklistItem', $routes);
		$this->assertStringContainsString('mobile#checklistTemplates', $routes);
		$this->assertStringContainsString('mobile#dealChecklist', $routes);
		$this->assertStringContainsString('mobile#applyChecklistTemplate', $routes);
		$this->assertStringContainsString('mobile#updateChecklistItem', $routes);
		$this->assertStringContainsString('mobile#linkChecklistFile', $routes);
		$this->assertStringContainsString('mobile#uploadChecklistFile', $routes);
		$this->assertStringContainsString('mobile#companyFiles', $routes);
		$this->assertStringContainsString('mobile#members', $routes);
		$this->assertStringContainsString('mobile#deals', $routes);
		$this->assertStringContainsString('mobile#dealStage', $routes);
		$this->assertStringContainsString('mobile#agreements', $routes);
		// Template CRUD / Settings stay web.
		$this->assertStringNotContainsString('mobile#createTemplate', $routes);
		$this->assertStringNotContainsString('mobile#updateTemplate', $routes);
		$this->assertStringNotContainsString('mobile#deleteTemplate', $routes);
		$this->assertStringNotContainsString('mobile#templatesManage', $routes);
	}

	public function testRoadMutationsGateCompanyAndMutationChannel(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/MobileController.php');
		foreach (['createCompany', 'updateCompany', 'createPerson', 'updatePerson', 'createDeal', 'applyChecklistTemplate', 'updateChecklistItem', 'linkChecklistFile', 'uploadChecklistFile'] as $method) {
			$chunk = $this->extractMethod($ctrl, $method);
			$this->assertStringContainsString('assertSafeMutationChannel', $chunk, $method);
			$this->assertStringContainsString("requireMobileWorkspace(\$uid, \$workspaceId, 'write')", $chunk, $method);
		}
		$create = $this->extractMethod($ctrl, 'createCompany');
		$this->assertStringContainsString('idempotency->once', $create);
		$this->assertStringContainsString("unset(\$body['filesFolderId'], \$body['filesFolderPath'])", $create);
		$update = $this->extractMethod($ctrl, 'updateCompany');
		$this->assertStringContainsString("unset(\$body['workspaceId'], \$body['filesFolderId'], \$body['filesFolderPath'])", $update);
		$channel = $this->extractMethod($ctrl, 'assertSafeMutationChannel');
		$this->assertStringContainsString('passesCSRFCheck', $channel);
		$this->assertStringContainsString('authorizationBasicAuthenticatesCurrentUser', $channel);
		// Presence-only Basic|Bearer MUST NOT appear in the channel gate (Argus MF CSRF).
		$this->assertDoesNotMatchRegularExpression(
			'/preg_match\s*\(\s*[\'"]\/\^\(Basic\|Bearer\)/',
			$channel,
			'Must not accept Authorization presence alone as CSRF bypass',
		);
		$basicAuth = $this->extractMethod($ctrl, 'authorizationBasicAuthenticatesCurrentUser');
		$this->assertStringContainsString('checkPassword', $basicAuth);
		$this->assertStringContainsString('hash_equals', $basicAuth);
		$apply = $this->extractMethod($ctrl, 'applyChecklistTemplate');
		$this->assertStringContainsString('idempotency->once', $apply);
		$this->assertStringContainsString('flushPendingAssignNotifies', $apply);
		$link = $this->extractMethod($ctrl, 'linkChecklistFile');
		$this->assertStringContainsString('idempotency->once', $link);
		$this->assertStringContainsString('STATUS_CREATED', $link);
		$upload = $this->extractMethod($ctrl, 'uploadChecklistFile');
		$this->assertStringContainsString('findResourceId', $upload);
		$this->assertStringContainsString('scopedIdempotencyKey', $upload);
		$this->assertStringContainsString('checklistItemPayloadForIdempotentReplay', $upload);
		$this->assertStringContainsString('uploadIntoCompanyFolder', $upload);
		$this->assertStringContainsString('idempotency->once', $upload);
		$this->assertStringContainsString('requireCompanyInWorkspace', $upload);
		$this->assertStringContainsString('checklistPayloadLinksFileId', $upload);
		// Catch path: once() threw after bytes hit disk.
		$this->assertMatchesRegularExpression(
			'/catch\s*\(\s*\\\\Throwable\s*\$e\s*\)\s*\{\s*\$this->documents->tryDeleteUploadedFile\(\$uid,\s*\$fileId\);/s',
			$upload,
		);
		// Zeus: orphan cleanup when payload does not list this upload's fileId
		// (race loser OR unique-ledger rollback after factory ran).
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*!\$this->checklistPayloadLinksFileId\(\$payload,\s*\$fileId\)\s*\)\s*\{\s*\$this->documents->tryDeleteUploadedFile\(\$uid,\s*\$fileId\);/s',
			$upload,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/idempotency->once\([\s\S]*uploadIntoCompanyFolder/m',
			$upload,
		);
		$helper = $this->extractMethod($ctrl, 'checklistPayloadLinksFileId');
		$this->assertStringContainsString("\$file['fileId']", $helper);
		$checklist = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/DealChecklistService.php');
		$this->assertStringContainsString('DbTransaction::begin', $checklist);
		$this->assertStringNotContainsString('$this->db->beginTransaction();', $checklist);
		$assign = $this->extractMethod($ctrl, 'updateChecklistItem');
		$this->assertStringContainsString('requireCompanyInWorkspace((int)$item->getCompanyId(), $wsId)', $assign);
	}

	public function testCompanyDetailReturnsRoad360NotLiteOnly(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/MobileController.php');
		$detail = $this->extractMethod($ctrl, 'companyDetail');
		$this->assertStringContainsString('companyRoadDetail', $detail);
		$this->assertStringNotContainsString('companyLite($company', $detail);
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MobileApiService.php');
		$this->assertStringContainsString('function companyRoadDetail', $svc);
		$this->assertStringContainsString("'persons'", $svc);
		$this->assertStringContainsString("'deals'", $svc);
	}

	private function extractMethod(string $src, string $name): string
	{
		$pos = strpos($src, 'function ' . $name);
		$this->assertNotFalse($pos, $name . ' missing');
		$chunk = substr($src, (int)$pos);
		if (preg_match('/^function\s+' . preg_quote($name, '/') . '\b.*?(?=\n\tpublic function|\n\tprivate function|\n\tprotected function|\n}\n)/s', $chunk, $m) === 1) {
			return $m[0];
		}

		return substr($chunk, 0, 6000);
	}
}
