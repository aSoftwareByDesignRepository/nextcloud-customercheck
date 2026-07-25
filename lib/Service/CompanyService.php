<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\EmailTouch;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEvent;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Company registry — PSD §4.1, §4.8, §8.7.
 *
 * The company is the anchor of the whole data model, so it is also the first
 * lock in the documented lock order (company → deal → agreement →
 * activity/hours → remlog/seq/pay → event). Every multi-table write in this
 * class acquires locks in exactly that direction.
 */
class CompanyService
{
	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly PersonMapper $personMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly DealMapper $dealMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly HoursMapper $hoursMapper,
		private readonly EmailTouchMapper $emailTouchMapper,
		private readonly TimelineEventMapper $timelineEventMapper,
		private readonly SlugService $slugService,
		private readonly TimelineService $timelineService,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	// ── Reads ───────────────────────────────────────────────────────────

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Company
	{
		try {
			return $this->companyMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException(
				$this->l10n->t('This company no longer exists.'),
				Constants::ERR_COMPANY_NOT_FOUND,
			);
		}
	}

	/**
	 * @throws NotFoundException
	 */
	public function getBySlug(string $slug): Company
	{
		$company = $this->companyMapper->findBySlug($slug);
		if ($company === null) {
			throw new NotFoundException(
				$this->l10n->t('This company no longer exists.'),
				Constants::ERR_COMPANY_NOT_FOUND,
			);
		}

		return $company;
	}

	/**
	 * @param array{status?: string, search?: string, archived?: bool} $filters
	 * @return Company[]
	 */
	public function search(array $filters = [], int $limit = 200, int $offset = 0): array
	{
		return $this->companyMapper->search($filters, $limit, $offset);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, Company>
	 */
	public function mapByIds(array $ids): array
	{
		return $this->companyMapper->findMapByIds($ids);
	}

	/**
	 * Acquire a write lock on the company row. Must run inside an open transaction.
	 *
	 * @throws NotFoundException
	 */
	public function lockRow(int $id): void
	{
		if (!$this->companyMapper->lockRow($id)) {
			throw new NotFoundException(
				$this->l10n->t('This company no longer exists.'),
				Constants::ERR_COMPANY_NOT_FOUND,
			);
		}
	}

	/**
	 * @param array{status?: string, search?: string, archived?: bool} $filters
	 */
	public function count(array $filters = []): int
	{
		return $this->companyMapper->countSearch($filters);
	}

	// ── Writes ──────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 */
	public function create(array $input, string $actor): Company
	{
		$now = $this->timeFactory->getDateTime();

		$company = new Company();
		$company->setCountry('DE');
		$company->setLang('de');
		$company->setStatus('lead');
		$company->setCreatedBy($actor);
		$company->setCreatedAt($now);
		$company->setUpdatedAt($now);

		$this->applyInput($company, $input, true);

		$requestedSlug = trim((string)($input['slug'] ?? ''));
		if ($requestedSlug !== '') {
			$company->setSlug($this->slugService->validateForCreate($requestedSlug));
			$stored = $this->insertWithSlug($company, false);
		} else {
			$company->setSlug($this->slugService->suggestUnique((string)$company->getName()));
			$stored = $this->insertWithSlug($company, true);
		}

		$this->timelineService->append((int)$stored->getId(), 'company.created', $actor, [
			'slug' => (string)$stored->getSlug(),
			'name' => (string)$stored->getName(),
		]);

		return $stored;
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function update(int $id, array $input, string $actor, ?string $updatedAtPrecondition): Company
	{
		$company = $this->get($id);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

		$before = [
			'name' => (string)$company->getName(),
			'status' => (string)$company->getStatus(),
			'slug' => (string)$company->getSlug(),
		];

		$this->applyInput($company, $input, false);

		// The slug is the cross-app key (§4.9.1) — renaming a company never moves it.
		$requestedSlug = trim((string)($input['slug'] ?? ''));
		if ($requestedSlug !== '' && $requestedSlug !== $before['slug']) {
			throw ValidationException::field(
				Constants::ERR_SLUG_INVALID,
				'slug',
				$this->l10n->t('The slug is the permanent key for this company and cannot be changed.'),
			);
		}

		$company->setUpdatedAt($this->timeFactory->getDateTime());
		$stored = $this->companyMapper->update($company);

		$this->timelineService->append($id, 'company.updated', $actor, [
			'changed' => $this->diffKeys($before, [
				'name' => (string)$stored->getName(),
				'status' => (string)$stored->getStatus(),
				'slug' => (string)$stored->getSlug(),
			]),
		]);

		return $stored;
	}

	/**
	 * Soft delete (§4.1): archived companies drop out of every default list but
	 * keep their history, agreements and revenue.
	 */
	public function archive(int $id, string $actor, ?string $updatedAtPrecondition): Company
	{
		$company = $this->get($id);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition, false);

		if ($company->isArchived()) {
			return $company;
		}

		$now = $this->timeFactory->getDateTime();
		$company->setArchivedAt($now);
		$company->setUpdatedAt($now);
		$stored = $this->companyMapper->update($company);

		$this->timelineService->append($id, 'company.archived', $actor, []);

		return $stored;
	}

	public function restore(int $id, string $actor, ?string $updatedAtPrecondition): Company
	{
		$company = $this->get($id);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition, false);

		if (!$company->isArchived()) {
			return $company;
		}

		$company->setArchivedAt(null);
		$company->setUpdatedAt($this->timeFactory->getDateTime());
		$stored = $this->companyMapper->update($company);

		$this->timelineService->append($id, 'company.restored', $actor, []);

		return $stored;
	}

