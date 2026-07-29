<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\MobileIdempotencyMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Companion P2 — optional Idempotency-Key for POST /api/mobile/v1/activities.
 *
 * Replay of a lost 201 must return the same activity (AC12). Concurrent creates
 * with the same key are resolved via the unique (uid, idem_key) index inside a
 * transaction so a loser never leaves an orphan CRM row.
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
		$key = $this->normaliseKey($rawKey);
		if ($key === null) {
			return $this->activities->create($body, $uid);
		}

		$existingId = $this->mapper->findActivityId($uid, $key);
		if ($existingId !== null) {
			return $this->activities->get($existingId);
		}

		$this->db->beginTransaction();
		try {
			$raced = $this->mapper->findActivityId($uid, $key);
			if ($raced !== null) {
				$this->db->commit();

				return $this->activities->get($raced);
			}

			$activity = $this->activities->create($body, $uid);
			$this->mapper->insert(
				$uid,
				$key,
				(int)$activity->getId(),
				$this->timeFactory->getTime(),
			);
			$this->db->commit();

			return $activity;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			if ($this->isUniqueConstraintViolation($e)) {
				$winner = $this->mapper->findActivityId($uid, $key);
				if ($winner !== null) {
					return $this->activities->get($winner);
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
