<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCA\CustomerCheck\Constants;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Agreement>
 */
class AgreementMapper extends QBMapper
{
	use ExcludesArchivedCompaniesTrait;

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_agreement', Agreement::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Agreement
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Write-lock the agreement row (CORE §6.3 / PSD §4.8).
	 *
	 * Callers MUST already be inside a DB transaction. Serialises concurrent
	 * supersede/cancel so two writers cannot both observe "active" and create
	 * two successors (or cancel after a supersede already closed the row).
	 */
	public function lockRow(int $id): bool
	{
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

	/** @return Agreement[] */
	public function findByCompany(int $companyId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->orderBy('valid_until', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(1000);

		return $this->findEntities($qb);
	}

	/**
	 * @param array{companyId?: int, kind?: string, status?: string, invoiceStatus?: string, expiringBefore?: \DateTimeInterface} $filters
	 * @return Agreement[]
	 */
	public function search(array $filters = [], int $limit = 500, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilters($qb, $filters);
		$qb->orderBy('valid_until', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * @param array{companyId?: int, kind?: string, status?: string, invoiceStatus?: string, expiringBefore?: \DateTimeInterface} $filters
	 */
	public function countSearch(array $filters = []): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());
		$this->applyFilters($qb, $filters);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Agreements the renewal engine watches: they have a `valid_until` and are
	 * not in a terminal status (§6.1). Chunked for the 10k-agreement budget (§15).
	 *
	 * Ordered by `id` so offset pagination stays stable across job ticks.
	 *
	 * @return Agreement[]
	 */
	public function findRenewalCandidates(int $limit = 500, int $offset = 0, ?int $workspaceId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('valid_until'))
			->andWhere($qb->expr()->notIn(
				'status',
				$qb->createNamedParameter(Constants::AGREEMENT_TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->orderBy('id', 'ASC');
		// Archived companies stop generating renewal work (Today + reminder job).
		$this->excludeArchivedCompanies($qb);
		if ($workspaceId !== null && $workspaceId > 0) {
			$this->restrictToWorkspace($qb, $workspaceId);
		}
		$qb
			->setMaxResults(max(1, min(5000, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * Today urgency window: non-draft, non-terminal agreements whose end date
	 * falls between `$floor` and `$horizon` (inclusive), soonest first.
	 *
	 * Unlike {@see findRenewalCandidates}, this is not id-paginated — it must
	 * surface the soonest expiries even when older rows have lower ids.
	 *
	 * @return Agreement[]
	 */
	public function findTodayRenewalCandidates(
		\DateTimeInterface $floor,
		\DateTimeInterface $horizon,
		int $limit = 2000,
		?int $workspaceId = null,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('valid_until'))
			->andWhere($qb->expr()->notIn(
				'status',
				$qb->createNamedParameter(Constants::AGREEMENT_TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('draft')))
			->andWhere($qb->expr()->gte('valid_until', $qb->createNamedParameter($floor->format('Y-m-d'))))
			->andWhere($qb->expr()->lte('valid_until', $qb->createNamedParameter($horizon->format('Y-m-d'))))
			->orderBy('valid_until', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)));
		$this->excludeArchivedCompanies($qb);
		if ($workspaceId !== null && $workspaceId > 0) {
			$this->restrictToWorkspace($qb, $workspaceId);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Active partner agreements in one workspace (hours / digest quota strip).
	 * Fail closed without a workspace — never scan every book.
	 *
	 * @return Agreement[]
	 */
	public function findPartnerAgreements(int $workspaceId, int $limit = 1000): array
	{
		if ($workspaceId <= 0) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('partner')))
			->andWhere($qb->expr()->notIn(
				'status',
				$qb->createNamedParameter(Constants::AGREEMENT_TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)));
		$this->excludeArchivedCompanies($qb);
		$this->restrictToWorkspace($qb, $workspaceId);

		return $this->findEntities($qb);
	}

	public function findBySalesLogId(int $salesLogId): ?Agreement
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('sales_log_id', $qb->createNamedParameter($salesLogId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return Agreement[] */
	public function findByIcInvoiceId(int $icInvoiceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ic_invoice_id', $qb->createNamedParameter($icInvoiceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(100);

		return $this->findEntities($qb);
	}

	/**
	 * Exact invoice-reference match; the caller must treat >1 hit as ambiguous (§14.3).
	 *
	 * @return Agreement[]
	 */
	public function findByInvoiceRef(string $invoiceRef): array
	{
		$invoiceRef = trim($invoiceRef);
		if ($invoiceRef === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('invoice_ref', $qb->createNamedParameter($invoiceRef)))
			->setMaxResults(100);

		return $this->findEntities($qb);
	}

	/**
	 * Same company + product family, used to build supersede chains (§5.4).
	 *
	 * @return Agreement[]
	 */
	public function findByCompanyAndProduct(int $companyId, string $productCode): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('product_code', $qb->createNamedParameter($productCode)))
			->orderBy('valid_until', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(500);

		return $this->findEntities($qb);
	}

	/**
	 * Newest non-terminal agreement for company+product — supersede proposal target (§8.6).
	 */
	public function findActiveByCompanyProduct(int $companyId, string $productCode): ?Agreement
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('product_code', $qb->createNamedParameter($productCode)))
			->andWhere($qb->expr()->notIn(
				'status',
				$qb->createNamedParameter(Constants::AGREEMENT_TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->orderBy('valid_until', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function deleteByCompany(int $companyId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * GDPR erase (§4.8): agreements are retained for ten years for tax/GoBD
	 * reasons, so only the free-text fields that can name a natural person are
	 * blanked. Amounts, dates and product codes stay intact.
	 */
	public function anonymiseByCompany(int $companyId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('label', $qb->createNamedParameter('[erased]'))
			->set('notes', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('cancel_reason', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('created_by', $qb->createNamedParameter('system'))
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * @return list<int>
	 */
	public function findIdsByCompany(int $companyId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * @param array{companyId?: int, companyIds?: list<int>, kind?: string, status?: string, invoiceStatus?: string, expiringBefore?: \DateTimeInterface, workspaceId?: int} $filters
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void
	{
		$companyId = (int)($filters['companyId'] ?? 0);
		$workspaceId = (int)($filters['workspaceId'] ?? 0);
		/** @var list<int> $companyIds */
		$companyIds = array_values(array_filter(array_map('intval', $filters['companyIds'] ?? [])));
		if ($companyId > 0) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		} elseif ($companyIds !== []) {
			$qb->andWhere($qb->expr()->in(
				'company_id',
				$qb->createNamedParameter($companyIds, IQueryBuilder::PARAM_INT_ARRAY)
			));
			$this->excludeArchivedCompanies($qb);
		} else {
			// Default lists never show agreements of archived companies (PSD §4.1);
			// explicit company context (companyId) keeps history reachable.
			$this->excludeArchivedCompanies($qb);
		}
		if ($workspaceId > 0) {
			$this->restrictToWorkspace($qb, $workspaceId);
		}

		$kind = (string)($filters['kind'] ?? '');
		if ($kind !== '') {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}

		$status = (string)($filters['status'] ?? '');
		if ($status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$invoiceStatus = (string)($filters['invoiceStatus'] ?? '');
		if ($invoiceStatus !== '') {
			$qb->andWhere($qb->expr()->eq('invoice_status', $qb->createNamedParameter($invoiceStatus)));
		}

		$expiringBefore = $filters['expiringBefore'] ?? null;
		if ($expiringBefore instanceof \DateTimeInterface) {
			$qb->andWhere($qb->expr()->isNotNull('valid_until'));
			$qb->andWhere($qb->expr()->lte(
				'valid_until',
				$qb->createNamedParameter($expiringBefore->format('Y-m-d'))
			));
		}
	}

	/** Move agreements during company merge. */
	public function reassignCompany(int $fromCompanyId, int $toCompanyId): int
	{
		if ($fromCompanyId === $toCompanyId) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('company_id', $qb->createNamedParameter($toCompanyId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($fromCompanyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

}
