<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCP\IL10N;

/**
 * Human-readable labels for CRM UI — never surface raw primary keys to people.
 */
final class DisplayLabelService
{
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	public function unknownCompany(): string
	{
		return $this->l10n->t('Unknown company');
	}

	public function companyOrUnknown(?string $name): string
	{
		$name = trim((string)$name);
		return $name !== '' ? $name : $this->unknownCompany();
	}

	public function unlabelledTicket(): string
	{
		return $this->l10n->t('Unlabelled ticket');
	}

	public function unlabelledInvoice(): string
	{
		return $this->l10n->t('Unlabelled invoice');
	}

	public function unlabelledProject(): string
	{
		return $this->l10n->t('Unlabelled project');
	}

	public function untitledSiblingCustomer(): string
	{
		return $this->l10n->t('Untitled customer');
	}

	/**
	 * Billing / money integrity state — COMMAND-CENTER-SPEC §7.2 (human labels).
	 */
	public function billingState(string $state): string
	{
		return match ($state) {
			'ok' => $this->l10n->t('Paid up'),
			'attention' => $this->l10n->t('Needs attention'),
			'critical' => $this->l10n->t('Overdue'),
			'unknown' => $this->l10n->t('Unknown'),
			default => $this->l10n->t('Unknown'),
		};
	}

