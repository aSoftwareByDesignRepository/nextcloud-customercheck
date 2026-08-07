<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Workspace value-map learn — GNQ §7.
 */
class ValueMapService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	public function resolve(int $workspaceId, string $fieldKey, string $raw): ?string
	{
		if (!$this->db->tableExists('crm_value_map')) {
			return ValueMapLogic::builtinCountryCanonical($raw);
		}
		$norm = ValueMapLogic::normalizeRaw($raw);
		$qb = $this->db->getQueryBuilder();
		$qb->select('canonical_value')->from('crm_value_map')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_key', $qb->createNamedParameter($fieldKey)))
			->andWhere($qb->expr()->eq('raw_value_norm', $qb->createNamedParameter($norm)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row !== false) {
			return (string)$row['canonical_value'];
		}
		if ($fieldKey === 'country') {
			return ValueMapLogic::builtinCountryCanonical($raw);
		}

		return null;
	}

	/**
	 * @return array{canonical: string, learned: bool}
	 */
	public function apply(int $workspaceId, string $fieldKey, string $raw, string $canonical, bool $learn, string $actor): array
	{
		$canonical = strtoupper(trim($canonical));
		if ($fieldKey === 'country' && !ValueMapLogic::isValidIsoCountry($canonical)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'canonical',
				$this->l10n->t('Use a valid two-letter country code (ISO).'),
			);
		}
		if (!$learn) {
			return ['canonical' => $canonical, 'learned' => false];
		}
		if (!$this->db->tableExists('crm_value_map')) {
			return ['canonical' => $canonical, 'learned' => false];
		}
		$norm = ValueMapLogic::normalizeRaw($raw);
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$existing = $this->resolve($workspaceId, $fieldKey, $raw);
		if ($existing === $canonical) {
			// Still upsert in case builtin matched without a row.
		}
		$del = $this->db->getQueryBuilder();
		$del->delete('crm_value_map')
			->where($del->expr()->eq('workspace_id', $del->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($del->expr()->eq('field_key', $del->createNamedParameter($fieldKey)))
			->andWhere($del->expr()->eq('raw_value_norm', $del->createNamedParameter($norm)))
			->executeStatement();
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_value_map')->values([
			'workspace_id' => $ins->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'field_key' => $ins->createNamedParameter($fieldKey),
			'raw_value_norm' => $ins->createNamedParameter($norm),
			'canonical_value' => $ins->createNamedParameter($canonical),
			'created_by' => $ins->createNamedParameter($actor),
			'created_at' => $ins->createNamedParameter($now),
		])->executeStatement();

		return ['canonical' => $canonical, 'learned' => true];
	}

	/** @return list<array{id: int, fieldKey: string, raw: string, canonical: string}> */
	public function listForWorkspace(int $workspaceId): array
	{
		if (!$this->db->tableExists('crm_value_map')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_value_map')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('field_key', 'ASC')
			->addOrderBy('raw_value_norm', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'fieldKey' => (string)$row['field_key'],
				'raw' => (string)$row['raw_value_norm'],
				'canonical' => (string)$row['canonical_value'],
			];
		}
		$r->closeCursor();

		return $out;
	}

	public function delete(int $workspaceId, int $id): void
	{
		if (!$this->db->tableExists('crm_value_map')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('crm_value_map')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