	// ── GDPR (§4.8, §8.7) ───────────────────────────────────────────────

	/**
	 * Everything the app stores about one company, as a plain array ready to be
	 * serialised as the downloadable JSON export.
	 *
	 * @return array<string, mixed>
	 */
	public function gdprExport(int $id): array
	{
		$company = $this->get($id);

		return [
			'exportedAt' => $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM),
			'company' => $this->serialiseCompany($company),
			'persons' => array_map(
				fn (Person $p): array => [
					'id' => (int)$p->getId(),
					'name' => (string)$p->getName(),
					'role' => $p->getRole(),
					'email' => $p->getEmail(),
					'phone' => $p->getPhone(),
					'isPrimary' => $p->isPrimaryContact(),
					'notes' => $p->getNotes(),
					'createdAt' => $p->getCreatedAt()?->format(\DateTimeInterface::ATOM),
				],
				$this->personMapper->findByCompany($id),
			),
			'agreements' => array_map(
				fn (Agreement $a): array => [
					'id' => (int)$a->getId(),
					'kind' => (string)$a->getKind(),
					'productCode' => (string)$a->getProductCode(),
					'label' => (string)$a->getLabel(),
					'limits' => $a->decodedLimits(),
					'startsAt' => $a->getStartsAt()?->format('Y-m-d'),
					'validUntil' => $a->getValidUntil()?->format('Y-m-d'),
					'amountCents' => (int)$a->getAmountCents(),
					'invoiceRef' => $a->getInvoiceRef(),
					'invoiceStatus' => (string)$a->getInvoiceStatus(),
					'status' => (string)$a->getStatus(),
					'notes' => $a->getNotes(),
				],
				$this->agreementMapper->findByCompany($id),
			),
			'deals' => array_map(
				fn (Deal $d): array => [
					'id' => (int)$d->getId(),
					'title' => (string)$d->getTitle(),
					'stage' => (string)$d->getStage(),
					'valueCents' => (int)$d->getValueCents(),
					'expectedClose' => $d->getExpectedClose()?->format('Y-m-d'),
					'lostReason' => $d->getLostReason(),
				],
				$this->dealMapper->findByCompany($id),
			),
			'activities' => array_map(
				fn (Activity $a): array => [
					'id' => (int)$a->getId(),
					'type' => (string)$a->getType(),
					'title' => (string)$a->getTitle(),
					'details' => $a->getDetails(),
					'dueDate' => $a->getDueDate()?->format('Y-m-d'),
					'doneAt' => $a->getDoneAt()?->format(\DateTimeInterface::ATOM),
					'origin' => (string)$a->getOrigin(),
				],
				$this->activityMapper->search(['companyId' => $id], 5000),
			),
			'hours' => $this->exportHours($id),
			'emailTouches' => array_map(
				fn (EmailTouch $e): array => [
					'id' => (int)$e->getId(),
					'direction' => (string)$e->getDirection(),
					'from' => (string)$e->getFromAddr(),
					'to' => (string)$e->getToAddr(),
					'subject' => (string)$e->getSubject(),
					'occurredAt' => $e->getOccurredAt()?->format(\DateTimeInterface::ATOM),
					'source' => (string)$e->getSource(),
					'snippet' => $e->getSnippet(),
				],
				$this->emailTouchMapper->findByCompany($id, 5000),
			),
			'timeline' => array_map(
				fn (TimelineEvent $e): array => [
					'id' => (int)$e->getId(),
					'eventType' => (string)$e->getEventType(),
					'actor' => (string)$e->getActor(),
					'payload' => $e->decodedPayload(),
					'createdAt' => $e->getCreatedAt()?->format(\DateTimeInterface::ATOM),
				],
				$this->timelineEventMapper->findByCompany($id, 1000),
			),
		];
	}

	/**
	 * GDPR erase (§4.8).
	 *
	 * Hard-deleted: persons, activities, email touches and free-text timeline
	 * notes. Retained but blanked: agreements and hours (ten-year tax/GoBD
	 * retention), plus every remaining timeline event, which keeps its type and
	 * date with the payload replaced by `{"gdpr":"erased"}`.
	 *
	 * The confirmation is the typed slug, so a mis-click cannot destroy data.
	 *
	 * @return array{personsDeleted: int, activitiesDeleted: int, emailsDeleted: int, notesDeleted: int, agreementsAnonymised: int, hoursAnonymised: int, eventsAnonymised: int}
	 * @throws ValidationException when the typed confirmation does not match
	 */
	public function gdprErase(int $id, string $typedSlug, string $actor): array
	{
		$company = $this->get($id);
		$slug = (string)$company->getSlug();

		if (!hash_equals($slug, trim($typedSlug))) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'confirmSlug',
				$this->l10n->t('Type the company slug "%s" exactly to confirm the deletion.', [$slug]),
			);
		}

		$agreementIds = $this->agreementMapper->findIdsByCompany($id);

		// Lock order: company → deal → agreement → activity/hours → event.
		$this->db->beginTransaction();
		try {
			$now = $this->timeFactory->getDateTime();
			$company->setVatId(null);
			$company->setAddrStreet(null);
			$company->setAddrZip(null);
			$company->setAddrCity(null);
			$company->setWebsite(null);
			$company->setNotes(null);
			$company->setStatus('churned');
			$company->setArchivedAt($now);
			$company->setUpdatedAt($now);
			$this->companyMapper->update($company);

			$personsDeleted = $this->personMapper->deleteByCompany($id);
			// Lock order: company (above) → deals → agreements → activities/hours → events.
			$this->dealMapper->deleteByCompany($id);
			$agreementsAnonymised = $this->agreementMapper->anonymiseByCompany($id);
			$activitiesDeleted = $this->activityMapper->deleteByCompany($id);
			$hoursAnonymised = $this->hoursMapper->anonymiseByAgreements($agreementIds);
			$emailsDeleted = $this->emailTouchMapper->deleteByCompany($id);
			$notesDeleted = $this->timelineEventMapper->deleteNotesByCompany($id);
			$eventsAnonymised = $this->timelineEventMapper->anonymiseByCompany($id);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Written after the erase so it is the one event with a readable payload.
		$this->timelineService->append($id, 'gdpr.erased', $actor, [
			'personsDeleted' => $personsDeleted,
			'activitiesDeleted' => $activitiesDeleted,
			'emailsDeleted' => $emailsDeleted,
		]);

		return [
			'personsDeleted' => $personsDeleted,
			'activitiesDeleted' => $activitiesDeleted,
			'emailsDeleted' => $emailsDeleted,
			'notesDeleted' => $notesDeleted,
			'agreementsAnonymised' => $agreementsAnonymised,
			'hoursAnonymised' => $hoursAnonymised,
			'eventsAnonymised' => $eventsAnonymised,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function serialiseCompany(Company $company): array
	{
		return [
			'id' => (int)$company->getId(),
			'slug' => (string)$company->getSlug(),
			'name' => (string)$company->getName(),
			'vatId' => $company->getVatId(),
			'addrStreet' => $company->getAddrStreet(),
			'addrZip' => $company->getAddrZip(),
			'addrCity' => $company->getAddrCity(),
			'country' => (string)$company->getCountry(),
			'lang' => (string)$company->getLang(),
			'status' => (string)$company->getStatus(),
			'website' => $company->getWebsite(),
			'notes' => $company->getNotes(),
			'pcCustomerId' => $company->getPcCustomerId(),
			'tcCustomerId' => $company->getTcCustomerId(),
			'archived' => $company->isArchived(),
			'createdAt' => $company->getCreatedAt()?->format(\DateTimeInterface::ATOM),
			'updatedAt' => OptimisticLock::format($company->getUpdatedAt()),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function exportHours(int $companyId): array
	{
		$out = [];
		foreach ($this->agreementMapper->findByCompany($companyId) as $agreement) {
			foreach ($this->hoursMapper->findByAgreement((int)$agreement->getId(), 5000) as $entry) {
				$out[] = [
					'id' => (int)$entry->getId(),
					'agreementId' => (int)$entry->getAgreementId(),
					'entryDate' => $entry->getEntryDate()?->format('Y-m-d'),
					'minutes' => (int)$entry->getMinutes(),
					'topic' => $entry->getTopic(),
				];
			}
		}

		return $out;
	}

	/**
	 * Insert, retrying once with a fresh suffix when a concurrent request took
	 * the slug between the availability probe and the insert. Only auto-generated
	 * slugs are retried — an explicit slug must fail loudly as `SLUG_TAKEN`.
	 *
	 * @throws ValidationException
	 */
	private function insertWithSlug(Company $company, bool $mayRetry): Company
	{
		$attempts = $mayRetry ? 5 : 1;

		for ($attempt = 0; $attempt < $attempts; $attempt++) {
			try {
				return $this->companyMapper->insert($company);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				if (!$mayRetry || $attempt === $attempts - 1) {
					throw ValidationException::field(
						Constants::ERR_SLUG_TAKEN,
						'slug',
						$this->l10n->t('The slug "%s" is already in use. Pick another one.', [(string)$company->getSlug()]),
					);
				}
				// Re-probe: suggestUnique walks past whatever the winner just took.
				$company->setSlug($this->slugService->suggestUnique((string)$company->getName()));
			}
		}

		// Unreachable: the loop either returns or throws.
		throw ValidationException::field(
			Constants::ERR_SLUG_TAKEN,
			'slug',
			$this->l10n->t('The slug "%s" is already in use. Pick another one.', [(string)$company->getSlug()]),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 */
	private function applyInput(Company $company, array $input, bool $isCreate): void
	{
		$fields = [];

		if ($isCreate || array_key_exists('name', $input)) {
			$name = trim((string)($input['name'] ?? ''));
			if ($name === '') {
				$fields['name'] = $this->l10n->t('Enter the company name.');
			} elseif (mb_strlen($name) > 255) {
				$fields['name'] = $this->l10n->t('Keep the company name under 256 characters.');
			} else {
				$company->setName($name);
			}
		}

		if (array_key_exists('status', $input)) {
			$status = (string)$input['status'];
			if (!in_array($status, Constants::COMPANY_STATUSES, true)) {
				$fields['status'] = $this->l10n->t('Choose lead, active, paused or churned.');
			} else {
				$company->setStatus($status);
			}
		}

		if (array_key_exists('lang', $input)) {
			$lang = mb_strtolower(trim((string)$input['lang']));
			if (!in_array($lang, Constants::LANGUAGES, true)) {
				$fields['lang'] = $this->l10n->t('Choose German or English.');
			} else {
				$company->setLang($lang);
			}
		}

		if (array_key_exists('country', $input)) {
			$country = strtoupper(trim((string)$input['country']));
			if (!preg_match('/^[A-Z]{2}$/', $country)) {
				$fields['country'] = $this->l10n->t('Use the two-letter country code, for example DE.');
			} else {
				$company->setCountry($country);
			}
		}

		if (array_key_exists('vatId', $input)) {
			$vatId = strtoupper(preg_replace('/\s+/', '', (string)($input['vatId'] ?? '')) ?? '');
			if ($vatId !== '' && !preg_match('/^[A-Z0-9]{4,32}$/', $vatId)) {
				$fields['vatId'] = $this->l10n->t('Enter the VAT ID without spaces, for example DE123456789.');
			} else {
				$company->setVatId($vatId === '' ? null : $vatId);
			}
		}

		if (array_key_exists('website', $input)) {
			$website = trim((string)($input['website'] ?? ''));
			if ($website !== '' && !$this->isAcceptableUrl($website)) {
				$fields['website'] = $this->l10n->t('Enter a web address starting with http:// or https://.');
			} else {
				$company->setWebsite($website === '' ? null : mb_substr($website, 0, 255));
			}
		}

		$this->applyOptionalString($company, $input, 'addrStreet', 'setAddrStreet', 255, $fields);
		$this->applyOptionalString($company, $input, 'addrZip', 'setAddrZip', 16, $fields);
		$this->applyOptionalString($company, $input, 'addrCity', 'setAddrCity', 128, $fields);

		if (array_key_exists('notes', $input)) {
			$notes = trim((string)($input['notes'] ?? ''));
			if (mb_strlen($notes) > 10000) {
				$fields['notes'] = $this->l10n->t('Keep the notes under 10000 characters.');
			} else {
				$company->setNotes($notes === '' ? null : $notes);
			}
		}

		if (array_key_exists('pcCustomerId', $input)) {
			$pcId = (int)($input['pcCustomerId'] ?? 0);
			$company->setPcCustomerId($pcId > 0 ? $pcId : null);
		}
		if (array_key_exists('tcCustomerId', $input)) {
			$tcId = (int)($input['tcCustomerId'] ?? 0);
			$company->setTcCustomerId($tcId > 0 ? $tcId : null);
		}

		if ($isCreate && (string)$company->getName() === '' && !isset($fields['name'])) {
			$fields['name'] = $this->l10n->t('Enter the company name.');
		}

		if ($fields !== []) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Please correct the highlighted fields.'),
				$fields,
			);
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, string> $fields
	 */
	private function applyOptionalString(
		Company $company,
		array $input,
		string $key,
		string $setter,
		int $maxLength,
		array &$fields,
	): void {
		if (!array_key_exists($key, $input)) {
			return;
		}

		$value = trim((string)($input[$key] ?? ''));
		if (mb_strlen($value) > $maxLength) {
			$fields[$key] = $this->l10n->n(
				'Keep this field under %n character.',
				'Keep this field under %n characters.',
				$maxLength + 1,
			);

			return;
		}

		$company->{$setter}($value === '' ? null : $value);
	}

	private function isAcceptableUrl(string $url): bool
	{
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

		return in_array($scheme, ['http', 'https'], true);
	}

	/**
	 * @param array<string, string> $before
	 * @param array<string, string> $after
	 * @return array<string, array{from: string, to: string}>
	 */
	private function diffKeys(array $before, array $after): array
	{
		$diff = [];
		foreach ($after as $key => $value) {
			$old = $before[$key] ?? '';
			if ($old !== $value) {
				$diff[$key] = ['from' => $old, 'to' => $value];
			}
		}

		return $diff;
	}
}
