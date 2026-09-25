<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<WorkspaceMember>
 */
class WorkspaceMemberMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_ws_member', WorkspaceMember::class);
	}

	/** @throws DoesNotExistException */
	public function findMembership(int $workspaceId, string $userId): WorkspaceMember
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	public function findMembershipOrNull(int $workspaceId, string $userId): ?WorkspaceMember
	{
		try {
			return $this->findMembership($workspaceId, $userId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return WorkspaceMember[] */
	public function findByWorkspace(int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('user_id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countByWorkspace(int $workspaceId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	public function deleteByWorkspaceAndUser(int $workspaceId, string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Drop every membership row for a uid — used when the account itself is
	 * deleted so stale member rows can never satisfy a membership check for a
	 * nonexistent user (ghost-uid mass-assignment vector).
	 */
	public function deleteByUser(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
