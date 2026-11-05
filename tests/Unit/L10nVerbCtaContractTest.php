<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 'Open'/'View' verb-vs-adjective contract — the msgid 'Open' is status-
 * adjective only in every catalog (de 'Offen', fr 'Ouvert', …). Any control
 * that navigates/expands must bind the verb msgid 'View' (de 'Anzeigen').
 * Regression pin for cuc-critic-l10n-dual-role-open-cta-bindings-unswept:
 * variable-indirect bindings escaped the l10n-dual-role gate's enumeration.
 */
final class L10nVerbCtaContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	private function src(string $rel): string
	{
		return (string)file_get_contents($this->root . '/' . $rel);
	}

	public function testRowOpenPartialDefaultIsView(): void
	{
		$src = $this->src('templates/common/crm-row-open.php');
		$this->assertStringContainsString("\$__label = \$l->t('View');", $src);
		$this->assertStringNotContainsString("t('Open')", $src);
	}

	public function testRowOpenCallersBindView(): void
	{
		foreach ([
			'templates/companies.php',
			'templates/activities.php',
			'templates/money.php',
			'templates/agreements.php',
			'templates/pipeline.php',
			'templates/company-detail.php',
		] as $rel) {
			$src = $this->src($rel);
			$this->assertStringNotContainsString(
				"\$crmRowOpenLabel = \$l->t('Open')", $src, $rel);
		}
	}

	public function testDisclosurePairIsViewClose(): void
	{
		$src = $this->src('templates/common/crm-collapsible-disclosure.php');
		$this->assertStringContainsString("p(\$l->t('View'))", $src);
		$this->assertStringContainsString("p(\$l->t('Close'))", $src);
		$this->assertStringNotContainsString("t('Open')", $src);
	}

	public function testGlanceTileCtasUseView(): void
	{
		$src = $this->src('lib/Support/GlanceDensityPresenter.php');
		$this->assertSame(3, substr_count($src, "\$l->t('View') : \$l->t('Link')"));
		$this->assertStringNotContainsString("\$l->t('Open')", $src);

		$list = $this->src('templates/common/crm-glance-density-list.php');
		$this->assertStringContainsString("\$l->t('View')", $list);
		$this->assertStringNotContainsString("t('Open')", $list);
	}

	public function testTodayCtaDefaultIsView(): void
	{
		$src = $this->src('templates/today.php');
		$this->assertStringContainsString("default => \$l->t('View'),", $src);
	}

	public function testPersonaCtaFallbackIsView(): void
	{
		$js = $this->src('js/pages/persona.js');
		$this->assertStringContainsString("it.cta || t('View')", $js);
		$this->assertStringNotContainsString("it.cta || t('Open')", $js);
	}

	public function testOpenRemainsOnlyAtAdjectiveSites(): void
	{
		// Status-scope filter link + <option> (activities) and status-label
		// maps are the only sanctioned 'Open' bindings.
		$activities = $this->src('templates/activities.php');
		$this->assertSame(2, substr_count($activities, "t('Open')"));

		foreach ([
			'lib/Service/DisplayLabelService.php' => 2,
			'lib/Service/DealChecklistService.php' => 1,
			'js/pages/company-detail.js' => 1,
		] as $rel => $expected) {
			$this->assertSame(
				$expected, substr_count($this->src($rel), "t('Open')"), $rel);
		}
	}
}
