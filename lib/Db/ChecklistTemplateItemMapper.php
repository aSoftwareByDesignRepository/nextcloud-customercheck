<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ChecklistTemplateItem>
 */
class ChecklistTemplateItemMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_cl_tmpl_item', ChecklistTemplateItem::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): ChecklistTemplateItem
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @return ChecklistTemplateItem[]
	 */
	public function findByTemplate(int $templateId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function deleteByTemplate(int $templateId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
