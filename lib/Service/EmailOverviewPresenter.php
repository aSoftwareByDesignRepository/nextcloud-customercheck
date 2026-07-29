<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;

/**
 * Plain-language labels for the company “Who wrote what” card (PSD §14.8).
 *
 * Pure presentation helpers — no DB / Mail I/O.
 */
final class EmailOverviewPresenter
{
	/**
	 * Counterparty address for “with whom” (outbound → to, inbound → from).
	 */
	public static function withWhom(string $direction, string $fromAddr, string $toAddr): string
	{
		$from = trim($fromAddr);
		$to = trim($toAddr);
		if ($direction === 'inbound') {
			return $from !== '' ? $from : $to;
		}
		return $to !== '' ? $to : $from;
	}

	/**
	 * Prefer a known person name; fall back to the address (never crm@local as the “with”).
	 *
	 * @param array<string, string> $emailToName lowercase email → display name
	 */
	public static function withLabel(string $withWhom, array $emailToName): string
	{
		$key = strtolower(trim($withWhom));
		if ($key === '' || $key === 'crm@local') {
			return $withWhom !== '' ? $withWhom : '—';
		}
		$name = $emailToName[$key] ?? '';
		if ($name !== '') {
			return $name;
		}
		return $withWhom;
	}

	public static function sourceLabel(IL10N $l10n, string $source): string
	{
		return match ($source) {
			'compose' => $l10n->t('Opened compose'),
			'mail_app' => $l10n->t('From Mail'),
			default => $l10n->t('Logged'),
		};
	}

	public static function directionLabel(IL10N $l10n, string $direction): string
	{
		return $direction === 'inbound'
			? $l10n->t('They wrote')
			: $l10n->t('You wrote');
	}

	public static function whenLabel(?\DateTimeInterface $occurredAt): string
	{
		if ($occurredAt === null) {
			return '—';
		}
		return $occurredAt->format('Y-m-d');
	}

	/**
	 * @return list<array{title:string,text:string}>
	 */
	public static function workflowSteps(IL10N $l10n, string $mode): array
	{
		$steps = [
			[
				'title' => $l10n->t('1. Put faces on addresses'),
				'text' => $l10n->t('Add people with their email under People. That is how CRM knows “who”.'),
			],
			[
				'title' => $l10n->t('2. Log what matters'),
				'text' => $l10n->t('When you write or receive something important, tap Log email — or use CRM compose helpers (renewal, delivery, Partner). Those appear here automatically.'),
			],
		];
		if ($mode === 'manual_only') {
			$steps[] = [
				'title' => $l10n->t('3. This list is your memory'),
				'text' => $l10n->t('CustomerCheck does not sync your whole inbox. The table below is only what you logged for this company — so you can answer “what did I write with whom?” without digging through Mail.'),
			];
		} else {
			$steps[] = [
				'title' => $l10n->t('3. Peek in Mail when you need the full thread'),
				'text' => $l10n->t('Use “Open in Mail” for a contact to search your Mail accounts. CRM never copies full mailboxes — only this short log stays here.'),
			];
		}
		return $steps;
	}
}
