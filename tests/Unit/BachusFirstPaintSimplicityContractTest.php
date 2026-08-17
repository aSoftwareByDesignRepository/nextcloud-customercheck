<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus first-paint simplicity — one tutorial owner, one CTA owner, no fake Log talk.
 */
final class BachusFirstPaintSimplicityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testRoleCueRemovedForCalmFirstPaint(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringNotContainsString('How to use this page', $html);
		$this->assertStringNotContainsString('crm-360-role', $html);
		$this->assertStringContainsString('data-crm-360-focus="1"', $html);
	}

	public function testEmptyStatesDeferCtasToCoach(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		// People header Add person stays visible (identity + roster). Empty-state duplicate still defers to the coach.
		$this->assertStringNotContainsString('showWelcomeCoach && $personsEmpty', $html);
		$this->assertStringContainsString('!$showWelcomeCoach', $html);
		$this->assertStringContainsString('id="crm-add-person"', $html);
		// Deals are on their own focus tab — coach must not strip Add deal from that panel.
		$this->assertDoesNotMatchRegularExpression(
			'/showWelcomeCoach && \$dealsEmpty[\s\S]{0,180}hidden/',
			$html,
		);
		$dealsStart = strpos($html, 'id="crm-deals"');
		$this->assertNotFalse($dealsStart);
		$dealsChunk = substr($html, (int)$dealsStart, 1400);
		$this->assertStringContainsString('id="crm-add-deal"', $dealsChunk);
		$this->assertStringNotContainsString('$showWelcomeCoach && $dealsEmpty', $dealsChunk);
		$this->assertStringContainsString('data-crm-trigger="crm-add-deal"', $dealsChunk);
	}

	public function testCoachWithoutEmailOffersCallTomorrow(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString('data-crm-trigger="crm-schedule-callback"', $html);
		$this->assertStringContainsString('One tap schedules a call for tomorrow — no email needed yet.', $html);
		$this->assertStringContainsString('$hasPersonWithEmail', $html);
	}

	public function testLogTalkOpensLogEmailNotHashScroll(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString("data-crm-trigger=\"crm-log-email\"><?php p(\$l->t('Log talk')); ?></button>", $html);
		$this->assertStringNotContainsString("href=\"#crm-communication\"><?php p(\$l->t('Log talk')); ?></a>", $html);
	}

	public function testLogEmailDialogNeverListsPeopleWithoutEmail(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString('const withEmail = persons.filter', $js);
		$this->assertStringContainsString("t('Add an email first')", $js);
		$this->assertStringContainsString("t('Call tomorrow')", $js);
		$this->assertStringContainsString('crm-schedule-callback', $js);
	}

	public function testQuickStartDoesNotCompeteWithWelcomeCoach(): void
	{
		$this->assertNotContains(
			'company-detail',
			\OCA\CustomerCheck\Service\PageQuickStartCatalog::requiredPageIds(),
		);
		$qs = (string)file_get_contents($this->root . '/lib/Service/PageQuickStartCatalog.php');
		$this->assertStringNotContainsString("'company-detail' => [", $qs);
	}
}
