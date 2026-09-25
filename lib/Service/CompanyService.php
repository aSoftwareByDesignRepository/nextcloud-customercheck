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
use OCA\CustomerCheck\Support\DbTransaction;
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
		private readonly ?EditConflictService $editConflicts = null,
		private readonly ?EntityRevisionService $entityRevisions = null,
		private readonly ?ValueMapService $valueMaps = null,
		private readonly ?NotificationService $notifications = null,
		private readonly ?WorkspaceService $workspaces = null,
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
	 * Case-insensitive name keys that appear more than once among active companies
	 * in a workspace (companies list duplicate banner / Merge into…).
	 *
	 * @return array<string, true>
	 */
	public function activeDuplicateNameKeys(int $workspaceId): array
	{
		if ($workspaceId <= 0) {
			return [];
		}

		return $this->companyMapper->activeDuplicateNameKeys($workspaceId);
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
	public function create(array $input, string $actor, bool $canReassignOwners = false): Company
	{
		if (!$canReassignOwners) {
			// Without the reassign capability the owner is always the actor —
			// same strip the mobile create path applies (§13.5).
			unset($input['accountOwnerUserId']);
		}
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
		$createdOwner = trim((string)($company->getAccountOwnerUserId() ?? ''));
		if ($createdOwner !== '' && $createdOwner !== $actor && $this->workspaces !== null) {
			$this->workspaces->assertOwnerCandidate($wsId, $createdOwner, 'accountOwnerUserId');
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
		bool $skipOptimistic = false,
	): Company
	{
		// Serialize vs archive/restore/move/erase — stamp alone last-write-wins.
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			if (!$this->companyMapper->lockRow($id)) {
				throw new NotFoundException(
					$this->l10n->t('This company no longer exists.'),
					Constants::ERR_COMPANY_NOT_FOUND,
				);
			}
			$company = $this->companyMapper->findById($id);
			$currentSnap = $this->companyConflictSnapshot($company);
			// User-facing updates always require updatedAt; conflict apply sets skipOptimistic (GNQ §6.4).
			$requirePrecondition = !$skipOptimistic;
			try {
				OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition, $requirePrecondition);
			} catch (ConflictException $stale) {
				if (!$requirePrecondition || $this->editConflicts === null
					|| $updatedAtPrecondition === null || trim($updatedAtPrecondition) === '') {
					throw $stale;
				}
				// Fail closed: no recorded base revision at the precondition stamp
				// means we cannot prove which fields the other writer touched —
				// never merge against current (that is silent last-write-wins).
				$baseSnap = $this->entityRevisions?->snapshotAt('company', $id, (string)$updatedAtPrecondition);
				if ($baseSnap === null) {
					throw $stale;
				}
				$incoming = $this->companyIncomingPatch($input);
				if ($incoming === []) {
					throw $stale;
				}
				$wsId = (int)($company->getWorkspaceId() ?? 0);
				$classified = ConflictFieldLogic::classifyPatch($baseSnap, $currentSnap, $incoming);
				if ($classified['conflicts'] !== []) {
					// Persist conflict outside this aborted update txn (GNQ §6.3).
					DbTransaction::rollBack($this->db, $ownsTxn);
					if (!$ownsTxn) {
						throw $stale;
					}
					$conflictId = $this->editConflicts->recordOpenConflict(
						$wsId,
						'company',
						$id,
						$baseSnap,
						$currentSnap,
						$incoming,
						$classified['conflicts'],
						(string)($baseSnap['_actor'] ?? 'unknown'),
						$actor,
					);
					throw new ConflictException(
						$this->l10n->t('Parallel edits need a resolution with a reason.'),
						Constants::ERR_EDIT_CONFLICT,
						['conflictId' => $conflictId, 'fields' => $classified['conflicts']],
					);
				}
				// Non-overlapping: apply only takeIncoming fields.
				$filtered = [];
				foreach ($classified['takeIncoming'] as $field) {
					if (array_key_exists($field, $incoming)) {
						$filtered[$this->companyApiFieldToInput($field)] = $incoming[$field];
					}
				}
				if ($filtered === []) {
					DbTransaction::commit($this->db, $ownsTxn);

					return $company;
				}
				$input = $filtered;
			}

			$before = [
				'name' => (string)$company->getName(),
				'status' => (string)$company->getStatus(),
				'slug' => (string)$company->getSlug(),
			];

			if (array_key_exists('accountOwnerUserId', $input)) {
				$newOwner = trim((string)($input['accountOwnerUserId'] ?? ''));
				$current = trim((string)($company->getAccountOwnerUserId() ?? ''));
				if ($newOwner !== $current) {
					$mayReassign = $canReassignOwners
						|| $current === ''
						|| $current === $actor;
					if (!$mayReassign) {
						throw ValidationException::field(
							Constants::ERR_DEAL_OWNED,
							'accountOwnerUserId',
							$this->l10n->t('This account already has an owner. Ask them or a manager to reassign it.'),
						);
					}
					if ($newOwner !== '') {
						$this->workspaces?->assertOwnerCandidate(
							(int)($company->getWorkspaceId() ?? 0),
							$newOwner,
							'accountOwnerUserId',
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

			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		$this->entityRevisions?->append(
			(int)($stored->getWorkspaceId() ?? 0),
			'company',
			(int)$stored->getId(),
			$stored->getUpdatedAt() ?? $this->timeFactory->getDateTime(),
			$actor,
			$this->companyConflictSnapshot($stored) + ['_actor' => $actor],
		);

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
		if (!Constants::MULTI_WORKSPACE_ENABLED) {
			throw ValidationException::field(
				Constants::ERR_WS_LIMIT,
				'workspaceId',
				$this->l10n->t('CustomerCheck uses a single organisation. Companies cannot be moved between workspaces.'),
			);
		}
		$company = $this->get($id);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

		$slug = (string)$company->getSlug();
		if (!hash_equals($slug, trim($confirmSlug))) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'confirmSlug',
				$this->l10n->t('Type the short address "%s" exactly to confirm the move.', [$slug]),
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
			// Deal checklist items denormalise workspace_id for Today/mobile — must follow
			// the company or old-book members retain Done on a company that left (§3.3).
			$checklistRestamped = 0;
			if ($this->db->tableExists('crm_deal_cl_item')) {
				$qb = $this->db->getQueryBuilder();
				$checklistRestamped = $qb->update('crm_deal_cl_item')
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
			'checklistItemsRestamped' => $checklistRestamped,
		]);

		return [
			'company' => $stored,
			'fromWorkspaceId' => $fromWorkspaceId,
			'toWorkspaceId' => $toWorkspaceId,
			'mailUnmatched' => $mailUnmatched,
			'draftsDetached' => $draftsDetached,
			'promisesRestamped' => $promisesRestamped,
			'plansRestamped' => $plansRestamped,
			'escalationsRestamped' => $escalationsRestamped,
			'commitsRestamped' => $commitsRestamped,
			'checklistItemsRestamped' => $checklistRestamped,
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
					'notes' => $d->getNotes(),
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
		$checklistItemIds = [];
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
					$this->l10n->t('Type the short address "%s" exactly to confirm the deletion.', [$slug]),
				);
			}

			$agreementIds = $this->agreementMapper->findIdsByCompany($id);

			$dealIds = [];
			foreach ($this->dealMapper->findByCompany($id) as $deal) {
				$dealIds[] = (int)$deal->getId();
			}
			$personIds = [];
			foreach ($this->personMapper->findByCompany($id) as $person) {
				$personIds[] = (int)$person->getId();
			}
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$this->eraseAssignAndConflicts($wsId, $id, $dealIds, $personIds, $agreementIds);

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
			// Lock order: company (above) → checklist files/items → deals → …
			// Raw QB avoids circular DI with DealChecklistService (needs CompanyService).
			$checklistItemIds = $this->eraseDealChecklists($id);
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

		// Notifications are not part of the DB unit of work — withdraw only after a successful erase.
		if ($this->notifications !== null) {
			foreach ($checklistItemIds as $checklistItemId) {
				$this->notifications->withdraw('deal_checklist_item', (string)$checklistItemId);
			}
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
	 * Hard-delete deal checklist rows for GDPR erase (CUC-DEAL-CHECKLIST-1).
	 * Raw QB — DealChecklistService depends on this class.
	 */
	/**
	 * Hard-delete deal checklist rows for a company (GDPR erase).
	 *
	 * @return list<int> deleted checklist item ids (for notification withdraw after commit)
	 */
	private function eraseDealChecklists(int $companyId): array
	{
		if (!$this->db->tableExists('crm_deal_cl_item')) {
			return [];
		}
		$itemIds = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_deal_cl_item')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$itemIds[] = (int)$row['id'];
		}
		$result->closeCursor();
		if ($itemIds !== [] && $this->db->tableExists('crm_deal_cl_file')) {
			$delFiles = $this->db->getQueryBuilder();
			$delFiles->delete('crm_deal_cl_file')
				->where($delFiles->expr()->in('item_id', $delFiles->createNamedParameter($itemIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$delItems = $this->db->getQueryBuilder();
		$delItems->delete('crm_deal_cl_item')
			->where($delItems->expr()->eq('company_id', $delItems->createNamedParameter($companyId)))
			->executeStatement();

		return $itemIds;
	}

	/**
	 * Cancel open edit conflicts and scrub Assign PII for an erased company (GNQ §5 / §18.9).
	 * Uses raw QB to avoid circular DI with ReviewService.
	 *
	 * @param list<int> $dealIds
	 * @param list<int> $personIds
	 * @param list<int> $agreementIds
	 */
	private function eraseAssignAndConflicts(
		int $workspaceId,
		int $companyId,
		array $dealIds,
		array $personIds,
		array $agreementIds = [],
	): void {
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$erasedSnap = json_encode(['erased' => true, 'at' => $now], JSON_THROW_ON_ERROR);

		if ($this->db->tableExists('crm_edit_conflict')) {
			$this->cancelOpenConflicts('company', [$companyId], $now, $erasedSnap);
			if ($dealIds !== []) {
				$this->cancelOpenConflicts('deal', $dealIds, $now, $erasedSnap);
			}
			if ($personIds !== []) {
				$this->cancelOpenConflicts('person', $personIds, $now, $erasedSnap);
			}
			if ($agreementIds !== []) {
				$this->cancelOpenConflicts('agreement', $agreementIds, $now, $erasedSnap);
			}
		}

		if (!$this->db->tableExists('crm_review_item')) {
			return;
		}
		$emptyRef = json_encode(['erased' => true], JSON_THROW_ON_ERROR);
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_review_item')
			->set('subject', $qb->createNamedParameter('[erased]'))
			->set('snippet', $qb->createNamedParameter(''))
			->set('source_ref', $qb->createNamedParameter($emptyRef))
			->set('suggestion_json', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('resolution_note', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('deal_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('status', $qb->createNamedParameter('no_relation'))
			->set('assigned_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		if ($workspaceId > 0) {
			$qb->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		}
		$qb->executeStatement();

		// Open items that only *suggest* this company (not yet assigned) — drop that suggestion.
		if ($workspaceId > 0) {
			$this->scrubAssignSuggestionsForCompany($workspaceId, $companyId, $now);
		}
	}

	/**
	 * @param list<int> $entityIds
	 */
	private function cancelOpenConflicts(string $entityType, array $entityIds, string $now, string $erasedSnap): void
	{
		$entityIds = array_values(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0));
		if ($entityIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_edit_conflict')
			->set('status', $qb->createNamedParameter('cancelled'))
			->set('reason', $qb->createNamedParameter('gdpr_erase'))
			->set('resolved_by', $qb->createNamedParameter('system'))
			->set('resolved_at', $qb->createNamedParameter($now))
			->set('base_json', $qb->createNamedParameter($erasedSnap))
			->set('current_json', $qb->createNamedParameter($erasedSnap))
			->set('incoming_json', $qb->createNamedParameter($erasedSnap))
			->set('fields_json', $qb->createNamedParameter('[]'))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
			// PARAM_INT_ARRAY — never pass a list of createNamedParameter() tokens into in();
			// Doctrine treats bare names (ceid0) as columns → SQLSTATE 42S22 / HTTP 500.
			->andWhere($qb->expr()->in(
				'entity_id',
				$qb->createNamedParameter($entityIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->executeStatement();
	}

	/**
	 * Remove suggestion entries pointing at an erased company from still-open Assign rows.
	 */
	private function scrubAssignSuggestionsForCompany(int $workspaceId, int $companyId, string $now): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'suggestion_json')->from('crm_review_item')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				['open', 'follow_up', 'snoozed'],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($qb->expr()->isNull('company_id'))
			->setMaxResults(500);
		$r = $qb->executeQuery();
		while ($row = $r->fetch()) {
			$raw = $row['suggestion_json'] ?? null;
			if ($raw === null || $raw === '') {
				continue;
			}
			$decoded = json_decode((string)$raw, true);
			if (!is_array($decoded)) {
				continue;
			}
			$filtered = array_values(array_filter(
				$decoded,
				static fn ($s): bool => !is_array($s) || (int)($s['companyId'] ?? 0) !== $companyId,
			));
			if (count($filtered) === count($decoded)) {
				continue;
			}
			$upd = $this->db->getQueryBuilder();
			$upd->update('crm_review_item')
				->set(
					'suggestion_json',
					$filtered === []
						? $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
						: $upd->createNamedParameter(json_encode($filtered, JSON_THROW_ON_ERROR)),
				)
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$r->closeCursor();
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
			$mapStepsDeleted = $qb->delete('crm_map_step')
				->where($qb->expr()->in(
					'plan_id',
					$qb->createNamedParameter($planIds, IQueryBuilder::PARAM_INT_ARRAY),
				))
				->executeStatement();
		}

		// Milestones carry free-text titles — delete before the parent plan (§18.9).
		if ($planIds !== [] && $this->db->tableExists('crm_plan_ms')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('crm_plan_ms')
				->where($qb->expr()->in(
					'plan_id',
					$qb->createNamedParameter($planIds, IQueryBuilder::PARAM_INT_ARRAY),
				))
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
			$rawCountry = trim((string)$input['country']);
			$wsId = (int)($company->getWorkspaceId() ?? 0);
			$country = $this->valueMaps !== null && $wsId > 0
				? $this->valueMaps->resolve($wsId, 'country', $rawCountry)
				: ValueMapLogic::builtinCountryCanonical($rawCountry);
			if ($country === null || !preg_match('/^[A-Z]{2}$/', $country)) {
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
				$fields['workspaceId'] = $this->l10n->t('Companies stay in the single organisation. Workspace moves are not available.');
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

	/**
	 * Apply field choices from a resolved edit conflict (GNQ §6.4).
	 * Skips optimistic precondition — the conflict row is the authority.
	 *
	 * @param array<string, mixed> $appliedPatch snap-field => value
	 */
	public function applyConflictResolution(int $id, array $appliedPatch, string $actor, bool $canReassignOwners = false): Company
	{
		$input = [];
		foreach ($appliedPatch as $field => $value) {
			$input[$this->companyApiFieldToInput((string)$field)] = $value;
		}
		if ($input === []) {
			return $this->get($id);
		}

		return $this->update($id, $input, $actor, null, $canReassignOwners, true);
	}

	/**
	 * Protected company fields for GNQ conflict three-way (§6.2).
	 *
	 * @return array<string, mixed>
	 */
	private function companyConflictSnapshot(Company $company): array
	{
		return [
			'name' => (string)$company->getName(),
			'status' => (string)$company->getStatus(),
			'accountOwnerUserId' => method_exists($company, 'getAccountOwnerUserId')
				? (string)($company->getAccountOwnerUserId() ?? '')
				: '',
			'kamTier' => method_exists($company, 'getKamTier') ? (string)$company->getKamTier() : 'standard',
			'touchIntervalDays' => method_exists($company, 'getTouchIntervalDays')
				? (int)($company->getTouchIntervalDays() ?? 0)
				: 0,
			'nextTouchOn' => method_exists($company, 'getNextTouchOn') && $company->getNextTouchOn() !== null
				? $company->getNextTouchOn()->format('Y-m-d')
				: '',
			'filesFolderId' => method_exists($company, 'getFilesFolderId')
				? (int)($company->getFilesFolderId() ?? 0)
				: 0,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function companyIncomingPatch(array $input): array
	{
		$map = [
			'name' => 'name',
			'status' => 'status',
			'accountOwnerUserId' => 'accountOwnerUserId',
			'kamTier' => 'kamTier',
			'touchIntervalDays' => 'touchIntervalDays',
			'nextTouchOn' => 'nextTouchOn',
			'filesFolderId' => 'filesFolderId',
		];
		$out = [];
		foreach ($map as $api => $snap) {
			if (array_key_exists($api, $input)) {
				$out[$snap] = $input[$api];
			}
		}

		return $out;
	}

	private function companyApiFieldToInput(string $snapField): string
	{
		return $snapField;
	}
}
