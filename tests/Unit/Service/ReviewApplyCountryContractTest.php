<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ReviewApplyCountryContractTest extends TestCase
{
	public function testApplyCountryMappingUpdatesCompanyAndClosesItem(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('function applyCountryMapping', $src);
		self::assertStringContainsString("['country' => (string)\$mapped['canonical']]", $src);
		self::assertStringContainsString('noRelation(', $src);
	}

	public function testApplyCountryRouteAndPersonaEndpoint(): void
	{
		$routes = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/pages/persona.js');
		self::assertNotFalse($routes);
		self::assertNotFalse($js);
		self::assertStringContainsString('review_api#applyCountry', $routes);
		self::assertStringContainsString('/apply-country', $js);
		self::assertStringContainsString('data-crm-review-deal', $js);
	}

	public function testPersonStubLocksCompanyRow(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('lockRow($companyId)', $src);
		self::assertStringContainsString('DbTransaction::begin', $src);
	}

	public function testEntityUpdatesUseOwnedTransactions(): void
	{
		foreach (['DealService.php', 'CompanyService.php', 'PersonService.php', 'AgreementService.php'] as $file) {
			$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/' . $file);
			self::assertNotFalse($src, $file);
			self::assertStringContainsString('DbTransaction::begin', $src, $file);
		}
	}

	public function testPipelinePeriodChips(): void
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/pipeline.php');
		self::assertNotFalse($tpl);
		self::assertStringContainsString('crm-pipeline-period-custom', $tpl);
		self::assertStringContainsString('This month', $tpl);
	}
}
