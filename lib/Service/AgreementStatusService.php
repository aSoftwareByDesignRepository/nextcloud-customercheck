<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\IL10N;

/**
 * Agreement lifecycle derivation — PSD §4.7-B.
 *
 * The state machine, read literally:
 *
 *     draft                       ──(user activates / import)──▶ active
 *     active                      ──(today ≥ valid_until − 60d)──▶ expiring
 *     expiring                    ──(today  >  valid_until)────▶ expired
 *     active|expiring|expired     ──(renewal created)──────────▶ superseded
 *     active|expiring             ──(user cancels + reason)────▶ cancelled
 *     onetime: draft ▶ active     ──(invoice paid)─────────────▶ completed
 *
 * Boundaries matter more than anything else in this class: `daysUntil == 60`
 * is already *expiring*, `61` is still *active*, `0` (the expiry day itself)
 * is still *expiring* because the transition needs `today > valid_until`, and
 * grace covers `-1 … -7` inclusive.
 */
class AgreementStatusService
{
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Whole days from today to `valid_until`; null for open-ended agreements.
	 * Positive means the agreement still has that many days to run.
	 */
	public function daysUntilExpiry(?\DateTimeInterface $validUntil, \DateTimeInterface $today): ?int
	{
		if ($validUntil === null) {
			return null;
		}

		return DateMath::daysBetween($today, $validUntil);
	}

	/**
	 * System-derived status. Terminal statuses and drafts are returned
	 * unchanged — only a user action or an import moves them on.
	 */
	public function deriveStatus(Agreement $agreement, \DateTimeInterface $today): string
	{
		$current = (string)$agreement->getStatus();

		if (in_array($current, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
			return $current;
		}
		if ($current === 'draft') {
			return 'draft';
		}
		if ((string)$agreement->getKind() === 'onetime') {
			// One-time items have no expiry states (§4.7-B).
			return $current;
		}

		$daysUntil = $this->daysUntilExpiry($agreement->getValidUntil(), $today);
		if ($daysUntil === null) {
			return $current;
		}

		if ($daysUntil < 0) {
			return 'expired';
		}
		if ($daysUntil <= Constants::RENEWAL_WINDOW_DAYS) {
			return 'expiring';
		}

		return 'active';
	}

	/**
	 * Apply the derived status in place.
	 *
	 * @return bool true when the status actually changed
	 */
	public function recompute(Agreement $agreement, \DateTimeInterface $today): bool
	{
		$derived = $this->deriveStatus($agreement, $today);
		if ($derived === (string)$agreement->getStatus()) {
			return false;
		}

		$agreement->setStatus($derived);

		return true;
	}

	/**
	 * Goodwill window after expiry (§3): days −1 … −7 inclusive. The expiry day
	 * itself is not "in grace" because the agreement has not expired yet.
	 */
	public function isInGrace(?\DateTimeInterface $validUntil, \DateTimeInterface $today): bool
	{
		$daysUntil = $this->daysUntilExpiry($validUntil, $today);
		if ($daysUntil === null) {
			return false;
		}

		return $daysUntil < 0 && $daysUntil >= -Constants::GRACE_DAYS;
	}

	public function graceEndsAt(?\DateTimeInterface $validUntil): ?\DateTimeImmutable
	{
		if ($validUntil === null) {
			return null;
		}

		return DateMath::addDays($validUntil, Constants::GRACE_DAYS);
	}

	/**
	 * Manual/user-driven transition guard. System recomputation between
	 * active/expiring/expired never routes through here.
	 *
	 * @throws ValidationException `AGREEMENT_ILLEGAL_TRANSITION`
	 */
	public function assertTransitionAllowed(string $from, string $to): void
	{
		if (!$this->isTransitionAllowed($from, $to)) {
			throw ValidationException::field(
				Constants::ERR_AGREEMENT_ILLEGAL_TRANSITION,
				'status',
				$this->l10n->t(
					'An agreement cannot move from "%1$s" to "%2$s". Create a new agreement instead.',
					[$from, $to],
				),
			);
		}
	}

	public function isTransitionAllowed(string $from, string $to): bool
	{
		if ($from === $to) {
			return true;
		}
		if (!in_array($to, Constants::AGREEMENT_STATUSES, true)) {
			return false;
		}
		// Terminal statuses are final; the correction path is a new agreement.
		if (in_array($from, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
			return false;
		}

		return match ($from) {
			'draft' => in_array($to, ['active', 'cancelled'], true),
			'active' => in_array($to, ['expiring', 'expired', 'superseded', 'cancelled', 'completed'], true),
			'expiring' => in_array($to, ['active', 'expired', 'superseded', 'cancelled'], true),
			'expired' => in_array($to, ['active', 'expiring', 'superseded', 'cancelled'], true),
			default => false,
		};
	}

	/**
	 * Expiry chip for tables and the 360 view. Always carries text, never
	 * colour alone (§9.0 rule 7).
	 *
	 * @return array{variant: string, label: string, daysUntil: int|null, inGrace: bool}
	 */
	public function expiryBadge(Agreement $agreement, \DateTimeInterface $today): array
	{
		$daysUntil = $this->daysUntilExpiry($agreement->getValidUntil(), $today);
		$inGrace = $this->isInGrace($agreement->getValidUntil(), $today);

		if ($daysUntil === null) {
			return [
				'variant' => 'neutral',
				'label' => $this->l10n->t('No expiry'),
				'daysUntil' => null,
				'inGrace' => false,
			];
		}

		if ($daysUntil < 0) {
			return [
				'variant' => $inGrace ? 'warning' : 'danger',
				'label' => $inGrace
					? $this->l10n->n('Expired %n day ago — in grace', 'Expired %n days ago — in grace', -$daysUntil)
					: $this->l10n->n('Expired %n day ago', 'Expired %n days ago', -$daysUntil),
				'daysUntil' => $daysUntil,
				'inGrace' => $inGrace,
			];
		}

		if ($daysUntil <= Constants::REMINDER_OFFSETS[2]) {
			return [
				'variant' => 'danger',
				'label' => $this->l10n->n('Expires in %n day', 'Expires in %n days', $daysUntil),
				'daysUntil' => $daysUntil,
				'inGrace' => false,
			];
		}

		if ($daysUntil <= Constants::RENEWAL_WINDOW_DAYS) {
			return [
				'variant' => 'warning',
				'label' => $this->l10n->n('Expires in %n day', 'Expires in %n days', $daysUntil),
				'daysUntil' => $daysUntil,
				'inGrace' => false,
			];
		}

		return [
			'variant' => 'success',
			'label' => $this->l10n->n('Expires in %n day', 'Expires in %n days', $daysUntil),
			'daysUntil' => $daysUntil,
			'inGrace' => false,
		];
	}
}
