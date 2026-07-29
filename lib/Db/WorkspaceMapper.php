<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Workspace>
 */
class WorkspaceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_workspace', Workspace::class);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): Workspace
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findBySlug(string $slug): Workspace
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));
		return $this->findEntity($qb);
	}

	public function findDefault(): ?Workspace
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_default', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return Workspace[] */
	public function findAll(int $limit = 100): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC')
			->setMaxResults(max(1, min(100, $limit)));
		return $this->findEntities($qb);
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/** @return Workspace[] */
	public function findForUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('w.*')
			->from($this->getTableName(), 'w')
			->innerJoin('w', 'crm_ws_member', 'm', $qb->expr()->eq('w.id', 'm.workspace_id'))
			->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)))
			->orderBy('w.name', 'ASC');
		return $this->findEntities($qb);
	}
}
