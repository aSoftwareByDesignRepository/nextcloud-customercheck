<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ChecklistTemplate>
 */
class ChecklistTemplateMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_cl_tmpl', ChecklistTemplate::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): ChecklistTemplate
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	public function findByWorkspaceAndKey(int $workspaceId, string $tmplKey): ?ChecklistTemplate
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tmpl_key', $qb->createNamedParameter($tmplKey)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * System templates (workspace_id=0) plus workspace-specific templates.
	 *
	 * @return ChecklistTemplate[]
	 */
	public function findAvailableForWorkspace(int $workspaceId, bool $enabledOnly = true): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('workspace_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)),
			));
		if ($enabledOnly) {
			$qb->andWhere($qb->expr()->eq('is_enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('is_system', 'DESC')
			->addOrderBy('name', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return ChecklistTemplate[]
	 */
	public function findByWorkspace(int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}
}
