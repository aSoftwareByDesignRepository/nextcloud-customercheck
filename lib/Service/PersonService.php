<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\CompanyScopedFk;
use OCA\CustomerCheck\Support\DbTransaction;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Contact persons — PSD §4.2.
 *
 * "At most one primary contact per company" cannot be expressed as a database
 * constraint portably (a partial unique index is not available on every
 * supported platform), so it is enforced transactionally: clearing the previous
 * primary and setting the new one happen inside a single transaction, which
 * makes a concurrent second promotion serialise behind the first instead of
 * leaving two primaries behind.
 */
class PersonService
{
	public function __construct(
		private readonly PersonMapper $personMapper,
		private readonly CompanyService $companyService,
		private readonly TimelineService $timelineService,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ?EditConflictService $editConflicts = null,
		private readonly ?EntityRevisionService $entityRevisions = null,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Person
	{
		try {
			return $this->personMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('This contact no longer exists.'));
		}
	}

	/** @return Person[] */
	public function listForCompany(int $companyId): array
	{
		return $this->personMapper->findByCompany($companyId);
	}

	public function findPrimary(int $companyId): ?Person
	{
		return $this->personMapper->findPrimary($companyId);
	}

	/**
	 * @param array{search?: string, companyId?: int} $filters
	 * @return Person[]
	 */
	public function search(array $filters = [], int $limit = 200, int $offset = 0): array
	{
		return $this->personMapper->search($filters, $limit, $offset);
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function create(int $companyId, array $input, string $actor): Person
	{
		// Presence check first so a contact can never dangle on a missing company.
		$this->companyService->get($companyId);

		$now = $this->timeFactory->getDateTime();
		$person = new Person();
		$person->setCompanyId($companyId);
		$person->setIsPrimary(0);
		$person->setCreatedBy($actor);
		$person->setCreatedAt($now);
		$person->setUpdatedAt($now);

		$this->applyInput($person, $input, true);
		$wantsPrimary = $this->readPrimaryFlag($input, false);

		// Buyer uniqueness + primary invariant under the same company FOR UPDATE
		// — closes concurrent second-economic-buyer race (§18.6).
		$stored = $this->persistWithPrimaryInvariant($person, $wantsPrimary, $companyId, true);

		$this->timelineService->append($companyId, 'company.updated', $actor, [
			'contactAdded' => (string)$stored->getName(),
			'primary' => $stored->isPrimaryContact(),
		]);

		return $stored;
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 * @throws NotFoundException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function update(int $id, array $input, string $actor, ?string $updatedAtPrecondition, bool $skipOptimistic = false): Person
	{
		// User-facing updates always require updatedAt; conflict apply sets skipOptimistic (GNQ §6.4).
		$requirePrecondition = !$skipOptimistic;
		// Fail fast on missing precondition before taking locks.
		OptimisticLock::assertFresh(null, $updatedAtPrecondition, $requirePrecondition);

		$ownsTxn = DbTransaction::begin($this->db);
		try {
			$person = $this->get($id);
			$companyId = (int)$person->getCompanyId();
			$this->companyService->lockRow($companyId);

			// Re-load under company lock and re-check — closes TOCTOU vs concurrent writers.
			$person = $this->get($id);
			$currentSnap = $this->personConflictSnapshot($person);
			try {
				OptimisticLock::assertFresh($person->getUpdatedAt(), $updatedAtPrecondition, $requirePrecondition);
			} catch (\OCA\CustomerCheck\Exception\ConflictException $stale) {
				if (!$requirePrecondition || $this->editConflicts === null) {
					throw $stale;
				}
				$baseSnap = $this->entityRevisions?->snapshotAt('person', $id, $updatedAtPrecondition) ?? $currentSnap;
				$incoming = $this->personIncomingPatch($input);
				if ($incoming === []) {
					throw $stale;
				}
				$company = $this->companyService->get($companyId);
				$wsId = (int)($company->getWorkspaceId() ?? 0);
				$classified = ConflictFieldLogic::classifyPatch($baseSnap, $currentSnap, $incoming);
				if ($classified['conflicts'] !== []) {
					DbTransaction::rollBack($this->db, $ownsTxn);
					if (!$ownsTxn) {
						throw $stale;
					}
					$conflictId = $this->editConflicts->recordOpenConflict(
						$wsId,
						'person',
						$id,
						$baseSnap,
						$currentSnap,
						$incoming,
						$classified['conflicts'],
						(string)($baseSnap['_actor'] ?? 'unknown'),
						$actor,
					);
					throw new \OCA\CustomerCheck\Exception\ConflictException(
						$this->l10n->t('Parallel edits need a resolution with a reason.'),
						Constants::ERR_EDIT_CONFLICT,
						['conflictId' => $conflictId, 'fields' => $classified['conflicts']],
					);
				}
				$filtered = [];
				foreach ($classified['takeIncoming'] as $field) {
					if (array_key_exists($field, $input)) {
						$filtered[$field] = $input[$field];
					}
				}
				foreach ($input as $k => $v) {
					if (!array_key_exists($k, $incoming)) {
						$filtered[$k] = $v;
					}
				}
				if ($filtered === []) {
					DbTransaction::commit($this->db, $ownsTxn);

					return $person;
				}
				$input = $filtered;
			}

			$this->applyInput($person, $input, false);
			$this->assertSingleEconomicBuyer($companyId, $person->getBuyingRole(), (int)$person->getId());
			$person->setUpdatedAt($this->timeFactory->getDateTime());

			$wantsPrimary = $this->readPrimaryFlag($input, $person->isPrimaryContact());
			if ($wantsPrimary) {
				$this->personMapper->clearPrimaryFor($companyId, null);
			}
			$person->setIsPrimary($wantsPrimary ? 1 : 0);
			$stored = $this->personMapper->update($person);

			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		if ($this->entityRevisions !== null) {
			$company = $this->companyService->get((int)$stored->getCompanyId());
			$this->entityRevisions->append(
				(int)($company->getWorkspaceId() ?? 0),
				'person',
				(int)$stored->getId(),
				$stored->getUpdatedAt() ?? $this->timeFactory->getDateTime(),
				$actor,
				$this->personConflictSnapshot($stored) + ['_actor' => $actor],
			);
		}

		$this->timelineService->append((int)$stored->getCompanyId(), 'company.updated', $actor, [
			'contactUpdated' => (string)$stored->getName(),
			'primary' => $stored->isPrimaryContact(),
		]);

		return $stored;
	}

	/**
	 * @param array<string, mixed> $appliedPatch
	 */
	public function applyConflictResolution(int $id, array $appliedPatch, string $actor): Person
	{
		if ($appliedPatch === []) {
			return $this->get($id);
		}

		return $this->update($id, $appliedPatch, $actor, null, true);
	}

	/** @return array<string, mixed> */
	private function personConflictSnapshot(Person $person): array
	{
		return [
			'name' => (string)$person->getName(),
			'email' => (string)($person->getEmail() ?? ''),
			'buyingRole' => (string)($person->getBuyingRole() ?? ''),
			'status' => (string)$person->getStatus(),
			'isPrimary' => $person->isPrimaryContact() ? 1 : 0,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function personIncomingPatch(array $input): array
	{
		$out = [];
		foreach (['name', 'email', 'buyingRole', 'status', 'isPrimary'] as $field) {
			if (array_key_exists($field, $input)) {
				$out[$field] = $input[$field];
			}
		}

		return $out;
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function delete(int $id, string $actor, ?string $updatedAtPrecondition = null): void
	{
		// Fail fast on missing precondition before taking locks (parity with update).
		OptimisticLock::assertFresh(null, $updatedAtPrecondition);

		$companyId = 0;
		$name = '';

		$this->db->beginTransaction();
		try {
			$person = $this->get($id);
			$companyId = (int)$person->getCompanyId();
			$this->companyService->lockRow($companyId);

			// Re-load under company lock — closes TOCTOU vs concurrent update/primary swap.
			$person = $this->get($id);
			OptimisticLock::assertFresh($person->getUpdatedAt(), $updatedAtPrecondition);
			$companyId = (int)$person->getCompanyId();
			$name = (string)$person->getName();

			$this->personMapper->delete($person);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->timelineService->append($companyId, 'company.updated', $actor, [
			'contactRemoved' => $name,
		]);
	}

	/**
	 * Insert or update while keeping "at most one primary" true.
	 *
	 * Protocol (deadlock-safe, race-safe under concurrent promotions):
	 *   1. BEGIN
	 *   2. SELECT company FOR UPDATE  (lock order §4.8: company → person)
	 *   3. If promoting: clear every primary for the company
	 *   4. Persist this person with the desired primary flag
	 *   5. COMMIT
	 *
	 * Without the company lock, two concurrent inserts can both observe "no
	 * other primary yet" (the peer row is uncommitted) and leave two primaries
	 * after both commit — failing PSD §11 S1.
	 */
	private function persistWithPrimaryInvariant(
		Person $person,
		bool $wantsPrimary,
		int $companyId,
		bool $assertBuyerOnCreate = false,
	): Person {
		$isInsert = $person->getId() === null;

		$this->db->beginTransaction();
		try {
			$this->companyService->lockRow($companyId);

			if ($assertBuyerOnCreate) {
				$this->assertSingleEconomicBuyer($companyId, $person->getBuyingRole(), null);
			}

			if ($wantsPrimary) {
				// Clear *all* primaries first so a concurrent peer cannot sneak
				// a second primary past an "except me" window.
				$this->personMapper->clearPrimaryFor($companyId, null);
			}

			$person->setIsPrimary($wantsPrimary ? 1 : 0);
			if ($isInsert) {
				$person = $this->personMapper->insert($person);
			} else {
				$person = $this->personMapper->update($person);
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $person;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function readPrimaryFlag(array $input, bool $current): bool
	{
		if (!array_key_exists('isPrimary', $input)) {
			return $current;
		}

		$value = $input['isPrimary'];
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value === 1;
		}

		return in_array(mb_strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 */
	private function applyInput(Person $person, array $input, bool $isCreate): void
	{
		$fields = [];

		if ($isCreate || array_key_exists('name', $input)) {
			$name = trim((string)($input['name'] ?? ''));
			if ($name === '') {
				$fields['name'] = $this->l10n->t('Enter the contact name.');
			} elseif (mb_strlen($name) > 255) {
				$fields['name'] = $this->l10n->t('Keep the contact name under 256 characters.');
			} else {
				$person->setName($name);
			}
		}

		if (array_key_exists('email', $input)) {
			$email = trim((string)($input['email'] ?? ''));
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				$fields['email'] = $this->l10n->t('Enter a valid email address, for example name@example.com.');
			} elseif (mb_strlen($email) > 255) {
				$fields['email'] = $this->l10n->t('Keep the email address under 256 characters.');
			} else {
				$person->setEmail($email === '' ? null : mb_strtolower($email));
			}
		}

		if (array_key_exists('role', $input)) {
			$role = trim((string)($input['role'] ?? ''));
			if (mb_strlen($role) > 128) {
				$fields['role'] = $this->l10n->t('Keep the role under 129 characters.');
			} else {
				$person->setRole($role === '' ? null : $role);
			}
		}

		if (array_key_exists('phone', $input)) {
			$phone = trim((string)($input['phone'] ?? ''));
			if ($phone !== '' && !preg_match('/^[0-9+()\/.\s-]{4,64}$/', $phone)) {
				$fields['phone'] = $this->l10n->t('Enter a phone number using digits, spaces and + ( ) / - only.');
			} else {
				$person->setPhone($phone === '' ? null : $phone);
			}
		}

		if (array_key_exists('notes', $input)) {
			$notes = trim((string)($input['notes'] ?? ''));
			if (mb_strlen($notes) > 4000) {
				$fields['notes'] = $this->l10n->t('Keep the notes under 4000 characters.');
			} else {
				$person->setNotes($notes === '' ? null : $notes);
			}
		}

		if (array_key_exists('contactsUid', $input)) {
			try {
				$validator = new ContactsUidValidator();
				$person->setContactsUid($validator->normalize(
					is_string($input['contactsUid']) || $input['contactsUid'] === null
						? ($input['contactsUid'] === null ? null : (string)$input['contactsUid'])
						: (string)$input['contactsUid'],
					$this->l10n,
				));
			} catch (ValidationException $e) {
				$fields['contactsUid'] = $e->getMessage();
			}
		}

		if (array_key_exists('buyingRole', $input)) {
			$br = trim((string)($input['buyingRole'] ?? ''));
			if ($br === '') {
				$person->setBuyingRole(null);
			} elseif (!in_array($br, Constants::BUYING_ROLES, true)) {
				$fields['buyingRole'] = $this->l10n->t('Unknown buying role.');
			} else {
				$person->setBuyingRole($br);
			}
		}

		if (array_key_exists('status', $input)) {
			$st = trim((string)($input['status'] ?? 'active'));
			if (!in_array($st, Constants::PERSON_STATUSES, true)) {
				$fields['status'] = $this->l10n->t('Choose active, left or unknown.');
			} else {
				$person->setStatus($st);
			}
		}

		if (array_key_exists('reportsToPersonId', $input)) {
			$rid = (int)($input['reportsToPersonId'] ?? 0);
			if ($rid <= 0) {
				$person->setReportsToPersonId(null);
			} else {
				$selfId = (int)($person->getId() ?? 0);
				if ($selfId > 0 && $rid === $selfId) {
					$fields['reportsToPersonId'] = $this->l10n->t('A contact cannot report to themselves.');
				} else {
					try {
						$manager = $this->personMapper->findById($rid);
					} catch (DoesNotExistException) {
						$fields['reportsToPersonId'] = $this->l10n->t('Pick a manager on this company.');
						$manager = null;
					}
					if ($manager !== null) {
						try {
							CompanyScopedFk::assertSameCompany(
								(int)$person->getCompanyId(),
								(int)$manager->getCompanyId(),
								'reportsToPersonId',
								$this->l10n,
								$this->l10n->t('Pick a manager on this company.'),
							);
							$person->setReportsToPersonId($rid);
						} catch (ValidationException $e) {
							$fields['reportsToPersonId'] = $e->getMessage();
						}
					}
				}
			}
		}

		if ((string)$person->getName() === '' && !isset($fields['name'])) {
			$fields['name'] = $this->l10n->t('Enter the contact name.');
		}

		if ($fields !== []) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Please correct the highlighted fields.'),
				$fields,
			);
		}
	}

	private function assertSingleEconomicBuyer(int $companyId, ?string $buyingRole, ?int $excludePersonId): void
	{
		if ($buyingRole !== 'economic_buyer') {
			return;
		}
		foreach ($this->personMapper->findByCompany($companyId) as $p) {
			if ($excludePersonId !== null && (int)$p->getId() === $excludePersonId) {
				continue;
			}
			if ((string)($p->getBuyingRole() ?? '') === 'economic_buyer'
				&& (string)($p->getStatus() ?? 'active') === 'active') {
				throw ValidationException::field(
					Constants::ERR_BUYER_DUP,
					'buyingRole',
					$this->l10n->t('This company already has an active budget decision-maker.'),
				);
			}
		}
	}
}
