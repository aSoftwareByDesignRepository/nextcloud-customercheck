<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DealChecklistItem>
 */
class DealChecklistItemMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_deal_cl_item', DealChecklistItem::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): DealChecklistItem
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	public function lockRow(int $id): bool
	{
		if ($id <= 0) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
	}

	/**
	 * @return DealChecklistItem[]
	 */
	public function findByDeal(int $dealId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function countByDeal(int $dealId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/** Open items only — soft-cap must not brick deals with Done history. */
	public function countOpenByDeal(int $dealId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DealChecklistItem::STATUS_OPEN)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function maxSortOrder(int $dealId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order', 'mx'))
			->from($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return $max === false || $max === null ? -1 : (int)$max;
	}

	/**
	 * Open items assigned to any of the given user ids in a workspace.
	 *
	 * @param list<string> $assigneeIds
	 * @return DealChecklistItem[]
	 */
	public function findOpenAssigned(int $workspaceId, array $assigneeIds, int $limit = 100): array
	{
		$assigneeIds = array_values(array_filter(array_map('strval', $assigneeIds), static fn (string $u): bool => $u !== ''));
		if ($assigneeIds === [] || $workspaceId <= 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DealChecklistItem::STATUS_OPEN)))
			->andWhere($qb->expr()->in('assignee_user_id', $qb->createNamedParameter($assigneeIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('due_on', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(500, $limit)));

		return $this->findEntities($qb);
	}

	public function cancelOpenForDeal(int $dealId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(DealChecklistItem::STATUS_CANCELLED))
			->set('updated_at', $qb->createNamedParameter((new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DealChecklistItem::STATUS_OPEN)));

		return $qb->executeStatement();
	}

	public function deleteByDeal(int $dealId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Hard-delete open rows only — preserves done/cancelled history (template replace). */
	public function deleteOpenByDeal(int $dealId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DealChecklistItem::STATUS_OPEN)));

		return $qb->executeStatement();
	}

	public function deleteByCompany(int $companyId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
