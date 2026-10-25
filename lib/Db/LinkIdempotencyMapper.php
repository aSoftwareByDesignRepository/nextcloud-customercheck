<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * createOrLink Idempotency-Key ledger (SHARED-IDENTITY MH-12).
 *
 * @phpstan-type LinkIdemRow array{companyId:int,siblingId:int,created:bool}
 */
class LinkIdempotencyMapper
{
	public const TTL_SECONDS = 86400;

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @return LinkIdemRow|null
	 */
	public function findFresh(string $uid, string $idemKey, string $sibling, int $now): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('crm_link_idem')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($idemKey)))
			->andWhere($qb->expr()->eq('sibling', $qb->createNamedParameter($sibling)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		$createdAt = (int)($row['created_at'] ?? 0);
		if ($createdAt + self::TTL_SECONDS < $now) {
			$this->deleteKey($uid, $idemKey, $sibling);

			return null;
		}

		return [
			'companyId' => (int)$row['company_id'],
			'siblingId' => (int)$row['sibling_id'],
			'created' => !empty($row['created']),
		];
	}

	public function insert(
		string $uid,
		string $idemKey,
		string $sibling,
		int $companyId,
		int $siblingId,
		bool $created,
		int $createdAt,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('crm_link_idem')
			->values([
				'uid' => $qb->createNamedParameter($uid),
				'idem_key' => $qb->createNamedParameter($idemKey),
				'sibling' => $qb->createNamedParameter($sibling),
				'company_id' => $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT),
				'sibling_id' => $qb->createNamedParameter($siblingId, IQueryBuilder::PARAM_INT),
				'created' => $qb->createNamedParameter($created, IQueryBuilder::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}

	public function deleteKey(string $uid, string $idemKey, string $sibling): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('crm_link_idem')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($idemKey)))
			->andWhere($qb->expr()->eq('sibling', $qb->createNamedParameter($sibling)));
		$qb->executeStatement();
	}

	public function purgeExpired(int $now): int
	{
		$cutoff = $now - self::TTL_SECONDS;
		$qb = $this->db->getQueryBuilder();
		$qb->delete('crm_link_idem')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
