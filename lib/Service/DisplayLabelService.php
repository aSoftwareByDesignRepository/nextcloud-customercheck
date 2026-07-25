<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\CustomerCheck\Service;

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