	public function companyStatus(string $status): string
	{
		return match ($status) {
			'lead' => $this->l10n->t('Lead'),
			'active' => $this->l10n->t('Active'),
			'paused' => $this->l10n->t('Paused'),
			'churned' => $this->l10n->t('Churned'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	/**
	 * Chase primary reason codes — never show snake_case in the UI.
	 */
	public function chaseReason(string $reason): string
	{
		return match ($reason) {
			'lead_sla' => $this->l10n->t('New lead waiting too long'),
			'close_overdue' => $this->l10n->t('Close date overdue'),
			'no_next_action' => $this->l10n->t('No next action'),
			'quote_followup_due' => $this->l10n->t('Quote follow-up due'),
			'close_this_week' => $this->l10n->t('Closing this week'),
			'stale_stage' => $this->l10n->t('Stage went stale'),
			'unowned' => $this->l10n->t('No owner'),
			default => $reason !== '' ? $reason : $this->l10n->t('Needs attention'),
		};
	}

	/**
	 * Pulse attention kind — never show snake_case in the UI.
	 */
	public function pulseAttentionKind(string $kind): string
	{
		return match ($kind) {
			'renewal_hot' => $this->l10n->t('Renewal soon'),
			'overdue_invoice' => $this->l10n->t('Overdue invoice'),
			'plan_stalled' => $this->l10n->t('Plan stalled'),
			'plan_at_risk' => $this->l10n->t('Plan at risk'),
			default => $kind !== '' ? $kind : $this->l10n->t('Attention'),
		};
	}

	/**
	 * InvoiceCheck facade v3 aging bucket — text+chip only when present; never invent.
	 */
	public function agingBucket(?string $bucket): ?string
	{
		if ($bucket === null) {
			return null;
		}
		$bucket = trim($bucket);
		if ($bucket === '') {
			return null;
		}
		return match ($bucket) {
			'current' => $this->l10n->t('Current'),
			'1-30' => $this->l10n->t('1–30 days'),
			'31-60' => $this->l10n->t('31–60 days'),
			'61-90' => $this->l10n->t('61–90 days'),
			'90+' => $this->l10n->t('90+ days'),
			default => $bucket,
		};
	}

	/**
	 * Days past due accompanying aging chip (null-safe).
	 */
	public function daysPastDueLabel(?int $days): ?string
	{
		if ($days === null) {
			return null;
		}
		return $this->l10n->n('%n day past due', '%n days past due', $days);
	}

	/**
	 * Unbilled Pulse metric hint — granny-readable; never machine states in chrome.
	 */
	public function unbilledHint(string $state): string
	{
		return match ($state) {
			'ok' => $this->l10n->t('Open billable hours from ProjectCheck that are not invoiced yet.'),
			'unavailable' => $this->l10n->t('ProjectCheck is off — unbilled hours cannot be shown.'),
			'honesty_blocked' => $this->l10n->t('Unbilled hours are not ready yet — we will not guess zero.'),
			default => $this->l10n->t('Unbilled hours unknown.'),
		};
	}

	/**
	 * Today urgency kind — never show snake_case in the UI (§19).
	 */
	public function todayKind(string $kind): string
	{
		return match ($kind) {
			'agreement_expiry' => $this->l10n->t('Renewal'),
			'ticket_waiting' => $this->l10n->t('Waiting ticket'),
			'activity_overdue' => $this->l10n->t('Overdue activity'),
			'activity_due' => $this->l10n->t('Due today'),
			'deal_stale' => $this->l10n->t('Stale deal'),
			default => $kind !== '' ? $kind : $this->l10n->t('Today item'),
		};
	}

	public function planHealth(string $health): string
	{
		return match ($health) {
			'healthy' => $this->l10n->t('Healthy'),
			'on_track' => $this->l10n->t('On track'),
			'stalled' => $this->l10n->t('Stalled'),
			'at_risk' => $this->l10n->t('At risk'),
			'ok' => $this->l10n->t('OK'),
			'unknown' => $this->l10n->t('Unknown'),
			default => $health !== '' ? $health : $this->l10n->t('Unknown'),
		};
	}

	public function planPriority(string $priority): string
	{
		return match (strtolower(trim($priority))) {
			'p1' => $this->l10n->t('High'),
			'p2' => $this->l10n->t('Normal'),
			'p3' => $this->l10n->t('Low'),
			default => $priority !== '' ? $priority : $this->l10n->t('Normal'),
		};
	}

	public function planStatus(string $status): string
	{
		return match (strtolower(trim($status))) {
			'active' => $this->l10n->t('Active'),
			'paused' => $this->l10n->t('Paused'),
			'done' => $this->l10n->t('Done'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	public function commitmentStatus(string $status): string
	{
		return match ($status) {
			'committed' => $this->l10n->t('Committed'),
			'done' => $this->l10n->t('Done'),
			'missed' => $this->l10n->t('Missed'),
			'carried' => $this->l10n->t('Carried'),
			'abandoned' => $this->l10n->t('Abandoned'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	public function coverageFlag(string $flag): string
	{
		return match ($flag) {
			'champion_gap' => $this->l10n->t('Missing champion'),
			'buyer_gap' => $this->l10n->t('Missing budget decision-maker'),
			'champion_left' => $this->l10n->t('Champion left'),
			'touch_overdue' => $this->l10n->t('Follow-up overdue'),
			'promise_overdue' => $this->l10n->t('Promise overdue'),
			'partner_hours_hot' => $this->l10n->t('Partner hours running low'),
			'renewal_hot' => $this->l10n->t('Renewal soon'),
			default => $flag !== '' ? $flag : $this->l10n->t('Coverage issue'),
		};
	}

	/**
	 * Coverage gap slot keys from CoverageLogic::$missing — never show snake_case.
	 */
	public function coverageMissing(string $slot): string
	{
		return match ($slot) {
			'economic_buyer' => $this->l10n->t('Budget decision-maker'),
			'champion' => $this->l10n->t('Champion'),
			'day_to_day_or_technical' => $this->l10n->t('Day-to-day or technical contact'),
			default => $slot !== '' ? $slot : $this->l10n->t('Coverage issue'),
		};
	}

	/**
	 * Timeline audit event types — never show dotted machine keys in the UI.
	 */
	public function timelineEventType(string $type): string
	{
		return match ($type) {
			'company.created' => $this->l10n->t('Company created'),
			'company.updated' => $this->l10n->t('Company updated'),
			'company.archived' => $this->l10n->t('Company archived'),
			'company.restored' => $this->l10n->t('Company restored'),
			'company.merged' => $this->l10n->t('Companies merged'),
			'company.pc_linked' => $this->l10n->t('Linked to ProjectCheck'),
			'company.tc_linked' => $this->l10n->t('Linked to TicketCheck'),
			'agreement.created' => $this->l10n->t('Agreement created'),
			'agreement.status' => $this->l10n->t('Agreement status changed'),
			'agreement.cancelled' => $this->l10n->t('Agreement cancelled'),
			'agreement.superseded' => $this->l10n->t('Agreement replaced'),
			'reminder.sent' => $this->l10n->t('Renewal reminder sent'),
			'reminder.skipped' => $this->l10n->t('Renewal reminder skipped'),
			'deal.stage' => $this->l10n->t('Deal stage changed'),
			'deal_checklist.assigned' => $this->l10n->t('Checklist task assigned'),
			'deal_checklist.done' => $this->l10n->t('Checklist task done'),
			'hours.logged' => $this->l10n->t('Hours logged'),
			'import.linked' => $this->l10n->t('Import linked'),
			'email.logged' => $this->l10n->t('Email logged'),
			'email.composed' => $this->l10n->t('Email composed'),
			'mail_sent' => $this->l10n->t('Email sent'),
			'review.assigned' => $this->l10n->t('Item assigned'),
			'invoice.paid' => $this->l10n->t('Invoice marked paid'),
			'invoice.paid.ambiguous' => $this->l10n->t('Invoice payment needs review'),
			'lead.webhook' => $this->l10n->t('Lead received'),
			'order.webhook' => $this->l10n->t('Order received'),
			'digest.sent' => $this->l10n->t('Weekly digest sent'),
			'quota.alert' => $this->l10n->t('Partner hours alert'),
			'gdpr.erased' => $this->l10n->t('Personal data erased'),
			'note' => $this->l10n->t('Note'),
			default => $this->l10n->t('Update'),
		};
	}

	/**
	 * Timeline actor UIDs / system roles — never show empty; soft-label known roles.
	 */
	public function timelineActor(string $actor): string
	{
		$actor = trim($actor);
		return match ($actor) {
			'', 'system' => $this->l10n->t('System'),
			'webhook' => $this->l10n->t('Webhook'),
			default => $actor,
		};
	}

	/**
	 * Locale-aware when-label for timeline rows (ISO stays on &lt;time datetime&gt;).
	 */
	public function timelineWhen(?\DateTimeInterface $at): string
	{
		if ($at === null) {
			return '';
		}

		return $this->l10nDate('datetime', $at);
	}

	/**
	 * Locale-aware calendar date for Y-m-d (or parseable) strings shown in tables.
	 *
	 * Date-only values are anchored at noon so user-timezone formatting cannot
	 * shift the calendar day backward (midnight UTC → previous evening locally).
	 *
	 * Nextcloud {@see IL10N::l()} only accepts mutable {@see \DateTime} or a Unix
	 * timestamp — {@see \DateTimeImmutable} falls through to `(int)$obj` (= 1) and
	 * renders as 1970-01-01. Always pass a timestamp.
	 */
	public function formatDate(?string $ymd): string
	{
		$raw = trim((string)$ymd);
		if ($raw === '' || $raw === '—') {
			return '—';
		}
		try {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
				$dt = new \DateTimeImmutable($raw . 'T12:00:00');
			} else {
				$dt = new \DateTimeImmutable($raw);
			}
		} catch (\Exception) {
			return $raw;
		}

		return $this->l10nDate('date', $dt);
	}

	/**
	 * Locale-aware date+time for ISO timestamps in feeds and glances.
	 */
	public function formatDateTime(?string $iso): string
	{
		$raw = trim((string)$iso);
		if ($raw === '' || $raw === '—') {
			return '—';
		}
		try {
			$dt = new \DateTimeImmutable($raw);
		} catch (\Exception) {
			return $raw;
		}

		return $this->l10nDate('datetime', $dt);
	}

	/**
	 * @param 'date'|'datetime'|'time' $type
	 */
	private function l10nDate(string $type, \DateTimeInterface $dt): string
	{
		return (string)$this->l10n->l($type, $dt->getTimestamp());
	}

	public function personStatus(string $status): string
	{
		return match ($status) {
			'active' => $this->l10n->t('Active'),
			'left' => $this->l10n->t('Left'),
			'unknown' => $this->l10n->t('Unknown'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	public function communicationKind(string $kind): string
	{
		return match ($kind) {
			'email_touch' => $this->l10n->t('Logged email'),
			'mail_related' => $this->l10n->t('Related mail'),
			'shared_mail' => $this->l10n->t('Shared mail'),
			'activity' => $this->l10n->t('Activity'),
			default => $kind !== '' ? $kind : $this->l10n->t('Touch'),
		};
	}

	public function mailDirection(string $direction): string
	{
		return match ($direction) {
			'inbound' => $this->l10n->t('Inbound'),
			'outbound' => $this->l10n->t('Outbound'),
			default => $direction !== '' ? $direction : '',
		};
	}

	public function promiseStatus(string $status): string
	{
		return match ($status) {
			'open' => $this->l10n->t('Open'),
			'done' => $this->l10n->t('Done'),
			'broken' => $this->l10n->t('Broken'),
			'cancelled' => $this->l10n->t('Cancelled'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	public function mapStepStatus(string $status): string
	{
		return match ($status) {
			'open' => $this->l10n->t('Open'),
			'done' => $this->l10n->t('Done'),
			'blocked' => $this->l10n->t('Blocked'),
			'skipped' => $this->l10n->t('Skipped'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	public function agreementKind(string $kind): string
	{
		return match ($kind) {
			'license' => $this->l10n->t('License'),
			'partner' => $this->l10n->t('Partner'),
			'onetime' => $this->l10n->t('One-time'),
			default => $kind !== '' ? $kind : $this->l10n->t('Agreement'),
		};
	}

	public function agreementStatus(string $status): string
	{
		return match ($status) {
			'draft' => $this->l10n->t('Draft'),
			'active' => $this->l10n->t('Active'),
			'expiring' => $this->l10n->t('Expiring'),
			'expired' => $this->l10n->t('Expired'),
			'superseded' => $this->l10n->t('Superseded'),
			'cancelled' => $this->l10n->t('Cancelled'),
			'completed' => $this->l10n->t('Completed'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	public function invoiceStatus(string $status): string
	{
		return match ($status) {
			'none' => $this->l10n->t('No invoice'),
			'sent' => $this->l10n->t('Sent'),
			'paid' => $this->l10n->t('Paid'),
			'overdue' => $this->l10n->t('Overdue'),
			default => $status !== '' ? $status : $this->l10n->t('Unknown'),
		};
	}

	public function dealStage(string $stage): string
	{
		return match ($stage) {
			'lead' => $this->l10n->t('Lead'),
			'contacted' => $this->l10n->t('Contacted'),
			'quoted' => $this->l10n->t('Quoted'),
			'negotiation' => $this->l10n->t('Negotiation'),
			'won' => $this->l10n->t('Won'),
			'lost' => $this->l10n->t('Lost'),
			default => $stage !== '' ? $stage : $this->l10n->t('Unknown'),
		};
	}

	/**
	 * @return list<array{value: string, label: string}>
	 */
	public function dealStageOptions(): array
	{
		$out = [];
		foreach (Constants::DEAL_STAGES as $stage) {
			$out[] = [
				'value' => $stage,
				'label' => $this->dealStage($stage),
			];
		}

		return $out;
	}

	public function activityType(string $type): string
	{
		return match ($type) {
			'call' => $this->l10n->t('Call'),
			'email' => $this->l10n->t('Email follow-up'),
			'meeting' => $this->l10n->t('Meeting'),
			'todo' => $this->l10n->t('To-do'),
			default => $type !== '' ? $type : $this->l10n->t('Activity'),
		};
	}

	/**
	 * @return list<array{value: string, label: string}>
	 */
	public function activityTypeOptions(): array
	{
		$out = [];
		foreach (['call', 'email', 'meeting', 'todo'] as $type) {
			$out[] = ['value' => $type, 'label' => $this->activityType($type)];
		}
		return $out;
	}

	public function buyingRole(string $role): string
	{
		return match ($role) {
			'economic_buyer' => $this->l10n->t('Budget decision-maker'),
			'champion' => $this->l10n->t('Champion'),
			'day_to_day' => $this->l10n->t('Day-to-day contact'),
			'technical' => $this->l10n->t('Technical contact'),
			'blocker' => $this->l10n->t('Blocker'),
			'other' => $this->l10n->t('Other'),
			default => $role !== '' ? $role : $this->l10n->t('None'),
		};
	}

	/** @return list<array{value: string, label: string}> */
	public function buyingRoleOptions(): array
	{
		$out = [['value' => '', 'label' => $this->l10n->t('None')]];
		foreach (\OCA\CustomerCheck\Constants::BUYING_ROLES as $role) {
			$out[] = ['value' => $role, 'label' => $this->buyingRole($role)];
		}
		return $out;
	}

	public function forecastBucket(string $bucket): string
	{
		return match ($bucket) {
			'commit' => $this->l10n->t('This month likely'),
			'upside' => $this->l10n->t('Possible'),
			'pipeline' => $this->l10n->t('Later'),
			default => $bucket !== '' ? $bucket : $this->l10n->t('None'),
		};
	}

	/** @return list<array{value: string, label: string}> */
	public function forecastBucketOptions(): array
	{
		$out = [['value' => '', 'label' => $this->l10n->t('None')]];
		foreach (\OCA\CustomerCheck\Constants::FORECAST_BUCKETS as $bucket) {
			$out[] = ['value' => $bucket, 'label' => $this->forecastBucket($bucket)];
		}
		return $out;
	}

	public function lossReasonCode(string $code): string
	{
		return match ($code) {
			'price' => $this->l10n->t('Price'),
			'competitor' => $this->l10n->t('Competitor'),
			'timing' => $this->l10n->t('Timing'),
			'no_decision' => $this->l10n->t('No decision'),
			'requirements' => $this->l10n->t('Requirements'),
			'technical' => $this->l10n->t('Technical'),
			'internal' => $this->l10n->t('Internal'),
			'other' => $this->l10n->t('Other'),
			default => $code !== '' ? $code : $this->l10n->t('Unknown'),
		};
	}

	/** @return list<array{value: string, label: string}> */
	public function lossReasonCodeOptions(): array
	{
		$out = [];
		foreach (\OCA\CustomerCheck\Constants::LOSS_REASON_CODES as $code) {
			$out[] = ['value' => $code, 'label' => $this->lossReasonCode($code)];
		}
		return $out;
	}

	public function staleSiblingLink(): string
	{
		return $this->l10n->t('Linked customer (no longer listed)');
	}

	/**
	 * Normalise sibling customer rows into selectable {id, label} options.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array{id:int,label:string}>
	 */
	public function siblingCustomerOptions(array $rows): array
	{
		$out = [];
		$seen = [];
		foreach ($rows as $row) {
			$id = (int)($row['id'] ?? 0);
			if ($id <= 0 || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$name = trim((string)($row['name'] ?? ''));
			$out[] = [
				'id' => $id,
				'label' => $name !== '' ? $name : $this->untitledSiblingCustomer(),
			];
		}
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
		return $out;
	}

	/**
	 * Ensure a currently linked sibling id remains selectable even if missing from the live list.
	 *
	 * @param list<array{id:int,label:string}> $options
	 * @return list<array{id:int,label:string}>
	 */
	public function ensureLinkedSiblingOption(array $options, ?int $linkedId): array
	{
		$linkedId = (int)($linkedId ?? 0);
		if ($linkedId <= 0) {
			return $options;
		}
		foreach ($options as $opt) {
			if ((int)$opt['id'] === $linkedId) {
				return $options;
			}
		}
		array_unshift($options, [
			'id' => $linkedId,
			'label' => $this->staleSiblingLink(),
		]);
		return $options;
	}

	/**
	 * Import Match cell: name (slug) — never "#123".
	 *
	 * @param array{
	 *   companyId?: int|null,
	 *   companyName?: string|null,
	 *   companySlug?: string|null,
	 *   suggestedSlug?: string|null,
	 *   candidates?: list<array{id:int,slug:string,name:string,via:string}>
	 * } $match
	 */
	public function importMatchLabel(array $match): string
	{
		$name = trim((string)($match['companyName'] ?? ''));
		$slug = trim((string)($match['companySlug'] ?? ''));
		if ($name === '' || $slug === '') {
			$companyId = (int)($match['companyId'] ?? 0);
			foreach ($match['candidates'] ?? [] as $candidate) {
				if ($companyId > 0 && (int)($candidate['id'] ?? 0) === $companyId) {
					$name = $name !== '' ? $name : trim((string)($candidate['name'] ?? ''));
					$slug = $slug !== '' ? $slug : trim((string)($candidate['slug'] ?? ''));
					break;
				}
			}
			if (($name === '' || $slug === '') && ($match['candidates'][0] ?? null) !== null) {
				$name = $name !== '' ? $name : trim((string)$match['candidates'][0]['name']);
				$slug = $slug !== '' ? $slug : trim((string)$match['candidates'][0]['slug']);
			}
		}
		$suggested = trim((string)($match['suggestedSlug'] ?? ''));
		if ($name !== '' && $slug !== '') {
			return $name . ' (' . $slug . ')';
		}
		if ($name !== '') {
			return $name;
		}
		if ($slug !== '') {
			return $slug;
		}
		if ($suggested !== '') {
			return $suggested;
		}
		return '—';
	}
}
