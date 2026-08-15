<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\DisplayLabelService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Humans never see raw PKs — labels stay name/slug based.
 */
final class DisplayLabelServiceTest extends TestCase
{
	private DisplayLabelService $labels;

	protected function setUp(): void
	{
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $s, array $args = []) {
			if ($args === []) {
				return $s;
			}
			// Mirror Nextcloud %s / %1$s substitution for tests that pass args.
			$out = $s;
			$i = 0;
			foreach ($args as $arg) {
				$i++;
				$out = str_replace('%' . $i . '$s', (string)$arg, $out);
				$out = preg_replace('/%s/', (string)$arg, $out, 1) ?? $out;
			}
			return $out;
		});
		$l10n->method('l')->willReturnCallback(static function (string $type, $data) {
			// Mirror Nextcloud L10N::l: only mutable DateTime is accepted as object.
			// DateTimeImmutable falls through to (int)$obj === 1 → epoch.
			if ($data instanceof \DateTimeImmutable) {
				$data = 1;
			}
			if ($data instanceof \DateTime) {
				return $data->format('Y-m-d H:i');
			}
			if (is_int($data) || (is_string($data) && is_numeric($data))) {
				$dt = (new \DateTime('@' . (int)$data))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

				return $dt->format('Y-m-d H:i');
			}

			return (string)$data;
		});
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $args = []) {
			$template = $count === 1 ? $singular : $plural;
			return str_replace('%n', (string)$count, $template);
		});
		$this->labels = new DisplayLabelService($l10n);
	}

	public function testUnknownCompanyNeverUsesHashId(): void
	{
		$this->assertSame('Unknown company', $this->labels->unknownCompany());
		$this->assertSame('Unknown company', $this->labels->companyOrUnknown(''));
		$this->assertSame('Unknown company', $this->labels->companyOrUnknown('   '));
		$this->assertSame('Acme', $this->labels->companyOrUnknown('Acme'));
		$this->assertStringNotContainsString('#', $this->labels->unknownCompany());
	}

	public function testStatusKindAndActivityLabelsAreHuman(): void
	{
		$this->assertSame('Lead', $this->labels->companyStatus('lead'));
		$this->assertSame('Active', $this->labels->companyStatus('active'));
		$this->assertSame('License', $this->labels->agreementKind('license'));
		$this->assertSame('Partner', $this->labels->agreementKind('partner'));
		$this->assertSame('Call', $this->labels->activityType('call'));
		$this->assertSame('Email follow-up', $this->labels->activityType('email'));
		$this->assertSame('One-time', $this->labels->agreementKind('onetime'));
		$this->assertSame('Draft', $this->labels->agreementStatus('draft'));
		$this->assertSame('Expiring', $this->labels->agreementStatus('expiring'));
		$this->assertSame('No invoice', $this->labels->invoiceStatus('none'));
		$this->assertSame('Overdue', $this->labels->invoiceStatus('overdue'));
		$this->assertSame('Quoted', $this->labels->dealStage('quoted'));
		$this->assertSame('Won', $this->labels->dealStage('won'));
		$this->assertSame('Paid up', $this->labels->billingState('ok'));
		$this->assertSame('Needs attention', $this->labels->billingState('attention'));
		$this->assertSame('Overdue', $this->labels->billingState('critical'));
		$this->assertSame('Unknown', $this->labels->billingState('unknown'));
		$this->assertSame('No next action', $this->labels->chaseReason('no_next_action'));
		$this->assertSame('New lead waiting too long', $this->labels->chaseReason('lead_sla'));
		$this->assertSame('Needs attention', $this->labels->chaseReason(''));
		$this->assertSame('Quote follow-up due', $this->labels->chaseReason('quote_followup_due'));
		$this->assertSame('Renewal soon', $this->labels->pulseAttentionKind('renewal_hot'));
		$this->assertSame('Overdue invoice', $this->labels->pulseAttentionKind('overdue_invoice'));
		$this->assertSame('Plan stalled', $this->labels->pulseAttentionKind('plan_stalled'));
		$this->assertSame('Plan at risk', $this->labels->pulseAttentionKind('plan_at_risk'));
		$this->assertSame('1–30 days', $this->labels->agingBucket('1-30'));
		$this->assertSame('90+ days', $this->labels->agingBucket('90+'));
		$this->assertNull($this->labels->agingBucket(null));
		$this->assertNull($this->labels->agingBucket(''));
		$this->assertSame('5 days past due', $this->labels->daysPastDueLabel(5));
		$this->assertSame('1 day past due', $this->labels->daysPastDueLabel(1));
		$this->assertNull($this->labels->daysPastDueLabel(null));
		$this->assertSame(
			'Unbilled hours are not ready yet — we will not guess zero.',
			$this->labels->unbilledHint('honesty_blocked')
		);
		$this->assertSame(
			'ProjectCheck is off — unbilled hours cannot be shown.',
			$this->labels->unbilledHint('unavailable')
		);
		$this->assertSame(
			'Open billable hours from ProjectCheck that are not invoiced yet.',
			$this->labels->unbilledHint('ok')
		);
		$this->assertStringNotContainsString('Not available yet', $this->labels->unbilledHint('honesty_blocked'));
		$this->assertStringNotContainsString('honesty_blocked', $this->labels->unbilledHint('honesty_blocked'));
		$this->assertSame('Stalled', $this->labels->planHealth('stalled'));
		$this->assertSame('At risk', $this->labels->planHealth('at_risk'));
		$this->assertSame('On track', $this->labels->planHealth('on_track'));
		$this->assertSame('High', $this->labels->planPriority('p1'));
		$this->assertSame('Normal', $this->labels->planPriority('p2'));
		$this->assertSame('Low', $this->labels->planPriority('p3'));
		$this->assertSame('Active', $this->labels->planStatus('active'));
		$this->assertSame('Paused', $this->labels->planStatus('paused'));
		$this->assertSame('Done', $this->labels->planStatus('done'));
		$this->assertSame('Renewal', $this->labels->todayKind('agreement_expiry'));
		$this->assertSame('Stale deal', $this->labels->todayKind('deal_stale'));
		$this->assertSame('Overdue activity', $this->labels->todayKind('activity_overdue'));
		$this->assertSame('Committed', $this->labels->commitmentStatus('committed'));
		$this->assertSame('Abandoned', $this->labels->commitmentStatus('abandoned'));
		$this->assertSame('Missing champion', $this->labels->coverageFlag('champion_gap'));
		$this->assertSame('Missing budget decision-maker', $this->labels->coverageFlag('buyer_gap'));
		$this->assertSame('Follow-up overdue', $this->labels->coverageFlag('touch_overdue'));
		$this->assertSame('Budget decision-maker', $this->labels->coverageMissing('economic_buyer'));
		$this->assertSame('Day-to-day or technical contact', $this->labels->coverageMissing('day_to_day_or_technical'));
		$this->assertSame('Budget decision-maker', $this->labels->buyingRole('economic_buyer'));
		$this->assertSame('Day-to-day contact', $this->labels->buyingRole('day_to_day'));
		$this->assertSame('Technical contact', $this->labels->buyingRole('technical'));
		$this->assertSame('Email logged', $this->labels->timelineEventType('email.logged'));
		$this->assertSame('Deal stage changed', $this->labels->timelineEventType('deal.stage'));
		$this->assertSame('Company created', $this->labels->timelineEventType('company.created'));
		$this->assertSame('Agreement created', $this->labels->timelineEventType('agreement.created'));
		$this->assertSame('Update', $this->labels->timelineEventType('totally.unknown.event'));
		$this->assertStringNotContainsString('.', $this->labels->timelineEventType('email.logged'));
		$this->assertSame('System', $this->labels->timelineActor('system'));
		$this->assertSame('System', $this->labels->timelineActor(''));
		$this->assertSame('Webhook', $this->labels->timelineActor('webhook'));
		$this->assertSame('admin', $this->labels->timelineActor('admin'));
		$when = $this->labels->timelineWhen(new \DateTimeImmutable('2026-07-28T12:50:26+00:00'));
		$this->assertNotSame('', $when);
		$this->assertStringNotContainsString('T12:50', $when);
		$this->assertSame('Left', $this->labels->personStatus('left'));
		$this->assertSame('Logged email', $this->labels->communicationKind('email_touch'));
		$this->assertSame('Inbound', $this->labels->mailDirection('inbound'));
		$this->assertSame('Open', $this->labels->promiseStatus('open'));
		$this->assertSame('Blocked', $this->labels->mapStepStatus('blocked'));
		$opts = $this->labels->activityTypeOptions();
		$this->assertCount(4, $opts);
		$this->assertSame('todo', $opts[3]['value']);
		$this->assertSame('To-do', $opts[3]['label']);
	}

	public function testSiblingOptionsSortByLabelAndSkipInvalid(): void
	{
		$opts = $this->labels->siblingCustomerOptions([
			['id' => 2, 'name' => 'Zulu'],
			['id' => 0, 'name' => 'Bad'],
			['id' => 1, 'name' => 'Alpha'],
			['id' => 1, 'name' => 'Dup'],
			['id' => 3, 'name' => ''],
		]);
		$this->assertCount(3, $opts);
		$this->assertSame(1, $opts[0]['id']);
		$this->assertSame('Alpha', $opts[0]['label']);
		$this->assertSame(3, $opts[1]['id']);
		$this->assertSame('Untitled customer', $opts[1]['label']);
		$this->assertSame(2, $opts[2]['id']);
	}

	public function testEnsureLinkedSiblingOptionAddsStaleWithoutShowingId(): void
	{
		$opts = $this->labels->ensureLinkedSiblingOption(
			[['id' => 1, 'label' => 'Alpha']],
			99,
		);
		$this->assertSame(99, $opts[0]['id']);
		$this->assertSame('Linked customer (no longer listed)', $opts[0]['label']);
		$this->assertStringNotContainsString('99', $opts[0]['label']);

		$same = $this->labels->ensureLinkedSiblingOption([['id' => 5, 'label' => 'Keep']], 5);
		$this->assertCount(1, $same);
		$this->assertSame('Keep', $same[0]['label']);
	}

	public function testImportMatchLabelNeverShowsHashId(): void
	{
		$this->assertSame(
			'Acme GmbH (acme-gmbh)',
			$this->labels->importMatchLabel([
				'companyId' => 7,
				'companyName' => 'Acme GmbH',
				'companySlug' => 'acme-gmbh',
			]),
		);
		$this->assertSame(
			'Beta (beta-co)',
			$this->labels->importMatchLabel([
				'companyId' => 3,
				'candidates' => [['id' => 3, 'name' => 'Beta', 'slug' => 'beta-co', 'via' => 'name']],
			]),
		);
		$this->assertSame('new-slug', $this->labels->importMatchLabel(['suggestedSlug' => 'new-slug']));
		$this->assertSame('—', $this->labels->importMatchLabel([]));
		$label = $this->labels->importMatchLabel(['companyId' => 42]);
		$this->assertStringNotContainsString('#', $label);
		$this->assertStringNotContainsString('42', $label);
	}

	public function testFormatDateAndDateTimeAreLocaleAware(): void
	{
		$this->assertSame('—', $this->labels->formatDate(null));
		$this->assertSame('—', $this->labels->formatDate(''));
		$this->assertSame('—', $this->labels->formatDate('—'));
		$this->assertSame('2026-08-03 12:00', $this->labels->formatDate('2026-08-03'));
		$this->assertSame('not-a-date', $this->labels->formatDate('not-a-date'));
		$this->assertSame('2026-08-03 14:30', $this->labels->formatDateTime('2026-08-03T14:30:00+00:00'));
		$this->assertSame('—', $this->labels->formatDateTime(''));
		// Date-only stays on the same calendar day even when formatted via datetime mock.
		$this->assertStringStartsWith('2026-08-03', $this->labels->formatDate('2026-08-03'));
	}

	public function testFormatDateNeverEmitsUnixEpochForValidYmd(): void
	{
		$out = $this->labels->formatDate('2026-09-13');
		$this->assertStringContainsString('2026-09-13', $out);
		$this->assertStringNotContainsString('1970-01-01', $out);
		$this->assertStringNotContainsString('1970', $out);
	}

	public function testTimelineWhenAcceptsDateTimeImmutable(): void
	{
		$at = new \DateTimeImmutable('2026-09-13T15:30:00+00:00');
		$out = $this->labels->timelineWhen($at);
		$this->assertStringContainsString('2026-09-13', $out);
		$this->assertStringNotContainsString('1970', $out);
	}

	public function testL10nDatePassesUnixTimestampNotImmutable(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/DisplayLabelService.php');
		$this->assertStringContainsString('function l10nDate', $src);
		$this->assertStringContainsString('$dt->getTimestamp()', $src);
		$this->assertStringNotContainsString("\$this->l10n->l('date', \$dt)", $src);
		$this->assertStringNotContainsString("\$this->l10n->l('datetime', \$dt)", $src);
		$this->assertStringNotContainsString("\$this->l10n->l('datetime', \$at)", $src);
	}
}
