<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\PageQuickStartCatalog;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class PageQuickStartCatalogTest extends TestCase
{
	private PageQuickStartCatalog $catalog;

	protected function setUp(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback(static function (string $route, array $params = []): string {
			$path = str_replace('customercheck.page.', '/apps/customercheck/', $route);
			if ($params !== []) {
				$path .= '?' . http_build_query($params);
			}
			return $path;
		});
		$this->catalog = new PageQuickStartCatalog($l10n, $url);
	}

	public function testEveryRequiredPageHasQuickStart(): void
	{
		foreach (PageQuickStartCatalog::requiredPageIds() as $pageId) {
			$card = $this->catalog->forPage($pageId);
			$this->assertNotNull($card, $pageId . ' must have a quickstart');
			$this->assertSame($pageId . '_quickstart_v1', $card['key']);
			$this->assertSame('crm-quickstart-' . $pageId, $card['domId']);
			$this->assertNotSame('', trim((string)$card['lead']));
			$this->assertGreaterThanOrEqual(2, count($card['steps']), $pageId);
			foreach ($card['steps'] as $i => $step) {
				$this->assertNotSame('', trim((string)($step['title'] ?? '')), "$pageId step $i title");
				$this->assertNotSame('', trim((string)($step['text'] ?? '')), "$pageId step $i text");
				$this->assertMatchesRegularExpression('/^\d+\.\s/', (string)$step['title'], "$pageId step titles must be numbered");
				if (isset($step['href'])) {
					$this->assertNotSame('', (string)$step['href']);
					$this->assertNotSame('', trim((string)($step['linkLabel'] ?? '')), "$pageId step $i linkLabel");
				}
			}
		}
	}

	public function testUnknownPageReturnsNull(): void
	{
		$this->assertNull($this->catalog->forPage('access-denied'));
		$this->assertNull($this->catalog->forPage('nope'));
	}

	public function testCompanyDetailHasNoQuickStart(): void
	{
		$this->assertNotContains('company-detail', PageQuickStartCatalog::requiredPageIds());
		$this->assertNull($this->catalog->forPage('company-detail'));
	}

	public function testWorkloadAliasesToTeam(): void
	{
		$team = $this->catalog->forPage('team');
		$workload = $this->catalog->forPage('workload');
		$this->assertNotNull($team);
		$this->assertNotNull($workload);
		$this->assertSame($team['key'], $workload['key']);
		$this->assertSame($team['lead'], $workload['lead']);
	}

	public function testPulseHonestyAvoidsGobdJargon(): void
	{
		$card = $this->catalog->forPage('pulse');
		$this->assertNotNull($card);
		$blob = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->assertIsString($blob);
		$this->assertStringNotContainsString('GoBD', $blob);
		$this->assertStringNotContainsString('Ops overview', $blob);
		$this->assertStringNotContainsString('tax filing', $blob);
		$this->assertStringNotContainsString('day-to-day ops', $blob);
		$this->assertStringContainsString('Open Money', $blob);
		$this->assertStringContainsString('/apps/customercheck/money', $blob);
	}

	public function testMoneyQuickStartAvoidsTaxLecture(): void
	{
		$card = $this->catalog->forPage('money');
		$this->assertNotNull($card);
		$blob = json_encode($card, JSON_UNESCAPED_UNICODE);
		$this->assertIsString($blob);
		$this->assertStringNotContainsString('GoBD', $blob);
		$this->assertStringNotContainsString('tax filing', $blob);
		$this->assertStringNotContainsString('Ops overview', $blob);
		$this->assertStringContainsString('overdue', strtolower($blob));
	}

	public function testDismissKeysAreUnique(): void
	{
		$keys = [];
		foreach (PageQuickStartCatalog::requiredPageIds() as $pageId) {
			$card = $this->catalog->forPage($pageId);
			$this->assertNotNull($card);
			$keys[] = $card['key'];
		}
		$this->assertSame(count($keys), count(array_unique($keys)));
	}
}
