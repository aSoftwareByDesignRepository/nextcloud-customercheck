<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** GNQ §5.6 / §5.8 — Review disclosure + suggestion name surface. */
final class ReviewInboxUxContractTest extends TestCase
{
	public function testUnfilteredInboxAlwaysShowsDisclosure(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString("id = 'crm-review-disclosure'", $src);
		$this->assertStringContainsString('This inbox may include shared mail and platform sources', $src);
		$this->assertStringContainsString('Shared mailbox items are visible to everyone', $src);
	}

	public function testSuggestionsPreferCompanyNameOverBareId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('top.companyName', $src);
		$this->assertStringContainsString('Ambiguous company name from import', $src);
		$this->assertStringNotContainsString("' (#' + String(top.companyId) + ')'", $src);
	}

	public function testImportAnomalyCompanyNameFeedsOrgSuggestion(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString("\$ref['org'] = \$raw", $src);
		$this->assertStringContainsString("\$kind === 'import_anomaly' && (\$ref['fieldKey'] ?? '') === 'company_name'", $src);
	}

	public function testMatchLogicReturnsCompanyName(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewMatchLogic.php');
		$this->assertStringContainsString("'companyName'", $src);
		$this->assertStringContainsString('private static function hit', $src);
	}
}
