<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Companion P2 — Expo push token registry (per seated CRM user).
 */
class PushTokenMapper
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @return list<array{token: string, platform: string}>
	 */
	public function findTokensForUser(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('token', 'platform')
			->from('crm_push_tok')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'token' => (string)$row['token'],
				'platform' => (string)$row['platform'],
			];
		}
		$result->closeCursor();

		return $out;
	}

	/**
	 * @param list<string> $uids
	 * @return list<array{uid: string, token: string, platform: string}>
	 */
	public function findTokensForUsers(array $uids): array
	{
		$uids = array_values(array_unique(array_filter($uids, static fn (string $u): bool => $u !== '')));
		if ($uids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'token', 'platform')
			->from('crm_push_tok')
			->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'uid' => (string)$row['uid'],
				'token' => (string)$row['token'],
				'platform' => (string)$row['platform'],
			];
		}
		$result->closeCursor();

		return $out;
	}

	public function upsert(string $uid, string $tokenHash, string $token, string $platform, int $updatedAt): void
	{
		$existing = $this->findId($uid, $tokenHash);
		$qb = $this->db->getQueryBuilder();
		if ($existing !== null) {
			$qb->update('crm_push_tok')
				->set('token', $qb->createNamedParameter($token))
				->set('platform', $qb->createNamedParameter($platform))
				->set('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($existing, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			return;
		}
		$qb->insert('crm_push_tok')
			->values([
				'uid' => $qb->createNamedParameter($uid),
				'token_hash' => $qb->createNamedParameter($tokenHash),
				'token' => $qb->createNamedParameter($token),
				'platform' => $qb->createNamedParameter($platform),
				'updated_at' => $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}

	public function deleteByHash(string $uid, string $tokenHash): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('crm_push_tok')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)));

		return $qb->executeStatement();
	}

	public function deleteAllForUser(string $uid): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('crm_push_tok')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		return $qb->executeStatement();
	}

	private function findId(string $uid, string $tokenHash): ?int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('crm_push_tok')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : (int)$row['id'];
	}
}
