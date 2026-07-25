<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IL10N;

class DeliveryPackService
{
	public function __construct(
		private readonly AgreementService $agreements,
		private readonly CompanyService $companies,
		private readonly PersonService $persons,
		private readonly EmailTouchService $emails,
		private readonly FacadeProbeService $facades,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array{subject:string,bodyText:string,bodyHtml:null,mailtoUrl:string,mailAppUrl:?string,to:string}
	 */
	public function build(int $agreementId, string $actor, ?int $personId = null, ?string $toEmail = null): array
	{
		$agreement = $this->agreements->get($agreementId);
		if ((string)$agreement->getKind() !== 'license') {
			throw ValidationException::field('VALIDATION', 'kind', $this->l10n->t('Delivery packs are for license agreements.'));
		}
		if ($agreement->getSalesLogId() === null && !$agreement->getKeyFingerprint()) {
			throw ValidationException::field('VALIDATION', 'key', $this->l10n->t('Attach a sales-log entry or fingerprint first.'));
		}
		$company = $this->companies->get((int)$agreement->getCompanyId());
		$to = $this->resolveRecipient((int)$company->getId(), $personId, $toEmail);
		$lang = (string)$company->getLang();
		$limits = $agreement->decodedLimits();
		$seats = (int)($limits['mobileSeats'] ?? 0);
		$devices = (int)($limits['terminalDevices'] ?? 0);
		$until = $agreement->getValidUntil()?->format('Y-m-d') ?? '';
		$fp = (string)($agreement->getKeyFingerprint() ?? '');

		if ($lang === 'en') {
			$subject = sprintf('Your %s license key', (string)$agreement->getLabel());
			$body = "Hello,\n\nPlease find your license details for " . $company->getName() . ":\n"
				. "- Product: " . $agreement->getLabel() . "\n"
				. "- Seats/devices: {$seats} / {$devices}\n"
				. "- Valid until: {$until}\n"
				. "- Fingerprint: {$fp}\n\n"
				. "Paste the full license key you received from the license generator into the secure channel.\n\nBest regards\n";
		} else {
			$subject = sprintf('Ihr Lizenzschlüssel für %s', (string)$agreement->getLabel());
			$body = "Guten Tag,\n\nHier die Lizenzdaten für " . $company->getName() . ":\n"
				. "- Produkt: " . $agreement->getLabel() . "\n"
				. "- Sitze/Geräte: {$seats} / {$devices}\n"
				. "- Gültig bis: {$until}\n"
				. "- Fingerprint: {$fp}\n\n"
				. "Bitte fügen Sie den vollständigen Schlüssel aus dem Lizenz-Generator in den sicheren Kanal ein.\n\nViele Grüße\n";
		}

		$mailto = 'mailto:' . rawurlencode($to) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
		$mailAppUrl = $this->facades->mailComposeUrl($to, $subject, $body);
		$this->emails->logCompose((int)$company->getId(), $to, $subject, $actor);

		return [
			'subject' => $subject,
			'bodyText' => $body,
			'bodyHtml' => null,
			'mailtoUrl' => $mailto,
			'mailAppUrl' => $mailAppUrl,
			'to' => $to,
		];
	}

	private function resolveRecipient(int $companyId, ?int $personId, ?string $toEmail): string
	{
		$to = trim((string)$toEmail);
		if ($personId !== null && $personId > 0) {
			$person = $this->persons->get($personId);
			if ((int)$person->getCompanyId() !== $companyId) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'personId', $this->l10n->t('Contact does not belong to this company.'));
			}
			$to = trim((string)($person->getEmail() ?? ''));
		}
		if ($to === '') {
			$primary = $this->persons->findPrimary($companyId);
			$to = trim((string)($primary?->getEmail() ?? ''));
		}
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			throw ValidationException::field(
				Constants::ERR_EMAIL_INVALID,
				'toEmail',
				$this->l10n->t('Add a contact email or pick a recipient before composing.'),
			);
		}

		return $to;
	}
}
