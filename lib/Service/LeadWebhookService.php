<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Website lead intake — PSD §14.4.
 *
 * Person creation goes through PersonService so the primary-contact invariant
 * serialises under the company lock (same path as the UI). Phone-only leads
 * open a call activity; email leads open an email reply activity (§8.9 E3).
 */
class LeadWebhookService
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly RateLimitService $rateLimit,
		private readonly CompanyService $companyService,
		private readonly SlugService $slugService,
		private readonly PersonService $personService,
		private readonly PersonMapper $personMapper,
		private readonly DealMapper $dealMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly TimelineService $timelineService,
		private readonly NotificationService $notificationService,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{companyId:int,dealId:int,slug:string}
	 */
	public function handle(string $token, string $clientIp, array $payload): array
	{
		if (!$this->settings->tokenMatches(Constants::CONFIG_LEAD_TOKEN, $token)) {
			throw ValidationException::field(Constants::ERR_HOOK_TOKEN_INVALID, 'token', $this->l10n->t('Invalid or revoked lead token.'));
		}
		if (!$this->rateLimit->allow('lead:' . $clientIp, Constants::HOOK_RATE_LIMIT, Constants::HOOK_RATE_WINDOW_SECONDS)) {
			throw ValidationException::field(Constants::ERR_HOOK_RATE_LIMITED, 'rate', $this->l10n->t('Too many requests. Try again later.'));
		}

		$companyName = trim((string)($payload['companyName'] ?? ''));
		$email = trim((string)($payload['email'] ?? ''));
		$phone = trim((string)($payload['phone'] ?? ''));
		$contactName = trim((string)($payload['contactName'] ?? ''));
		$message = trim((string)($payload['message'] ?? ''));
		$productInterest = trim((string)($payload['productInterest'] ?? ''));
		$lang = (string)($payload['lang'] ?? 'de');
		if (!in_array($lang, Constants::LANGUAGES, true)) {
			$lang = 'de';
		}

		$fields = [];
		if (mb_strlen($companyName) < 2) {
			$fields['companyName'] = $this->l10n->t('Company name is required.');
		}
		$emailOk = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
		$phoneOk = mb_strlen($phone) >= 5;
		if (!$emailOk && !$phoneOk) {
			$fields['email'] = $this->l10n->t('Provide a valid email or a phone number (at least 5 characters).');
		}
		if ($fields !== []) {
			throw new ValidationException(Constants::ERR_HOOK_VALIDATION, $this->l10n->t('Please correct the highlighted fields.'), $fields);
		}

		$now = $this->timeFactory->getDateTime();
		$company = null;
		if ($emailOk) {
			$matches = $this->personMapper->findByEmail($email);
			if ($matches !== []) {
				$company = $this->companyService->get((int)$matches[0]->getCompanyId());
			}
		}
		if ($company === null) {
			$slug = $this->slugService->suggestUnique($companyName);
			$company = $this->companyService->create([
				'name' => $companyName,
				'slug' => $slug,
				'lang' => $lang,
				'status' => 'lead',
			], 'webhook');
			if ($contactName !== '' || $emailOk || $phoneOk) {
				$this->personService->create((int)$company->getId(), [
					'name' => $contactName !== '' ? $contactName : $companyName,
					'email' => $emailOk ? $email : null,
					'phone' => $phoneOk ? $phone : null,
					'isPrimary' => true,
				], 'webhook');
			}
		}

		$deal = $this->findOrCreateLeadDeal($company, $productInterest, $now);

		$activity = new Activity();
		$activity->setCompanyId((int)$company->getId());
		$activity->setDealId((int)$deal->getId());
		// Phone-only inquiries become call tasks; otherwise reply-by-email (§8.9 E3).
		$activity->setType($emailOk ? 'email' : 'call');
		$activity->setTitle($this->l10n->t('Reply to inquiry'));
		$activity->setDetails($message !== '' ? mb_substr($message, 0, 4000) : null);
		$activity->setDueDate(new \DateTime(DateMath::toUtcMidnight($now)->format('Y-m-d')));
		$activity->setOrigin('webhook');
		$activity->setCreatedBy('webhook');
		$activity->setCreatedAt($now);
		$activity->setUpdatedAt($now);
		$this->activityMapper->insert($activity);

		$this->timelineService->append((int)$company->getId(), 'lead.webhook', 'webhook', [
			'dealId' => (int)$deal->getId(),
			'source' => (string)($payload['source'] ?? 'website'),
		]);
		$this->notificationService->notifyCrmUsers(
			Constants::NOTIFY_LEAD,
			'company',
			(string)$company->getId(),
			['slug' => (string)$company->getSlug(), 'name' => (string)$company->getName()],
		);

		return [
			'companyId' => (int)$company->getId(),
			'dealId' => (int)$deal->getId(),
			'slug' => (string)$company->getSlug(),
		];
	}

	private function findOrCreateLeadDeal(Company $company, string $productInterest, \DateTimeInterface $now): Deal
	{
		$openDeals = $this->dealMapper->findOpenByCompany((int)$company->getId());
		foreach ($openDeals as $candidate) {
			if ((string)$candidate->getStage() === 'lead') {
				return $candidate;
			}
		}

		$deal = new Deal();
		$deal->setCompanyId((int)$company->getId());
		$deal->setTitle($productInterest !== '' ? $productInterest : $this->l10n->t('Website inquiry'));
		$deal->setStage('lead');
		$deal->setValueCents(0);
		$deal->setStageChangedAt($now);
		$deal->setCreatedBy('webhook');
		$deal->setCreatedAt($now);
		$deal->setUpdatedAt($now);

		return $this->dealMapper->insert($deal);
	}
}
