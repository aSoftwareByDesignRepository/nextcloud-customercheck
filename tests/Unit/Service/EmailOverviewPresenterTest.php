<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\EmailOverviewPresenter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class EmailOverviewPresenterTest extends TestCase
{
	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return $l;
	}

	public function testWithWhomUsesToForOutboundAndFromForInbound(): void
	{
		$this->assertSame('them@ex.com', EmailOverviewPresenter::withWhom('outbound', 'me@ex.com', 'them@ex.com'));
		$this->assertSame('them@ex.com', EmailOverviewPresenter::withWhom('inbound', 'them@ex.com', 'me@ex.com'));
	}

	public function testWithWhomFallsBackWhenPreferredSideEmpty(): void
	{
		$this->assertSame('me@ex.com', EmailOverviewPresenter::withWhom('outbound', 'me@ex.com', ''));
		$this->assertSame('me@ex.com', EmailOverviewPresenter::withWhom('inbound', '', 'me@ex.com'));
	}

	public function testWithLabelPrefersPersonName(): void
	{
		$map = ['ada@acme.example' => 'Ada Lovelace'];
		$this->assertSame('Ada Lovelace', EmailOverviewPresenter::withLabel('Ada@acme.example', $map));
		$this->assertSame('unknown@ex.com', EmailOverviewPresenter::withLabel('unknown@ex.com', $map));
	}

	public function testSourceAndDirectionLabels(): void
	{
		$l = $this->l10n();
		$this->assertSame('Opened compose', EmailOverviewPresenter::sourceLabel($l, 'compose'));
		$this->assertSame('From Mail', EmailOverviewPresenter::sourceLabel($l, 'mail_app'));
		$this->assertSame('Logged', EmailOverviewPresenter::sourceLabel($l, 'manual'));
		$this->assertSame('You wrote', EmailOverviewPresenter::directionLabel($l, 'outbound'));
		$this->assertSame('They wrote', EmailOverviewPresenter::directionLabel($l, 'inbound'));
	}

	public function testWhenLabelFormatsDate(): void
	{
		$this->assertSame('—', EmailOverviewPresenter::whenLabel(null));
		$this->assertSame('2026-07-25', EmailOverviewPresenter::whenLabel(new \DateTimeImmutable('2026-07-25 15:00:00')));
	}

	public function testWorkflowStepsAlwaysThreeAndModeAware(): void
	{
		$l = $this->l10n();
		$manual = EmailOverviewPresenter::workflowSteps($l, 'manual_only');
		$deep = EmailOverviewPresenter::workflowSteps($l, 'deep_link');
		$this->assertCount(3, $manual);
		$this->assertCount(3, $deep);
		$this->assertStringContainsString('inbox', strtolower($manual[2]['text']));
		$this->assertStringContainsString('Mail', $deep[2]['text']);
	}
}
