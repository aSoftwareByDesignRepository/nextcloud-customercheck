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
use OCP\Lock\ILockingProvider;

/**
 * Public website order intake (§14.4 order path).
 *
 * A stable order id is represented by an open deal title. This makes retries
 * safe without relying on client-side delivery guarantees.
 */
class WebsiteOrderWebhookService
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly RateLimitService $rateLimit,
		private readonly CompanyService $companyService,
		private readonly SlugService $slugService,
		private readonly PersonService $personService,
		private readonly PersonMapper $personMapper,
		private readonly DealMapper $dealMapper,
		private readonly AgreementService $agreementService,
		private readonly ActivityMapper $activityMapper,
		private readonly TimelineService $timelineService,
		private readonly NotificationService $notificationService,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{companyId:int,dealId:int,slug:string}
	 */
	public function handle(string $token, string $clientIp, array $payload): array
	{
		if (!$this->settings->tokenMatches(Constants::CONFIG_ORDER_TOKEN, $token)) {
			throw ValidationException::field(Constants::ERR_HOOK_TOKEN_INVALID, 'token', $this->l10n->t('Invalid or revoked order token.'));
		}
		if (!$this->rateLimit->allow('order:' . $clientIp, Constants::HOOK_RATE_LIMIT, Constants::HOOK_RATE_WINDOW_SECONDS)) {
			throw ValidationException::field(Constants::ERR_HOOK_RATE_LIMITED, 'rate', $this->l10n->t('Too many requests. Try again later.'));
		}

		$orderId = trim((string)($payload['orderId'] ?? ''));
		$companyName = trim((string)($payload['companyName'] ?? ''));
		$email = trim((string)($payload['email'] ?? ''));
		$contactName = trim((string)($payload['contactName'] ?? ''));
		$phone = trim((string)($payload['phone'] ?? ''));
		$lang = (string)($payload['lang'] ?? 'de');
		$fields = [];
		if ($orderId === '' || mb_strlen($orderId) > 200) {
			$fields['orderId'] = $this->l10n->t('A website order id is required.');
		}
		if (mb_strlen($companyName) < 2) {
			$fields['companyName'] = $this->l10n->t('Company name is required.');
		}
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$fields['email'] = $this->l10n->t('Provide a valid email address.');
		}
		if (!in_array($lang, Constants::LANGUAGES, true)) {
			$fields['lang'] = $this->l10n->t('Choose German or English.');
		}
		$agreements = $this->normaliseAgreements($payload, $fields);
		if ($fields !== []) {
			throw new ValidationException(Constants::ERR_HOOK_VALIDATION, $this->l10n->t('Please correct the highlighted fields.'), $fields);
		}

		$lockKey = 'customercheck:website-order:' . hash('sha256', $orderId);
		$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$dealTitle = 'Website order ' . $orderId;
			$existingDeals = $this->dealMapper->findOpenByTitle($dealTitle);
			if ($existingDeals !== []) {
				$deal = $existingDeals[0];
				$company = $this->companyService->get((int)$deal->getCompanyId());

				return [
					'companyId' => (int)$company->getId(),
					'dealId' => (int)$deal->getId(),
					'slug' => (string)$company->getSlug(),
				];
			}

			$now = $this->timeFactory->getDateTime();
			$company = $this->findOrCreateCompany($companyName, $email, $phone, $contactName, $lang);
			$deal = new Deal();
			$deal->setCompanyId((int)$company->getId());
			$deal->setTitle($dealTitle);
			$deal->setStage('lead');
			$deal->setValueCents((int)($payload['totalCents'] ?? 0));
			$deal->setStageChangedAt($now);
			$deal->setCreatedBy('webhook');
			$deal->setCreatedAt($now);
			$deal->setUpdatedAt($now);
			$deal = $this->dealMapper->insert($deal);

			foreach ($agreements as $agreement) {
				$this->agreementService->create($agreement + ['companyId' => (int)$company->getId(), 'status' => 'draft'], 'webhook');
			}
			$this->createTodo($company, $deal, $this->l10n->t('Issue licence key(s)'), $now);
			$this->createTodo($company, $deal, $this->l10n->t('Write invoice'), $now);

			$this->timelineService->append((int)$company->getId(), 'order.webhook', 'webhook', [
				'dealId' => (int)$deal->getId(),
				'orderId' => $orderId,
				'source' => (string)($payload['source'] ?? 'website-order'),
			]);
			$this->notificationService->notifyCrmUsers(
				Constants::NOTIFY_LEAD,
				'company',
				(string)$company->getId(),
				['slug' => (string)$company->getSlug(), 'name' => (string)$company->getName(), 'type' => 'website-order'],
			);

			return ['companyId' => (int)$company->getId(), 'dealId' => (int)$deal->getId(), 'slug' => (string)$company->getSlug()];
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function findOrCreateCompany(string $companyName, string $email, string $phone, string $contactName, string $lang): Company
	{
		foreach ($this->personMapper->findByEmail($email) as $person) {
			$company = $this->companyService->get((int)$person->getCompanyId());
			if (!$company->isArchived()) {
				return $company;
			}
		}

		$company = $this->companyService->create([
			'name' => $companyName,
			'slug' => $this->slugService->suggestUnique($companyName),
			'lang' => $lang,
			'status' => 'lead',
		], 'webhook');
		$this->personService->create((int)$company->getId(), [
			'name' => $contactName !== '' ? $contactName : $companyName,
			'email' => $email,
			'phone' => $phone !== '' ? $phone : null,
			'isPrimary' => true,
		], 'webhook');

		return $company;
	}

	private function createTodo(Company $company, Deal $deal, string $title, \DateTimeInterface $now): void
	{
		$activity = new Activity();
		$activity->setCompanyId((int)$company->getId());
		$activity->setDealId((int)$deal->getId());
		$activity->setType('todo');
		$activity->setTitle($title);
		$activity->setDueDate(new \DateTime(DateMath::toUtcMidnight($now)->format('Y-m-d')));
		$activity->setOrigin('webhook');
		$activity->setCreatedBy('webhook');
		$activity->setCreatedAt($now);
		$activity->setUpdatedAt($now);
		$this->activityMapper->insert($activity);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, string> $fields
	 * @return list<array<string, mixed>>
	 */
	private function normaliseAgreements(array $payload, array &$fields): array
	{
		$rawAgreements = $payload['agreements'] ?? null;
		if (!is_array($rawAgreements) || $rawAgreements === []) {
			$fields['agreements'] = $this->l10n->t('At least one agreement is required.');
			return [];
		}

		$normalised = [];
		foreach ($rawAgreements as $index => $raw) {
			if (!is_array($raw)) {
				$fields['agreements.' . $index] = $this->l10n->t('Each agreement must be an object.');
				continue;
			}
			$productCode = trim((string)($raw['productCode'] ?? ''));
			$label = trim((string)($raw['label'] ?? ''));
			$startsAt = (string)($raw['startsAt'] ?? $payload['startsAt'] ?? '');
			$validUntil = (string)($raw['validUntil'] ?? $payload['validUntil'] ?? '');
			$seats = $this->nonNegativeInt($raw['seats'] ?? null);
			$devices = $this->nonNegativeInt($raw['devices'] ?? null);
			$amountCents = $this->nonNegativeInt($raw['amountCents'] ?? null);
			if ($productCode === '' || $label === '' || DateMath::parseStrictDate($startsAt) === null || DateMath::parseStrictDate($validUntil) === null || $seats === null || $devices === null || $seats + $devices === 0 || $amountCents === null) {
				$fields['agreements.' . $index] = $this->l10n->t('Each agreement needs a product, label, dates, amount and at least one seat or device.');
				continue;
			}
			$normalised[] = [
				'productCode' => $productCode,
				'kind' => 'license',
				'label' => $label,
				'limits' => [
					'mobileSeats' => $seats,
					'terminalDevices' => $devices,
					'bundle' => $seats > 0 && $devices > 0,
				],
				'amountCents' => $amountCents,
				'startsAt' => $startsAt,
				'validUntil' => $validUntil,
			];
		}

		return $normalised;
	}

	private function nonNegativeInt(mixed $value): ?int
	{
		if (is_int($value) && $value >= 0) {
			return $value;
		}
		if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
			return (int)$value;
		}

		return null;
	}
}
