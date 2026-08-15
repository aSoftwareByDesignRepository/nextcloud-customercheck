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
use OCA\CustomerCheck\Support\PostalAddressParser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Public website order intake (§14.4 order path).
 *
 * A stable order id is represented by an open deal title. This makes retries
 * safe without relying on client-side delivery guarantees.
 *
 * Lock keys MUST stay ≤64 characters: Nextcloud `file_locks`.`key` truncates
 * longer values, so releaseLock misses the row and exclusive locks stick until TTL.
 */
class WebsiteOrderWebhookService
{
	/** @internal exposed for unit tests / mutation anchors */
	public static function orderLockKey(string $orderId): string
	{
		// Exactly 64 hex chars — fits file_locks.key without truncation.
		return hash('sha256', 'customercheck:website-order|' . $orderId);
	}

	public function __construct(
		private readonly SettingsService $settings,
		private readonly RateLimitService $rateLimit,
		private readonly CompanyService $companyService,
		private readonly WorkspaceService $workspaces,
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

		$dealTitle = 'Website order ' . $orderId;
		// Title idempotency is scoped to the default workspace — never reclaim
		// an open deal from another book that happens to share the same order id.
		$defaultWs = $this->workspaces->findDefault();
		$orderWorkspaceId = $defaultWs !== null ? (int)$defaultWs->getId() : 0;

		// Cheap retry path: no lock needed when the deal already exists.
		$existing = $this->existingOrderResult($dealTitle, $orderWorkspaceId);
		if ($existing !== null) {
			return $existing;
		}

		$lockKey = self::orderLockKey($orderId);
		$acquired = false;
		try {
			try {
				$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				$acquired = true;
			} catch (LockedException) {
				$waited = $this->waitForExistingOrder($dealTitle, $orderWorkspaceId);
				if ($waited !== null) {
					return $waited;
				}
				throw ValidationException::field(
					Constants::ERR_HOOK_RATE_LIMITED,
					'rate',
					$this->l10n->t('Too many requests. Try again later.'),
				);
			}

			$existing = $this->existingOrderResult($dealTitle, $orderWorkspaceId);
			if ($existing !== null) {
				return $existing;
			}

			$now = $this->timeFactory->getDateTime();
			$company = $this->findOrCreateCompany($companyName, $email, $phone, $contactName, $lang, $payload);
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
			$notifyWs = (int)($company->getWorkspaceId() ?? 0);
			$leadParams = [
				'slug' => (string)$company->getSlug(),
				'name' => (string)$company->getName(),
				'type' => 'website-order',
			];
			if ($notifyWs > 0) {
				$this->notificationService->notifyWorkspaceUsers(
					$notifyWs,
					Constants::NOTIFY_LEAD,
					'company',
					(string)$company->getId(),
					$leadParams,
				);
			}

			return ['companyId' => (int)$company->getId(), 'dealId' => (int)$deal->getId(), 'slug' => (string)$company->getSlug()];
		} finally {
			if ($acquired) {
				$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * @return array{companyId:int,dealId:int,slug:string}|null
	 */
	private function existingOrderResult(string $dealTitle, int $orderWorkspaceId): ?array
	{
		if ($orderWorkspaceId <= 0) {
			return null;
		}
		$existingDeals = $this->dealMapper->findOpenByTitle($dealTitle, $orderWorkspaceId);
		if ($existingDeals === []) {
			return null;
		}
		$deal = $existingDeals[0];
		$company = $this->companyService->get((int)$deal->getCompanyId());

		return [
			'companyId' => (int)$company->getId(),
			'dealId' => (int)$deal->getId(),
			'slug' => (string)$company->getSlug(),
		];
	}

	/**
	 * @return array{companyId:int,dealId:int,slug:string}|null
	 */
	private function waitForExistingOrder(string $dealTitle, int $orderWorkspaceId): ?array
	{
		for ($i = 0; $i < 8; $i++) {
			$hit = $this->existingOrderResult($dealTitle, $orderWorkspaceId);
			if ($hit !== null) {
				return $hit;
			}
			usleep(50_000);
		}

		return $this->existingOrderResult($dealTitle, $orderWorkspaceId);
	}

	private function findOrCreateCompany(string $companyName, string $email, string $phone, string $contactName, string $lang, array $payload): Company
	{
		$defaultWs = $this->workspaces->findDefault();
		$workspaceId = $defaultWs !== null ? (int)$defaultWs->getId() : 0;
		$address = PostalAddressParser::fromImportPayload($payload);
		$matches = $workspaceId > 0
			? $this->personMapper->findByEmail($email, $workspaceId)
			: [];
		foreach ($matches as $person) {
			$company = $this->companyService->get((int)$person->getCompanyId());
			if (!$company->isArchived()) {
				$this->fillEmptyAddressFields($company, $address);
				return $company;
			}
		}

		$create = [
			'name' => $companyName,
			'slug' => $this->slugService->suggestUnique($companyName),
			'lang' => $lang,
			'status' => 'lead',
		];
		if ($workspaceId > 0) {
			$create['workspaceId'] = $workspaceId;
		}
		if ($address['addrStreet'] !== null) {
			$create['addrStreet'] = $address['addrStreet'];
		}
		if ($address['addrZip'] !== null) {
			$create['addrZip'] = $address['addrZip'];
		}
		if ($address['addrCity'] !== null) {
			$create['addrCity'] = $address['addrCity'];
		}
		if ($address['country'] !== null) {
			$create['country'] = $address['country'];
		}
		$company = $this->companyService->create($create, 'webhook');
		$this->personService->create((int)$company->getId(), [
			'name' => $contactName !== '' ? $contactName : $companyName,
			'email' => $email,
			'phone' => $phone !== '' ? $phone : null,
			'isPrimary' => true,
		], 'webhook');

		return $company;
	}

	/**
	 * @param array{addrStreet:?string,addrZip:?string,addrCity:?string,country:?string,parsed:bool} $address
	 */
	private function fillEmptyAddressFields(Company $company, array $address): void
	{
		$patch = [];
		if (trim((string)($company->getAddrStreet() ?? '')) === '' && $address['addrStreet'] !== null) {
			$patch['addrStreet'] = $address['addrStreet'];
		}
		if (trim((string)($company->getAddrZip() ?? '')) === '' && $address['addrZip'] !== null) {
			$patch['addrZip'] = $address['addrZip'];
		}
		if (trim((string)($company->getAddrCity() ?? '')) === '' && $address['addrCity'] !== null) {
			$patch['addrCity'] = $address['addrCity'];
		}
		// Never override an explicit non-default country silently; only fill when still default DE and payload has another.
		if ($address['country'] !== null
			&& $address['country'] !== 'DE'
			&& strtoupper((string)$company->getCountry()) === 'DE') {
			$patch['country'] = $address['country'];
		}
		if ($patch === []) {
			return;
		}
		try {
			$this->companyService->update((int)$company->getId(), $patch, 'webhook', null, false, true);
		} catch (\Throwable) {
			// Best-effort enrichment on idempotent order retries — never fail the order.
		}
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
