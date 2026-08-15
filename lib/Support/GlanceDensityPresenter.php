<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCP\IL10N;

/**
 * Granny-safe “At a glance” sibling KPI tiles.
 * Never paint unpaid/warning when the count is zero; never pretend a missing app is live.
 * Open work (projects / unpaid / tickets) always uses attention so progressive disclosure expands.
 */
final class GlanceDensityPresenter
{
	public const TONE_CALM = 'calm';
	public const TONE_ATTENTION = 'attention';
	public const TONE_OFFLINE = 'offline';

	/**
	 * @param array{
	 *   openProjects?:int|string,
	 *   unpaidInvoices?:int|string,
	 *   openTickets?:int|string,
	 *   waitingTickets?:int|string,
	 *   projectcheck?:bool,
	 *   invoicecheck?:bool,
	 *   ticketcheck?:bool,
	 *   projectsHref?:string,
	 *   invoicesHref?:string,
	 *   ticketsHref?:string
	 * } $density
	 * @return list<array{
	 *   key: string,
	 *   href: string,
	 *   value: int,
	 *   label: string,
	 *   hint: string,
	 *   tone: string,
	 *   available: bool,
	 *   cta: string,
	 *   ariaLabel: string
	 * }>
	 */
	public static function tiles(array $density, IL10N $l): array
	{
		$openProjects = max(0, (int)($density['openProjects'] ?? 0));
		$unpaid = max(0, (int)($density['unpaidInvoices'] ?? 0));
		$openTickets = max(0, (int)($density['openTickets'] ?? $density['waitingTickets'] ?? 0));
		$waiting = max(0, (int)($density['waitingTickets'] ?? 0));

		$pc = !empty($density['projectcheck']);
		$ic = !empty($density['invoicecheck']);
		$tc = !empty($density['ticketcheck']);

		$projectsHref = self::safeHref((string)($density['projectsHref'] ?? ''), '#crm-projects');
		$invoicesHref = self::safeHref((string)($density['invoicesHref'] ?? ''), '#crm-invoices');
		$ticketsHref = self::safeHref((string)($density['ticketsHref'] ?? ''), '#crm-tickets');

		return [
			self::tile(
				'projects',
				$pc ? $projectsHref : '#crm-identity-links',
				$openProjects,
				$l->t('Projects'),
				$pc
					? ($openProjects > 0
						? $l->t('%s open in ProjectCheck', [(string)$openProjects])
						: $l->t('Nothing open · ProjectCheck'))
					: $l->t('App not installed — link under Identity'),
				!$pc ? self::TONE_OFFLINE : ($openProjects > 0 ? self::TONE_ATTENTION : self::TONE_CALM),
				$pc,
				$pc ? $l->t('Open') : $l->t('Link'),
				$l
			),
			self::tile(
				'invoices',
				$ic ? $invoicesHref : '#crm-identity-links',
				$unpaid,
				$l->t('Unpaid invoices'),
				$ic
					? ($unpaid > 0
						? $l->t('%s unpaid — open money', [(string)$unpaid])
						: $l->t('All clear · InvoiceCheck'))
					: $l->t('App not installed — link under Identity'),
				!$ic ? self::TONE_OFFLINE : ($unpaid > 0 ? self::TONE_ATTENTION : self::TONE_CALM),
				$ic,
				$ic ? $l->t('Open') : $l->t('Link'),
				$l
			),
			self::tile(
				'tickets',
				$tc ? $ticketsHref : '#crm-identity-links',
				$openTickets,
				$l->t('Tickets'),
				$tc
					? ($waiting > 0
						? $l->t('%s waiting', [(string)$waiting])
						: ($openTickets > 0
							? $l->t('%s open in TicketCheck', [(string)$openTickets])
							: $l->t('Nothing open · TicketCheck')))
					: $l->t('App not installed — link under Identity'),
				!$tc ? self::TONE_OFFLINE : (($waiting > 0 || $openTickets > 0) ? self::TONE_ATTENTION : self::TONE_CALM),
				$tc,
				$tc ? $l->t('Open') : $l->t('Link'),
				$l
			),
		];
	}

	/**
	 * Expand the density block when any tile needs eyes (open work or missing app).
	 *
	 * @param list<array{tone?:string}> $tiles
	 */
	public static function needsAttention(array $tiles): bool
	{
		foreach ($tiles as $tile) {
			$tone = (string)($tile['tone'] ?? self::TONE_CALM);
			if ($tone === self::TONE_ATTENTION || $tone === self::TONE_OFFLINE) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prefer on-page anchors when unpaid is zero so we never dump users on an empty overdue list.
	 *
	 * @param array<string, mixed> $density
	 * @return array<string, mixed>
	 */
	public static function normalizeInvoiceHref(array $density): array
	{
		$unpaid = max(0, (int)($density['unpaidInvoices'] ?? 0));
		$href = trim((string)($density['invoicesHref'] ?? ''));
		if ($unpaid < 1 && $href !== '' && !str_starts_with($href, '#')) {
			$density['invoicesHref'] = '#crm-invoices';
		}
		return $density;
	}

	/**
	 * Block javascript: / data: / empty — fall back to in-page section.
	 */
	public static function safeHref(string $href, string $fallback): string
	{
		$href = trim($href);
		if ($href === '') {
			return $fallback;
		}
		$lower = strtolower($href);
		if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
			return $fallback;
		}
		return $href;
	}

	/**
	 * @return array{
	 *   key: string,
	 *   href: string,
	 *   value: int,
	 *   label: string,
	 *   hint: string,
	 *   tone: string,
	 *   available: bool,
	 *   cta: string,
	 *   ariaLabel: string
	 * }
	 */
	private static function tile(
		string $key,
		string $href,
		int $value,
		string $label,
		string $hint,
		string $tone,
		bool $available,
		string $cta,
		IL10N $l,
	): array {
		$href = self::safeHref($href, '#crm-glance');
		$aria = $l->t('%1$s: %2$s. %3$s. %4$s', [
			$label,
			(string)$value,
			$hint,
			$cta,
		]);
		return [
			'key' => $key,
			'href' => $href,
			'value' => $value,
			'label' => $label,
			'hint' => $hint,
			'tone' => $tone,
			'available' => $available,
			'cta' => $cta,
			'ariaLabel' => $aria,
		];
	}
}
