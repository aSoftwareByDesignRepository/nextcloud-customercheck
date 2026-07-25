<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
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

		$stored = $this->persistWithPrimaryInvariant($person, $wantsPrimary, $companyId);

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
	public function update(int $id, array $input, string $actor, ?string $updatedAtPrecondition): Person
	{
		$person = $this->get($id);
		OptimisticLock::assertFresh($person->getUpdatedAt(), $updatedAtPrecondition);

		$this->applyInput($person, $input, false);
		$person->setUpdatedAt($this->timeFactory->getDateTime());

		$wantsPrimary = $this->readPrimaryFlag($input, $person->isPrimaryContact());
		$stored = $this->persistWithPrimaryInvariant($person, $wantsPrimary, (int)$person->getCompanyId());

		$this->timelineService->append((int)$person->getCompanyId(), 'company.updated', $actor, [
			'contactUpdated' => (string)$stored->getName(),
			'primary' => $stored->isPrimaryContact(),
		]);

		return $stored;
	}

	/**
	 * @throws NotFoundException
	 */
	public function delete(int $id, string $actor): void
	{
		$person = $this->get($id);
		$companyId = (int)$person->getCompanyId();
		$name = (string)$person->getName();

		$this->personMapper->delete($person);

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
	private function persistWithPrimaryInvariant(Person $person, bool $wantsPrimary, int $companyId): Person
	{
		$isInsert = $person->getId() === null;

		$this->db->beginTransaction();
		try {
			$this->companyService->lockRow($companyId);

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
}
