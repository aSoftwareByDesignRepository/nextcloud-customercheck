<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IL10N;

class PartnerMailService
{
	public function __construct(
		private readonly AgreementService $agreements,
		private readonly CompanyService $companies,
		private readonly PersonService $persons,
		private readonly QuotaService $quota,
		private readonly EmailTouchService $emails,
		private readonly FacadeProbeService $facades,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array{subject:string,bodyText:string,mailtoUrl:string,mailAppUrl:?string,promptHours:bool,to:string}
	 */
	public function build(
		int $agreementId,
		string $actor,
		bool $promptHours = false,
		?int $personId = null,
		?string $toEmail = null,
	): array {
		$agreement = $this->agreements->get($agreementId);
		if ((string)$agreement->getKind() !== 'partner') {
			throw ValidationException::field('HOURS_WRONG_KIND', 'kind', $this->l10n->t('Partner mail is for Check Partner agreements.'));
		}
		$company = $this->companies->get((int)$agreement->getCompanyId());
		$slug = (string)$company->getSlug();
		$subject = 'Partner: ' . $slug;
		$snap = $this->quota->snapshot($agreement);
		$to = $this->resolveRecipient((int)$company->getId(), $personId, $toEmail);
		$body = $this->l10n->t(
			"Hello,\n\nRegarding Check Partner for %1\$s.\nQuota used: %2\$s min of %3\$s min (%4\$s%%).\n\nBest regards\n",
			[$company->getName(), (string)$snap['usedMinutes'], (string)$snap['quotaMinutes'], (string)$snap['percent']],
		);
		$mailto = 'mailto:' . rawurlencode($to) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
		$mailAppUrl = $this->facades->mailComposeUrl($to, $subject, $body);
		$this->emails->logCompose((int)$company->getId(), $to, $subject, $actor);

		return [
			'subject' => $subject,
			'bodyText' => $body,
			'mailtoUrl' => $mailto,
			'mailAppUrl' => $mailAppUrl,
			'promptHours' => $promptHours,
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
