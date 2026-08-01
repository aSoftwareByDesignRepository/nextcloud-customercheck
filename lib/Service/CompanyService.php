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
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
		$wsId = (int)($input['workspaceId'] ?? 0);
		if ($wsId <= 0) {
			$wsId = $this->resolveDefaultWorkspaceId();
		}
		if ($wsId <= 0) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Please correct the highlighted fields.'),
				['workspaceId' => $this->l10n->t('Choose a workspace for this company.')],
			);
		}

		$now = $this->timeFactory->getDateTime();

		$company = new Company();
		$company->setCountry('DE');
		$company->setLang('de');
		$company->setStatus('lead');
		$company->setWorkspaceId($wsId);
		$company->setCreatedBy($actor);
		$company->setCreatedAt($now);
		$company->setUpdatedAt($now);

		$this->applyInput($company, $input, true);

		if (trim((string)($company->getAccountOwnerUserId() ?? '')) === '') {
			$company->setAccountOwnerUserId($actor);
		}

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
	public function update(
		int $id,
		array $input,
		string $actor,
		?string $updatedAtPrecondition,
		bool $canReassignOwners = false,
	): Company
	{
		// Serialize vs archive/restore/move/erase — stamp alone last-write-wins.
		$this->db->beginTransaction();
		try {
			if (!$this->companyMapper->lockRow($id)) {
				throw new NotFoundException(
					$this->l10n->t('This company no longer exists.'),
					Constants::ERR_COMPANY_NOT_FOUND,
				);
			}
			$company = $this->companyMapper->findById($id);
			OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

			$before = [
				'name' => (string)$company->getName(),
				'status' => (string)$company->getStatus(),
				'slug' => (string)$company->getSlug(),
			];

			if (array_key_exists('accountOwnerUserId', $input)) {
				$newOwner = trim((string)($input['accountOwnerUserId'] ?? ''));
				$current = trim((string)($company->getAccountOwnerUserId() ?? ''));
				if ($newOwner !== $current) {
					$mayReassign = $current === ''
						|| $current === $actor;
					if (!$mayReassign) {
						throw ValidationException::field(
							Constants::ERR_DEAL_OWNED,
							'accountOwnerUserId',
							$this->l10n->t('This account already has an owner. Ask them or a manager to reassign it.'),
						);
					}
				}
			}

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
			try {
				$stored = $this->companyMapper->update($company);
			} catch (DbException $e) {
				$this->rethrowMappedUniqueViolation($e, (string)$company->getSlug());
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

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
	 * Move a company between workspaces (§6).
	 *
	 * Requires slug confirmation and an optimistic lock. Shared-mail matches are
	 * re-triaged to unmatched so the old book’s mailbox never points at a company
	 * that left. Files folder ACL is intentionally not changed (caller warns).
	 *
	 * @return array{company: Company, fromWorkspaceId: int, toWorkspaceId: int, mailUnmatched: int, draftsDetached: int}
	 * @throws ValidationException
	 * @throws ConflictException
	 * @throws NotFoundException
	 */
	public function moveToWorkspace(
		int $id,
		int $toWorkspaceId,
		string $confirmSlug,
		string $actor,
		?string $updatedAtPrecondition,
	): array {
		$company = $this->get($id);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

		$slug = (string)$company->getSlug();
		if (!hash_equals($slug, trim($confirmSlug))) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'confirmSlug',
				$this->l10n->t('Type the company slug "%s" exactly to confirm the move.', [$slug]),
			);
		}

		$fromWorkspaceId = (int)($company->getWorkspaceId() ?? 0);
		if ($toWorkspaceId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'workspaceId',
				$this->l10n->t('Choose a destination workspace.'),
			);
		}
		if ($toWorkspaceId === $fromWorkspaceId) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'workspaceId',
				$this->l10n->t('This company is already in that workspace.'),
			);
		}
		if (!$this->workspaceExists($toWorkspaceId)) {
			throw new NotFoundException(
				$this->l10n->t('The destination workspace was not found.'),
				Constants::ERR_NOT_FOUND,
			);
		}

		$mailUnmatched = 0;
		$draftsDetached = 0;
		$promisesRestamped = 0;
		$plansRestamped = 0;
		$escalationsRestamped = 0;
		$commitsRestamped = 0;
		$this->db->beginTransaction();
		try {
			// Serialize concurrent moves (parity with merge lockRow) — two writers
			// with the same updatedAt token must not both restamp ledgers.
			if (!$this->companyMapper->lockRow($id)) {
				throw new NotFoundException(
					$this->l10n->t('This company no longer exists.'),
					Constants::ERR_COMPANY_NOT_FOUND,
				);
			}
			$company = $this->companyMapper->findById($id);
			OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);
			$fromWorkspaceId = (int)($company->getWorkspaceId() ?? 0);
			if ($toWorkspaceId === $fromWorkspaceId) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'workspaceId',
					$this->l10n->t('This company is already in that workspace.'),
				);
			}

			$now = $this->timeFactory->getDateTime();
			$company->setWorkspaceId($toWorkspaceId);
			$company->setUpdatedAt($now);
			$stored = $this->companyMapper->update($company);

			// Old book mailbox must not keep a match to a company that left.
			if ($this->db->tableExists('crm_mail_msg')) {
				$qb = $this->db->getQueryBuilder();
				$mailUnmatched = $qb->update('crm_mail_msg')
					->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->set('matched_how', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->set('triage_status', $qb->createNamedParameter('open'))
					->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id)))
					->executeStatement();
			}

			// Drafts are workspace-scoped — clear the company pointer so old WS
			// drafts do not reference a company that now lives elsewhere.
			if ($this->db->tableExists('crm_mail_dft')) {
				$qb = $this->db->getQueryBuilder();
				$draftsDetached = $qb->update('crm_mail_dft')
					->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id)))
					->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($fromWorkspaceId)))
					->executeStatement();
			}

			// Denormalised ledger workspace_id must follow the company — otherwise
			// Today/Strategy keep listing the row on the old book while complete()
			// looks up the new book and 404s (§3.3).
			if ($this->db->tableExists('crm_cust_prm')) {
				$qb = $this->db->getQueryBuilder();
				$promisesRestamped = $qb->update('crm_cust_prm')
					->set('workspace_id', $qb->createNamedParameter($toWorkspaceId, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
			if ($this->db->tableExists('crm_acct_plan')) {
				$qb = $this->db->getQueryBuilder();
				$plansRestamped = $qb->update('crm_acct_plan')
					->set('workspace_id', $qb->createNamedParameter($toWorkspaceId, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
			if ($this->db->tableExists('crm_escal')) {
				$qb = $this->db->getQueryBuilder();
				$escalationsRestamped = $qb->update('crm_escal')
					->set('workspace_id', $qb->createNamedParameter($toWorkspaceId, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
			if ($this->db->tableExists('crm_commit')) {
				$qb = $this->db->getQueryBuilder();
				$commitsRestamped = $qb->update('crm_commit')
					->set('workspace_id', $qb->createNamedParameter($toWorkspaceId, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->timelineService->append($id, 'company.moved', $actor, [
			'fromWorkspaceId' => $fromWorkspaceId,
			'toWorkspaceId' => $toWorkspaceId,
			'mailUnmatched' => $mailUnmatched,
			'draftsDetached' => $draftsDetached,
			'promisesRestamped' => $promisesRestamped,
			'plansRestamped' => $plansRestamped,
			'escalationsRestamped' => $escalationsRestamped,
			'commitsRestamped' => $commitsRestamped,
		]);

		return [
			'company' => $stored,
			'fromWorkspaceId' => $fromWorkspaceId,
			'toWorkspaceId' => $toWorkspaceId,
			'mailUnmatched' => $mailUnmatched,
			'draftsDetached' => $draftsDetached,
			'promisesRestamped' => $promisesRestamped,
			'plansRestamped' => $plansRestamped,
		];
	}

	private function workspaceExists(int $workspaceId): bool
	{
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_workspace')) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_workspace')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();

		return $row !== false;
	}

	/**
	 * Soft delete (§4.1): archived companies drop out of every default list but
	 * keep their history, agreements and revenue.
	 */
	public function archive(int $id, string $actor, ?string $updatedAtPrecondition): Company
	{
		$this->db->beginTransaction();
		try {
			if (!$this->companyMapper->lockRow($id)) {
				throw new NotFoundException(
					$this->l10n->t('This company no longer exists.'),
					Constants::ERR_COMPANY_NOT_FOUND,
				);
			}
			$company = $this->companyMapper->findById($id);
			OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition, false);

			if ($company->isArchived()) {
				$this->db->commit();
				return $company;
			}

			$now = $this->timeFactory->getDateTime();
			$company->setArchivedAt($now);
			$company->setUpdatedAt($now);
			$stored = $this->companyMapper->update($company);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->timelineService->append($id, 'company.archived', $actor, []);

		return $stored;
	}

	public function restore(int $id, string $actor, ?string $updatedAtPrecondition): Company
	{
		$this->db->beginTransaction();
		try {
			if (!$this->companyMapper->lockRow($id)) {
				throw new NotFoundException(
					$this->l10n->t('This company no longer exists.'),
					Constants::ERR_COMPANY_NOT_FOUND,
				);
			}
			$company = $this->companyMapper->findById($id);
			OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition, false);

			if (!$company->isArchived()) {
				$this->db->commit();
				return $company;
			}

			$company->setArchivedAt(null);
			$company->setUpdatedAt($this->timeFactory->getDateTime());
			$stored = $this->companyMapper->update($company);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

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

		$mergeNotes = [];
		foreach ($this->timelineEventMapper->findByCompany($id, 1000) as $event) {
			if ((string)$event->getEventType() !== Constants::EVENT_COMPANY_MERGED) {
				continue;
			}
			$payload = $event->decodedPayload();
			$mergeNotes[] = [
				'at' => $event->getCreatedAt()?->format(\DateTimeInterface::ATOM),
				'sourceId' => (int)($payload['sourceId'] ?? 0),
				'sourceSlug' => (string)($payload['sourceSlug'] ?? ''),
				'sourceName' => (string)($payload['sourceName'] ?? ''),
				'moved' => $payload['moved'] ?? null,
			];
		}

		return [
			'exportedAt' => $this->timeFactory->getDateTime()->format(\DateTimeInterface::ATOM),
			'company' => $this->serialiseCompany($company),
			'mergedHistory' => $mergeNotes,
			'persons' => array_map(
				fn (Person $p): array => [
					'id' => (int)$p->getId(),
					'name' => (string)$p->getName(),
					'role' => $p->getRole(),
					'buyingRole' => $p->getBuyingRole(),
					'status' => method_exists($p, 'getStatus') ? (string)$p->getStatus() : 'active',
					'reportsToPersonId' => method_exists($p, 'getReportsToPersonId') ? $p->getReportsToPersonId() : null,
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
					'ownerUserId' => $d->getOwnerUserId(),
					'forecastBucket' => $d->getForecastBucket(),
					'lostReason' => $d->getLostReason(),
					'lostReasonCode' => $d->getLostReasonCode(),
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
			'customerPromises' => $this->exportPromises($id),
			'accountPlans' => $this->exportPlans($id),
			'sharedMail' => $this->exportSharedMail($id),
			'mailDrafts' => $this->exportMailDrafts($id),
			'escalations' => $this->exportEscalations($id),
			'commitments' => $this->exportCommitments($id),
		];
	}

	/**
	 * GDPR erase (§4.8 / §18.9).
	 *
	 * Hard-deleted: persons, deals, activities, email touches, customer promises,
	 * account plans + MAP steps, and free-text timeline notes. Shared mail rows
	 * are re-triaged to unmatched and PII fields scrubbed (subject/from/snippet/
	 * preview). Retained but blanked: agreements and hours (ten-year tax/GoBD
	 * retention), plus remaining timeline events (`{"gdpr":"erased"}`).
	 *
	 * The confirmation is the typed slug, so a mis-click cannot destroy data.
	 *
	 * @return array{
	 *   personsDeleted: int, activitiesDeleted: int, emailsDeleted: int, notesDeleted: int,
	 *   agreementsAnonymised: int, hoursAnonymised: int, eventsAnonymised: int,
	 *   promisesDeleted: int, plansDeleted: int, mapStepsDeleted: int, mailUnmatched: int
	 * }
	 * @throws ValidationException when the typed confirmation does not match
	 */
	public function gdprErase(int $id, string $typedSlug, string $actor): array
	{
		$personsDeleted = 0;
		$activitiesDeleted = 0;
		$emailsDeleted = 0;
		$notesDeleted = 0;
		$agreementsAnonymised = 0;
		$hoursAnonymised = 0;
		$eventsAnonymised = 0;
		$persona = [
			'promisesDeleted' => 0,
			'plansDeleted' => 0,
			'mapStepsDeleted' => 0,
			'mailUnmatched' => 0,
		];

		// Lock order: company → deal → agreement → activity/hours → event.
		$this->db->beginTransaction();
		try {
			if (!$this->companyMapper->lockRow($id)) {
				throw new NotFoundException(
					$this->l10n->t('This company no longer exists.'),
					Constants::ERR_COMPANY_NOT_FOUND,
				);
			}
			$company = $this->companyMapper->findById($id);
			$slug = (string)$company->getSlug();
			if (!hash_equals($slug, trim($typedSlug))) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'confirmSlug',
					$this->l10n->t('Type the company slug "%s" exactly to confirm the deletion.', [$slug]),
				);
			}

			$agreementIds = $this->agreementMapper->findIdsByCompany($id);

			$now = $this->timeFactory->getDateTime();
			$company->setVatId(null);
			$company->setAddrStreet(null);
			$company->setAddrZip(null);
			$company->setAddrCity(null);
			$company->setWebsite(null);
			$company->setNotes(null);
			$company->setStatus('churned');
			// Anonymise the shell — name/slug remain unique but no longer identify the customer (§18.9).
			$company->setName($this->l10n->t('Erased company %s', [(string)$id]));
			$company->setSlug('erased-' . $id);
			if (method_exists($company, 'setAccountOwnerUserId')) {
				$company->setAccountOwnerUserId(null);
			}
			if (method_exists($company, 'setPcCustomerId')) {
				$company->setPcCustomerId(null);
			}
			if (method_exists($company, 'setTcCustomerId')) {
				$company->setTcCustomerId(null);
			}
			// Detach Files folder pointers — CRM must not keep deep-links into personal docs (§18.9).
			// Nodes stay in Nextcloud Files (platform SoR); only the CRM link is cleared.
			if (method_exists($company, 'setFilesFolderId')) {
				$company->setFilesFolderId(null);
			}
			if (method_exists($company, 'setFilesFolderPath')) {
				$company->setFilesFolderPath(null);
			}
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
			$persona = $this->erasePersonaLedgers($id);
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
			'promisesDeleted' => $persona['promisesDeleted'],
			'plansDeleted' => $persona['plansDeleted'],
			'mailUnmatched' => $persona['mailUnmatched'],
		]);

		return [
			'personsDeleted' => $personsDeleted,
			'activitiesDeleted' => $activitiesDeleted,
			'emailsDeleted' => $emailsDeleted,
			'notesDeleted' => $notesDeleted,
			'agreementsAnonymised' => $agreementsAnonymised,
			'hoursAnonymised' => $hoursAnonymised,
			'eventsAnonymised' => $eventsAnonymised,
			'promisesDeleted' => $persona['promisesDeleted'],
			'plansDeleted' => $persona['plansDeleted'],
			'mapStepsDeleted' => $persona['mapStepsDeleted'],
			'mailUnmatched' => $persona['mailUnmatched'],
		];
	}

	/**
	 * Cascade customer promises, account plans/MAP, and re-triage shared mail (§18.9).
	 *
	 * @return array{promisesDeleted: int, plansDeleted: int, mapStepsDeleted: int, mailUnmatched: int, draftsScrubbed: int, commitsCleared: int, escalationsCleared: int}
	 */
	private function erasePersonaLedgers(int $companyId): array
	{
		$promisesDeleted = 0;
		$plansDeleted = 0;
		$mapStepsDeleted = 0;
		$mailUnmatched = 0;
		$commitsCleared = 0;
		$escalationsCleared = 0;

		if ($this->db->tableExists('crm_cust_prm')) {
			$qb = $this->db->getQueryBuilder();
			$promisesDeleted = $qb->delete('crm_cust_prm')
				->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
				->executeStatement();
		}

		$planIds = [];
		if ($this->db->tableExists('crm_acct_plan')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('crm_acct_plan')
				->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)));
			$r = $qb->executeQuery();
			while ($row = $r->fetch()) {
				$planIds[] = (int)$row['id'];
			}
			$r->closeCursor();
		}

		if ($planIds !== [] && $this->db->tableExists('crm_map_step')) {
			$qb = $this->db->getQueryBuilder();
			$params = [];
			foreach ($planIds as $i => $pid) {
				$params[] = $qb->createNamedParameter($pid, null, 'pid' . $i);
			}
			$mapStepsDeleted = $qb->delete('crm_map_step')
				->where($qb->expr()->in('plan_id', $params))
				->executeStatement();
		}

		// Milestones carry free-text titles — delete before the parent plan (§18.9).
		if ($planIds !== [] && $this->db->tableExists('crm_plan_ms')) {
			$qb = $this->db->getQueryBuilder();
			$params = [];
			foreach ($planIds as $i => $pid) {
				$params[] = $qb->createNamedParameter($pid, null, 'pmid' . $i);
			}
			$qb->delete('crm_plan_ms')
				->where($qb->expr()->in('plan_id', $params))
				->executeStatement();
		}

		if ($this->db->tableExists('crm_acct_plan')) {
			$qb = $this->db->getQueryBuilder();
			$plansDeleted = $qb->delete('crm_acct_plan')
				->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
				->executeStatement();
		}

		// Shared mail: re-triage + scrub PII so Unmatched triage cannot resurface
		// erased company content (§18.9 — purge fields; keep message_uid for sync).
		if ($this->db->tableExists('crm_mail_msg')) {
			$qb = $this->db->getQueryBuilder();
			$mailUnmatched = $qb->update('crm_mail_msg')
				->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('matched_how', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('triage_status', $qb->createNamedParameter('open'))
				->set('from_addr', $qb->createNamedParameter('[erased]'))
				->set('to_addrs', $qb->createNamedParameter(''))
				->set('subject', $qb->createNamedParameter('[erased]'))
				->set('snippet', $qb->createNamedParameter(''))
				->set('preview', $qb->createNamedParameter(''))
				->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
				->executeStatement();
		}

		// Failed/claim drafts: scrub bodies — never leave recipient/subject/body after erase (§18.9).
		$draftsScrubbed = 0;
		if ($this->db->tableExists('crm_mail_dft')) {
			$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
			$qb = $this->db->getQueryBuilder();
			$draftsScrubbed = $qb->update('crm_mail_dft')
				->set('to_addrs', $qb->createNamedParameter(''))
				->set('subject', $qb->createNamedParameter('[erased]'))
				->set('body_text', $qb->createNamedParameter(''))
				->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
				->executeStatement();
		}

		// Weekly commitments: drop company link and scrub free-text titles/notes (§18.9).
		if ($this->db->tableExists('crm_commit')) {
			$qb = $this->db->getQueryBuilder();
			$commitsCleared = $qb->update('crm_commit')
				->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('title', $qb->createNamedParameter('[erased]'))
				->set('verify_note', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('evidence', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
				->executeStatement();
		}

		if ($this->db->tableExists('crm_escal')) {
			$qb = $this->db->getQueryBuilder();
			$escalationsCleared = $qb->update('crm_escal')
				->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('deal_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('reason', $qb->createNamedParameter('[erased]'))
				->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
				->executeStatement();
		}

		return [
			'promisesDeleted' => $promisesDeleted,
			'plansDeleted' => $plansDeleted,
			'mapStepsDeleted' => $mapStepsDeleted,
			'mailUnmatched' => $mailUnmatched,
			'draftsScrubbed' => $draftsScrubbed,
			'commitsCleared' => $commitsCleared,
			'escalationsCleared' => $escalationsCleared,
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
			'workspaceId' => $company->getWorkspaceId(),
			'kamTier' => method_exists($company, 'getKamTier') ? (string)$company->getKamTier() : 'standard',
			'kamTierLock' => method_exists($company, 'getKamTierLock') ? (int)$company->getKamTierLock() : 0,
			'accountOwnerUserId' => method_exists($company, 'getAccountOwnerUserId') ? $company->getAccountOwnerUserId() : null,
			'touchIntervalDays' => method_exists($company, 'getTouchIntervalDays') ? $company->getTouchIntervalDays() : null,
			'nextTouchOn' => method_exists($company, 'getNextTouchOn') ? $company->getNextTouchOn()?->format('Y-m-d') : null,
			'pcCustomerId' => $company->getPcCustomerId(),
			'tcCustomerId' => $company->getTcCustomerId(),
			'filesFolderId' => $company->getFilesFolderId(),
			'filesFolderPath' => $company->getFilesFolderPath(),
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
	 * @return list<array<string, mixed>>
	 */
	private function exportPromises(int $companyId): array
	{
		if (!$this->db->tableExists('crm_cust_prm')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_cust_prm')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->orderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'title' => (string)$row['title'],
				'dueOn' => $row['due_on'] !== null ? (string)$row['due_on'] : null,
				'status' => (string)$row['status'],
				'ownerUserId' => $row['owner_user_id'] !== null ? (string)$row['owner_user_id'] : null,
				'evidence' => $row['evidence'] !== null ? (string)$row['evidence'] : null,
			];
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function exportPlans(int $companyId): array
	{
		if (!$this->db->tableExists('crm_acct_plan')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_acct_plan')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->orderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$planId = (int)$row['id'];
			$map = [];
			if ($this->db->tableExists('crm_map_step')) {
				$mq = $this->db->getQueryBuilder();
				$mq->select('*')->from('crm_map_step')
					->where($mq->expr()->eq('plan_id', $mq->createNamedParameter($planId)))
					->orderBy('sort_order', 'ASC');
				$mr = $mq->executeQuery();
				while ($step = $mr->fetch()) {
					$map[] = [
						'id' => (int)$step['id'],
						'title' => (string)$step['title'],
						'status' => (string)$step['status'],
					];
				}
				$mr->closeCursor();
			}
			$out[] = [
				'id' => $planId,
				'goal' => (string)($row['goal'] ?? ''),
				'status' => (string)($row['status'] ?? ''),
				'ownerUserId' => $row['owner_user_id'] !== null ? (string)$row['owner_user_id'] : null,
				'mapSteps' => $map,
			];
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * Shared mailbox index rows matched to this company (DSAR completeness).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function exportSharedMail(int $companyId): array
	{
		if (!$this->db->tableExists('crm_mail_msg')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			'id',
			'mailbox_id',
			'direction',
			'from_addr',
			'to_addrs',
			'subject',
			'snippet',
			'sent_at',
			'matched_how',
			'triage_status',
		)->from('crm_mail_msg')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->orderBy('id', 'ASC')
			->setMaxResults(5000);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'mailboxId' => (int)$row['mailbox_id'],
				'direction' => (string)($row['direction'] ?? ''),
				'from' => $row['from_addr'] !== null ? (string)$row['from_addr'] : null,
				'to' => $row['to_addrs'] !== null ? (string)$row['to_addrs'] : null,
				'subject' => $row['subject'] !== null ? (string)$row['subject'] : null,
				'snippet' => $row['snippet'] !== null ? (string)$row['snippet'] : null,
				'sentAt' => $row['sent_at'] !== null ? (string)$row['sent_at'] : null,
				'matchedHow' => $row['matched_how'] !== null ? (string)$row['matched_how'] : null,
				'triageStatus' => (string)($row['triage_status'] ?? ''),
			];
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * Failed/claim drafts attached to this company (DSAR completeness).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function exportMailDrafts(int $companyId): array
	{
		if (!$this->db->tableExists('crm_mail_dft')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'mailbox_id', 'to_addrs', 'subject', 'body_text', 'created_by', 'created_at', 'updated_at')
			->from('crm_mail_dft')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->orderBy('id', 'ASC')
			->setMaxResults(500);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'mailboxId' => (int)$row['mailbox_id'],
				'to' => $row['to_addrs'] !== null ? (string)$row['to_addrs'] : null,
				'subject' => $row['subject'] !== null ? (string)$row['subject'] : null,
				'body' => $row['body_text'] !== null ? (string)$row['body_text'] : null,
				'createdBy' => $row['created_by'] !== null ? (string)$row['created_by'] : null,
				'createdAt' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
				'updatedAt' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
			];
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * Open escalations linked to this company (DSAR completeness).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function exportEscalations(int $companyId): array
	{
		if (!$this->db->tableExists('crm_escal')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_escal')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->orderBy('id', 'ASC')
			->setMaxResults(500);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'reason' => isset($row['reason']) ? (string)$row['reason'] : null,
				'status' => isset($row['status']) ? (string)$row['status'] : null,
				'ownerUserId' => isset($row['owner_user_id']) && $row['owner_user_id'] !== null
					? (string)$row['owner_user_id'] : null,
				'createdAt' => isset($row['created_at']) && $row['created_at'] !== null
					? (string)$row['created_at'] : null,
			];
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * Weekly Brief commitments linked to this company (DSAR completeness — erase
	 * scrubs these titles, so export must include them while they still exist).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function exportCommitments(int $companyId): array
	{
		if (!$this->db->tableExists('crm_commit')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_commit')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->orderBy('id', 'ASC')
			->setMaxResults(500);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'weekKey' => isset($row['week_key']) ? (string)$row['week_key'] : null,
				'kind' => isset($row['kind']) ? (string)$row['kind'] : null,
				'title' => isset($row['title']) ? (string)$row['title'] : null,
				'status' => isset($row['status']) ? (string)$row['status'] : null,
				'ownerUserId' => isset($row['owner_user_id']) ? (string)$row['owner_user_id'] : null,
				'dueOn' => isset($row['due_on']) && $row['due_on'] !== null ? (string)$row['due_on'] : null,
				'evidence' => isset($row['evidence']) && $row['evidence'] !== null ? (string)$row['evidence'] : null,
				'createdAt' => isset($row['created_at']) && $row['created_at'] !== null
					? (string)$row['created_at'] : null,
			];
		}
		$r->closeCursor();
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
				// Sibling unique indexes must never be mislabeled as SLUG_TAKEN.
				$sibling = $this->classifySiblingUniqueViolation($e);
				if ($sibling !== null) {
					$this->throwSiblingLinkConflict($sibling);
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
	 * Map unique-index races (SHARED-IDENTITY I5) to typed API errors instead of 500s.
	 *
	 * @return never
	 */
	private function rethrowMappedUniqueViolation(DbException $e, string $slug): void
	{
		if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			throw $e;
		}
		$sibling = $this->classifySiblingUniqueViolation($e);
		if ($sibling !== null) {
			$this->throwSiblingLinkConflict($sibling);
		}
		throw ValidationException::field(
			Constants::ERR_SLUG_TAKEN,
			'slug',
			$this->l10n->t('The slug "%s" is already in use. Pick another one.', [$slug]),
		);
	}

	/**
	 * @return 'pc'|'tc'|null
	 */
	private function classifySiblingUniqueViolation(DbException $e): ?string
	{
		$haystack = strtolower($e->getMessage());
		$previous = $e->getPrevious();
		if ($previous instanceof \Throwable) {
			$haystack .= ' ' . strtolower($previous->getMessage());
		}
		if (str_contains($haystack, 'crm_co_pc_uq')
			|| (str_contains($haystack, 'pc_customer_id') && str_contains($haystack, 'unique'))) {
			return 'pc';
		}
		if (str_contains($haystack, 'crm_co_tc_uq')
			|| (str_contains($haystack, 'tc_customer_id') && str_contains($haystack, 'unique'))) {
			return 'tc';
		}

		return null;
	}

	/**
	 * @param 'pc'|'tc' $sibling
	 * @return never
	 */
	private function throwSiblingLinkConflict(string $sibling): void
	{
		if ($sibling === 'pc') {
			throw new ConflictException(
				$this->l10n->t('This ProjectCheck customer is already linked to another company.'),
				Constants::ERR_LINK_CONFLICT,
			);
		}
		throw new ConflictException(
			$this->l10n->t('This TicketCheck customer is already linked to another company.'),
			Constants::ERR_LINK_CONFLICT,
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
			if ($pcId > 0) {
				$other = $this->companyMapper->findByPcCustomerId($pcId);
				if ($other !== null && (int)$other->getId() !== (int)($company->getId() ?? 0)) {
					$fields['pcCustomerId'] = $this->l10n->t('This ProjectCheck customer is already linked to another company.');
				} else {
					$company->setPcCustomerId($pcId);
				}
			} else {
				$company->setPcCustomerId(null);
			}
		}
		if (array_key_exists('tcCustomerId', $input)) {
			$tcId = (int)($input['tcCustomerId'] ?? 0);
			if ($tcId > 0) {
				$other = $this->companyMapper->findByTcCustomerId($tcId);
				if ($other !== null && (int)$other->getId() !== (int)($company->getId() ?? 0)) {
					$fields['tcCustomerId'] = $this->l10n->t('This TicketCheck customer is already linked to another company.');
				} else {
					$company->setTcCustomerId($tcId);
				}
			} else {
				$company->setTcCustomerId(null);
			}
		}

		if (array_key_exists('filesFolderId', $input)) {
			$fid = $input['filesFolderId'];
			if ($fid === null || $fid === '' || (int)$fid <= 0) {
				$company->setFilesFolderId(null);
			} else {
				$company->setFilesFolderId((int)$fid);
			}
		}
		if (array_key_exists('filesFolderPath', $input)) {
			$path = $input['filesFolderPath'];
			if ($path === null || trim((string)$path) === '') {
				$company->setFilesFolderPath(null);
			} else {
				$company->setFilesFolderPath(mb_substr(trim((string)$path), 0, 4000));
			}
		}

		if (array_key_exists('workspaceId', $input)) {
			$ws = (int)($input['workspaceId'] ?? 0);
			if ($isCreate) {
				if ($ws > 0) {
					$company->setWorkspaceId($ws);
				}
			} elseif ($ws > 0 && $ws !== (int)($company->getWorkspaceId() ?? 0)) {
				// Moves require confirm slug + mail rematch — never via generic PUT.
				$fields['workspaceId'] = $this->l10n->t('Use Move to workspace to change the book. Files sharing is not moved automatically.');
			}
		}

		if (array_key_exists('kamTier', $input)) {
			$tier = trim((string)$input['kamTier']);
			if (!in_array($tier, Constants::KAM_TIERS, true)) {
				$fields['kamTier'] = $this->l10n->t('Choose standard, key or partner.');
			} else {
				$company->setKamTier($tier);
			}
		}
		if (array_key_exists('kamTierLock', $input)) {
			$company->setKamTierLock(!empty($input['kamTierLock']) ? 1 : 0);
		}
		if (array_key_exists('accountOwnerUserId', $input)) {
			$owner = trim((string)($input['accountOwnerUserId'] ?? ''));
			$company->setAccountOwnerUserId($owner !== '' ? $owner : null);
		}
		if (array_key_exists('touchIntervalDays', $input)) {
			$days = (int)($input['touchIntervalDays'] ?? 0);
			$company->setTouchIntervalDays($days > 0 ? $days : null);
		}
		if (array_key_exists('nextTouchOn', $input)) {
			$nt = \OCA\CustomerCheck\Support\DateMath::parseStrictDate((string)($input['nextTouchOn'] ?? ''));
			$company->setNextTouchOn($nt !== null ? new \DateTime($nt->format('Y-m-d')) : null);
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
	 * Bootstrap / webhook / import path: attach to the Default workspace when
	 * the caller did not pass workspaceId (product §6).
	 */
	private function resolveDefaultWorkspaceId(): int
	{
		try {
			if (!$this->db->tableExists('crm_workspace')) {
				return 0;
			}
		} catch (\Throwable) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('crm_workspace')
			->where($qb->expr()->eq('is_default', $qb->createNamedParameter(1)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();

		return $id > 0 ? $id : 0;
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
