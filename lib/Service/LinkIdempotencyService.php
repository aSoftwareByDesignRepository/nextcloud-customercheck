<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\LinkIdempotencyMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;

/**
 * HTTP Idempotency-Key for createOrLink (SHARED-IDENTITY MH-12 / AC-C-14 / §9.3b).
 *
 * createOrLink HTTP endpoints require a key (header or body). Replay returns the
 * same sibling id without a second facade create.
 */
class LinkIdempotencyService
{
	public const MAX_KEY_LENGTH = 128;
	public const SIBLING_PC = 'pc';
	public const SIBLING_TC = 'tc';

	public function __construct(
		private readonly LinkIdempotencyMapper $mapper,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Read Idempotency-Key from header or body (nullable — use requireKey for HTTP createOrLink).
	 *
	 * @param array<string, mixed> $body
	 */
	public function resolveKey(IRequest $request, array $body): ?string
	{
		$header = $request->getHeader('Idempotency-Key');
		if (is_string($header) && trim($header) !== '') {
			return $this->normaliseKey($header);
		}
		if (isset($body['idempotencyKey']) && is_string($body['idempotencyKey'])) {
			return $this->normaliseKey($body['idempotencyKey']);
		}

		return null;
	}

	/**
	 * Spec §9.3b / NN-12: Idempotency-Key is required on createOrLink HTTP.
	 *
	 * @param array<string, mixed> $body
	 * @throws ValidationException
	 */
	public function requireKey(IRequest $request, array $body): string
	{
		$key = $this->resolveKey($request, $body);
		if ($key === null || $key === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'idempotencyKey',
				$this->l10n->t('Idempotency-Key header (or idempotencyKey) is required for create-or-link.'),
			);
		}

		return $key;
	}

	/**
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
				Constants::ERR_VALIDATION,
				'idempotencyKey',
				$this->l10n->t('Idempotency key is too long (max %d characters).', [self::MAX_KEY_LENGTH]),
			);
		}
		if (preg_match('/^[A-Za-z0-9:_.-]+$/', $key) !== 1) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'idempotencyKey',
				$this->l10n->t('Idempotency key uses unsupported characters.'),
			);
		}

		return $key;
	}

	/**
	 * @return array{companyId:int,siblingId:int,created:bool}|null
	 */
	public function findReplay(string $uid, string $key, string $sibling): ?array
	{
		return $this->mapper->findFresh($uid, $key, $sibling, $this->timeFactory->getTime());
	}

	public function remember(
		string $uid,
		string $key,
		string $sibling,
		int $companyId,
		int $siblingId,
		bool $created,
	): void {
		try {
			$this->mapper->insert(
				$uid,
				$key,
				$sibling,
				$companyId,
				$siblingId,
				$created,
				$this->timeFactory->getTime(),
			);
		} catch (\Throwable $e) {
			if (!$this->isUniqueConstraintViolation($e)) {
				throw $e;
			}
			// Parallel winner already stored the same key — treat as success.
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
