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

	public function testRoleCueYieldsToWelcomeCoach(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString('$showWelcomeCoach = $canWritePage && ($personsEmpty || $dealsEmpty)', $html);
		$this->assertStringContainsString('$showRoleCue = !$showWelcomeCoach', $html);
		$this->assertStringContainsString('if ($showRoleCue):', $html);
		$this->assertStringContainsString('How to use this page', $html);
		$this->assertStringContainsString('Buttons to add or change stay hidden', $html);
		$this->assertStringNotContainsString('Look-only role', $html);
	}

	public function testEmptyStatesDeferCtasToCoach(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString('!$showWelcomeCoach', $html);
		$this->assertMatchesRegularExpression(
			'/showWelcomeCoach && \$personsEmpty[\s\S]{0,120}hidden/',
			$html,
		);
		$this->assertMatchesRegularExpression(
			'/showWelcomeCoach && \$dealsEmpty[\s\S]{0,120}hidden/',
			$html,
		);
		$this->assertGreaterThanOrEqual(2, substr_count($html, '!$showWelcomeCoach'));
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
}
