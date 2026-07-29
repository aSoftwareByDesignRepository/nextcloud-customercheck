<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IL10N;

/**
 * Static customer-facing email templates — PSD §7.3 / §14.6–14.7.
 *
 * Templates are human-triggered compose helpers only (no auto-send). Placeholders
 * are resolved server-side; the caller opens Mail / mailto with the result.
 */
class MailTemplateService
{
	public const TEMPLATES = [
		'renewal_60',
		'renewal_30',
		'renewal_7',
		'welcome',
		'quota_80',
		'payment_reminder',
		'partner',
	];

	public function __construct(
		private readonly CompanyService $companies,
		private readonly AgreementService $agreements,
		private readonly PersonService $persons,
		private readonly QuotaService $quota,
		private readonly EmailTouchService $emails,
		private readonly FacadeProbeService $facades,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @param array{personId?:int,toEmail?:string} $options
	 * @return array{template:string,subject:string,bodyText:string,mailtoUrl:string,mailAppUrl:?string,to:string}
	 */
	public function build(string $template, int $companyId, ?int $agreementId, string $actor, array $options = []): array
	{
		if (!in_array($template, self::TEMPLATES, true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'template', $this->l10n->t('Unknown email template.'));
		}

		$company = $this->companies->get($companyId);
		$agreement = $agreementId !== null ? $this->agreements->get($agreementId) : null;
		if ($agreement !== null && (int)$agreement->getCompanyId() !== $companyId) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'agreementId', $this->l10n->t('Agreement does not belong to this company.'));
		}

