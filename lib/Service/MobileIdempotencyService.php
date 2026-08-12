<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\MobileIdempotencyMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DbTransaction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Companion — optional Idempotency-Key for mobile POSTs.
 *
 * Keys are client-namespaced (e.g. company:…, person:…, deal:…, activity:…).
 * The ledger column `activity_id` stores the created resource id for any kind.
 */
class MobileIdempotencyService
{
	public const MAX_KEY_LENGTH = 128;

	public function __construct(
		private readonly MobileIdempotencyMapper $mapper,
		private readonly ActivityService $activities,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Normalise a client key, or null when absent. Throws on malformed keys.
	 *
	 * @throws ValidationException
	 */
	public function normaliseKey(?string $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		$key = trim($raw);
		if ($key === '') {
			return null;
		}
		if (strlen($key) > self::MAX_KEY_LENGTH) {
			throw ValidationException::field(
				'VALIDATION',
				'idempotencyKey',
				$this->l10n->t('Idempotency key is too long (max %d characters).', [self::MAX_KEY_LENGTH]),
			);
		}
		if (preg_match('/^[A-Za-z0-9:_.-]+$/', $key) !== 1) {
			throw ValidationException::field(
				'VALIDATION',
				'idempotencyKey',
				$this->l10n->t('Idempotency key uses unsupported characters.'),
			);
		}

		return $key;
	}

	/**
	 * @param array<string, mixed> $body
	 * @throws ValidationException
	 */
	public function createActivity(string $uid, array $body, ?string $rawKey): Activity
	{
		return $this->once(
			$uid,
			$rawKey,
			fn (): Activity => $this->activities->create($body, $uid),
			static fn (Activity $a): int => (int)$a->getId(),
			fn (int $id): Activity => $this->activities->get($id),
		);
	}

	/**
	 * Peek ledger for a prior create (no write). Used to skip expensive side-effects
	 * (e.g. Files upload) before entering once().
	 */
	public function findResourceId(string $uid, ?string $rawKey): ?int
	{
		$key = $this->normaliseKey($rawKey);
		if ($key === null) {
			return null;
		}

		return $this->mapper->findActivityId($uid, $key);
	}

	/**
	 * Run a create at most once per (uid, idempotency key).
	 *
	 * Nested services MUST use DbTransaction (not raw begin/commit) so they do not
	 * end this unit of work. Side-effects that must not fire before the ledger
	 * commits (push notify) must be deferred by the factory and flushed by the
	 * caller after once() returns successfully.
	 *
	 * @template T
	 * @param callable(): T $factory
	 * @param callable(T): int $idOf
	 * @param callable(int): T $load
	 * @return T
	 */
	public function once(string $uid, ?string $rawKey, callable $factory, callable $idOf, callable $load): mixed
	{
		$key = $this->normaliseKey($rawKey);
		if ($key === null) {
			return $factory();
		}

		$existingId = $this->mapper->findActivityId($uid, $key);
		if ($existingId !== null) {
			return $load($existingId);
		}

		// Own the ledger TX; nested services must use DbTransaction (not raw begin)
		// so they do not commit/rollback this unit of work (person create / sequences).
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			$raced = $this->mapper->findActivityId($uid, $key);
			if ($raced !== null) {
				DbTransaction::commit($this->db, $ownsTxn);

				return $load($raced);
			}

			$created = $factory();
			$this->mapper->insert(
				$uid,
				$key,
				$idOf($created),
				$this->timeFactory->getTime(),
			);
			DbTransaction::commit($this->db, $ownsTxn);

			return $created;
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			if ($this->isUniqueConstraintViolation($e)) {
				$winner = $this->mapper->findActivityId($uid, $key);
				if ($winner !== null) {
					return $load($winner);
				}
			}
			throw $e;
		}
	}

	private function isUniqueConstraintViolation(\Throwable $e): bool
	{
		if ($e instanceof UniqueConstraintViolationException) {
			return true;
		}
		$previous = $e->getPrevious();

		return $previous instanceof \Throwable && $this->isUniqueConstraintViolation($previous);
	}
}
