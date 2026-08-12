<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DealChecklistFile>
 */
class DealChecklistFileMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_deal_cl_file', DealChecklistFile::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): DealChecklistFile
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @return DealChecklistFile[]
	 */
	public function findByItem(int $itemId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $itemIds
	 * @return array<int, list<DealChecklistFile>>
	 */
	public function findGroupedByItemIds(array $itemIds): array
	{
		$itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));
		if ($itemIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('item_id', $qb->createNamedParameter($itemIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');
		$files = $this->findEntities($qb);
		$out = [];
		foreach ($files as $file) {
			$iid = (int)$file->getItemId();
			$out[$iid] ??= [];
			$out[$iid][] = $file;
		}

		return $out;
	}

	public function deleteByItem(int $itemId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteByItemIds(array $itemIds): void
	{
		$itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));
		if ($itemIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('item_id', $qb->createNamedParameter($itemIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