		$to = trim((string)($options['toEmail'] ?? ''));
		$personId = (int)($options['personId'] ?? 0);
		if ($personId > 0) {
			$person = $this->persons->get($personId);
			if ((int)$person->getCompanyId() !== $companyId) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'personId', $this->l10n->t('Contact does not belong to this company.'));
			}
			$to = (string)($person->getEmail() ?? '');
		}
		if ($to === '') {
			$primary = $this->persons->findPrimary($companyId);
			$to = (string)($primary?->getEmail() ?? '');
		}
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			throw ValidationException::field(
				Constants::ERR_EMAIL_INVALID,
				'toEmail',
				$this->l10n->t('Add a contact email or pick a recipient before composing.'),
			);
		}

		$contactName = '';
		$primary = $this->persons->findPrimary($companyId);
		if ($primary !== null) {
			$contactName = (string)$primary->getName();
		}

		$vars = $this->vars($company, $agreement, $contactName);
		[$subject, $body] = $this->render($template, (string)$company->getLang(), $vars);

		$mailto = 'mailto:' . rawurlencode($to) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
		$mailAppUrl = $this->facades->mailComposeUrl($to, $subject, $body);
		$this->emails->logCompose($companyId, $to, $subject, $actor);

		return [
			'template' => $template,
			'subject' => $subject,
			'bodyText' => $body,
			'mailtoUrl' => $mailto,
			'mailAppUrl' => $mailAppUrl,
			'to' => $to,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function vars(\OCA\CustomerCheck\Db\Company $company, ?\OCA\CustomerCheck\Db\Agreement $agreement, string $contactName): array
	{
		$limits = $agreement?->decodedLimits() ?? [];
		$quotaUsed = '';
		$quotaTotal = '';
		if ($agreement !== null && (string)$agreement->getKind() === 'partner') {
			$snap = $this->quota->snapshot($agreement);
			$quotaUsed = (string)$snap['usedMinutes'];
			$quotaTotal = (string)$snap['quotaMinutes'];
		}

		return [
			'companyName' => (string)$company->getName(),
			'contactName' => $contactName !== '' ? $contactName : (string)$company->getName(),
			'productLabel' => (string)($agreement?->getLabel() ?? ''),
			'validUntil' => $agreement?->getValidUntil()?->format('Y-m-d') ?? '',
			'amountEur' => number_format(((int)($agreement?->getAmountCents() ?? 0)) / 100, 2, '.', ''),
			'hoursUsed' => $quotaUsed,
			'hoursQuota' => $quotaTotal,
			'keyFingerprint' => (string)($agreement?->getKeyFingerprint() ?? ''),
			'limitsSummary' => sprintf(
				'%s seats / %s devices',
				(string)($limits['mobileSeats'] ?? 0),
				(string)($limits['terminalDevices'] ?? 0),
			),
			'partnerSubject' => 'Partner: ' . (string)$company->getSlug(),
			'slug' => (string)$company->getSlug(),
		];
	}

	/**
	 * @param array<string, string> $vars
	 * @return array{0:string,1:string}
	 */
	private function render(string $template, string $lang, array $vars): array
	{
		$en = $lang === 'en';
		return match ($template) {
			'renewal_60' => [
				$en
					? sprintf('Renewal in 60 days — %s', $vars['productLabel'])
					: sprintf('Verlängerung in 60 Tagen — %s', $vars['productLabel']),
				$en
					? "Hello {$vars['contactName']},\n\nYour {$vars['productLabel']} for {$vars['companyName']} expires on {$vars['validUntil']}.\nWe would be happy to prepare a renewal quote ({$vars['amountEur']} EUR).\n\nBest regards\n"
					: "Guten Tag {$vars['contactName']},\n\nIhr {$vars['productLabel']} für {$vars['companyName']} läuft am {$vars['validUntil']} aus.\nGerne bereiten wir ein Verlängerungsangebot vor ({$vars['amountEur']} EUR).\n\nViele Grüße\n",
			],
			'renewal_30' => [
				$en
					? sprintf('Renewal reminder — %s (30 days)', $vars['productLabel'])
					: sprintf('Erinnerung Verlängerung — %s (30 Tage)', $vars['productLabel']),
				$en
					? "Hello {$vars['contactName']},\n\nFriendly reminder: {$vars['productLabel']} expires on {$vars['validUntil']} (30 days).\nPlease let us know if we should issue the renewal.\n\nBest regards\n"
					: "Guten Tag {$vars['contactName']},\n\nErinnerung: {$vars['productLabel']} läuft am {$vars['validUntil']} aus (noch 30 Tage).\nSollen wir die Verlängerung vorbereiten?\n\nViele Grüße\n",
			],
			'renewal_7' => [
				$en
					? sprintf('Urgent: %s expires in 7 days', $vars['productLabel'])
					: sprintf('Dringend: %s läuft in 7 Tagen aus', $vars['productLabel']),
				$en
					? "Hello {$vars['contactName']},\n\n{$vars['productLabel']} for {$vars['companyName']} expires on {$vars['validUntil']}.\nAfter expiry, clients will hit HTTP 402 until a renewed key is installed.\n\nBest regards\n"
					: "Guten Tag {$vars['contactName']},\n\n{$vars['productLabel']} für {$vars['companyName']} läuft am {$vars['validUntil']} aus.\nDanach melden Clients HTTP 402, bis ein neuer Schlüssel eingespielt ist.\n\nViele Grüße\n",
			],
			'welcome' => [
				$en
					? sprintf('Welcome — %s', $vars['productLabel'])
					: sprintf('Willkommen — %s', $vars['productLabel']),
				$en
					? "Hello {$vars['contactName']},\n\nWelcome to {$vars['productLabel']} for {$vars['companyName']}.\nLimits: {$vars['limitsSummary']}. Valid until {$vars['validUntil']}.\nFingerprint: {$vars['keyFingerprint']}\n\nBest regards\n"
					: "Guten Tag {$vars['contactName']},\n\nWillkommen bei {$vars['productLabel']} für {$vars['companyName']}.\nLimits: {$vars['limitsSummary']}. Gültig bis {$vars['validUntil']}.\nFingerprint: {$vars['keyFingerprint']}\n\nViele Grüße\n",
			],
			'quota_80' => [
				$en
					? sprintf('Check Partner quota at 80%% — %s', $vars['companyName'])
					: sprintf('Check-Partner-Kontingent bei 80 %% — %s', $vars['companyName']),
				$en
					? "Hello {$vars['contactName']},\n\nYou have used {$vars['hoursUsed']} of {$vars['hoursQuota']} partner minutes for {$vars['companyName']}.\nWe can top up hours or discuss the next package.\n\nBest regards\n"
					: "Guten Tag {$vars['contactName']},\n\nSie haben {$vars['hoursUsed']} von {$vars['hoursQuota']} Partner-Minuten für {$vars['companyName']} verbraucht.\nGerne erweitern wir das Kontingent oder sprechen über das nächste Paket.\n\nViele Grüße\n",
			],
			'payment_reminder' => [
				$en
					? sprintf('Payment reminder — %s', $vars['productLabel'])
					: sprintf('Zahlungserinnerung — %s', $vars['productLabel']),
				$en
					? "Hello {$vars['contactName']},\n\nThis is a friendly reminder regarding {$vars['productLabel']} ({$vars['amountEur']} EUR).\nPlease let us know if the invoice is already on its way.\n\nBest regards\n"
					: "Guten Tag {$vars['contactName']},\n\nfreundliche Erinnerung zu {$vars['productLabel']} ({$vars['amountEur']} EUR).\nBitte geben Sie uns Bescheid, falls die Rechnung bereits unterwegs ist.\n\nViele Grüße\n",
			],
			'partner' => [
				$vars['partnerSubject'],
				$en
					? "Hello {$vars['contactName']},\n\nRegarding Check Partner for {$vars['companyName']} ({$vars['slug']}).\nQuota: {$vars['hoursUsed']} / {$vars['hoursQuota']} minutes.\n\nBest regards\n"
					: "Guten Tag {$vars['contactName']},\n\nbetreffend Check Partner für {$vars['companyName']} ({$vars['slug']}).\nKontingent: {$vars['hoursUsed']} / {$vars['hoursQuota']} Minuten.\n\nViele Grüße\n",
			],
			default => throw ValidationException::field(Constants::ERR_VALIDATION, 'template', $this->l10n->t('Unknown email template.')),
		};
	}
}
