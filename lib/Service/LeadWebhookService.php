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
use OCA\CustomerCheck\Support\LeadSource;
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
		private readonly WorkspaceService $workspaces,
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
		$defaultWs = $this->workspaces->findDefault();
		$workspaceId = $defaultWs !== null ? (int)$defaultWs->getId() : 0;
		$company = null;
		if ($emailOk) {
			// Email match only inside the default intake workspace — never attach
			// a lead to another circle’s book (§3.3 / §18.1).
			$matches = $workspaceId > 0
				? $this->personMapper->findByEmail($email, $workspaceId)
				: [];
			foreach ($matches as $person) {
				$candidate = $this->companyService->get((int)$person->getCompanyId());
				if (!$candidate->isArchived()) {
					$company = $candidate;
					break;
				}
			}
		}
		if ($company === null) {
			$slug = $this->slugService->suggestUnique($companyName);
			$create = [
				'name' => $companyName,
				'slug' => $slug,
				'lang' => $lang,
				'status' => 'lead',
			];
			if ($workspaceId > 0) {
				$create['workspaceId'] = $workspaceId;
			}
			$company = $this->companyService->create($create, 'webhook');
			if ($contactName !== '' || $emailOk || $phoneOk) {
				$this->personService->create((int)$company->getId(), [
					'name' => $contactName !== '' ? $contactName : $companyName,
					'email' => $emailOk ? $email : null,
					'phone' => $phoneOk ? $phone : null,
					'isPrimary' => true,
				], 'webhook');
			}
		}

		$deal = $this->findOrCreateLeadDeal(
			$company,
			$productInterest,
			$now,
			LeadSource::normalize((string)($payload['source'] ?? 'website'), false) ?? 'website',
		);

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
		$notifyWs = (int)($company->getWorkspaceId() ?? 0);
		$leadParams = ['slug' => (string)$company->getSlug(), 'name' => (string)$company->getName()];
		if ($notifyWs > 0) {
			$this->notificationService->notifyWorkspaceUsers(
				$notifyWs,
				Constants::NOTIFY_LEAD,
				'company',
				(string)$company->getId(),
				$leadParams,
			);
		}

		return [
			'companyId' => (int)$company->getId(),
			'dealId' => (int)$deal->getId(),
			'slug' => (string)$company->getSlug(),
		];
	}

	private function findOrCreateLeadDeal(
		Company $company,
		string $productInterest,
		\DateTimeInterface $now,
		string $leadSource,
	): Deal {
		$openDeals = $this->dealMapper->findOpenByCompany((int)$company->getId());
		foreach ($openDeals as $candidate) {
			if ((string)$candidate->getStage() === 'lead') {
				return $candidate;
			}
		}

		$wsId = (int)($company->getWorkspaceId() ?? 0);
		$slaHours = Constants::LEAD_SLA_HOURS_DEFAULT;
		if ($wsId > 0) {
			$slaHours = max(1, (int)$this->workspaces->getSettings($wsId)['leadSlaHours']);
		}

		$primaryPersonId = $this->primaryPersonIdForCompany((int)$company->getId());

		$stamp = $now instanceof \DateTime ? clone $now : \DateTime::createFromInterface($now);
		$deal = new Deal();
		$deal->setCompanyId((int)$company->getId());
		$deal->setTitle($productInterest !== '' ? $productInterest : $this->l10n->t('Website inquiry'));
		$deal->setStage('lead');
		$deal->setValueCents(0);
		$deal->setLeadSource(LeadSource::isKnown($leadSource) ? $leadSource : 'website');
		if ($primaryPersonId !== null) {
			$deal->setPersonId($primaryPersonId);
		}
		// Unowned until claimed on Today (§18.7) — chase surfaces with Claim CTA.
		$deal->setOwnerUserId(null);
		$deal->setLeadSlaDueAt((clone $stamp)->modify('+' . $slaHours . ' hours'));
		$deal->setStageChangedAt($stamp);
		$deal->setCreatedBy('webhook');
		$deal->setCreatedAt($stamp);
		$deal->setUpdatedAt($stamp);

		return $this->dealMapper->insert($deal);
	}

	private function primaryPersonIdForCompany(int $companyId): ?int
	{
		if ($companyId <= 0) {
			return null;
		}
		$primary = $this->personMapper->findPrimary($companyId);

		return $primary !== null ? (int)$primary->getId() : null;
	}
}
