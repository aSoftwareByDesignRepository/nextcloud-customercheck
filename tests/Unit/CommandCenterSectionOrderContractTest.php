<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AC-CC-1 — Company 360 DOM section order (COMMAND-CENTER-SPEC §3.2).
 */
final class CommandCenterSectionOrderContractTest extends TestCase
{
	public function testCompanyDetailSectionIdsInNormativeOrder(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$required = [
			'crm-glance',
			'crm-identity',
			'crm-communication',
			'crm-next-steps',
			'crm-activities',
			'crm-promises',
			'crm-open-tasks',
			'crm-persons',
			'crm-deals',
			'crm-contracts',
			'crm-billing',
			'crm-documents',
			'crm-identity-links',
			'crm-projects',
			'crm-tickets',
			'crm-account-plan',
			'crm-prepare-meeting',
			'crm-timeline',
		];
		$positions = [];
		foreach ($required as $id) {
			$pos = strpos($html, 'id="' . $id . '"');
			self::assertNotFalse($pos, $id . ' missing');
			$positions[$id] = $pos;
		}
		$prev = -1;
		foreach ($required as $id) {
			self::assertGreaterThan($prev, $positions[$id], $id . ' out of order');
			$prev = $positions[$id];
		}
	}

	public function testNoEditableNumericSiblingOrFileIdInputs(): void
	{
		$form = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		foreach ([$form, $detail, $js] as $src) {
			self::assertDoesNotMatchRegularExpression(
				'/name=["\']pcCustomerId["\'][^>]*type=["\']number["\']/',
				$src,
			);
			self::assertDoesNotMatchRegularExpression(
				'/name=["\']tcCustomerId["\'][^>]*type=["\']number["\']/',
				$src,
			);
			self::assertDoesNotMatchRegularExpression(
				'/name=["\']files_folder_id["\']/',
				$src,
			);
			self::assertDoesNotMatchRegularExpression(
				'/name=["\']filesFolderId["\'][^>]*type=["\']number["\']/',
				$src,
			);
		}
		self::assertStringContainsString('crm-link-folder', $detail);
		self::assertStringContainsString('crm-create-folder', $detail);
	}

	public function testOneWayTasksHintPresentEn(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		self::assertStringContainsString('Done in CustomerCheck is what counts', $html);
	}
}
