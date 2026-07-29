<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/** Customer promises — distinct from CEO commitments (§12.4). */
class PromiseService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function listForCompany(int $companyId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_cust_prm')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->orderBy('due_on', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->map($row);
		}
		$r->closeCursor();
		return $out;
	}

	/** @return list<array<string, mixed>> */
	public function dueSoon(int $workspaceId, \DateTimeInterface $today, int $withinDays = 7): array
	{
		if (!$this->db->tableExists('crm_cust_prm')) {
			return [];
		}
		$until = (clone \DateTimeImmutable::createFromInterface($today))->modify('+' . $withinDays . ' days')->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_cust_prm')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->lte('due_on', $qb->createNamedParameter($until)))
			->orderBy('due_on', 'ASC')
			->setMaxResults(100);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->map($row);
		}
		$r->closeCursor();
		return $out;
	}

	/** @param array<string, mixed> $data */
	public function create(int $companyId, array $data, string $actor): array
	{
		$company = $this->companies->get($companyId);
		$ws = (int)($company->getWorkspaceId() ?? 0);
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a promise title.'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_cust_prm')->values([
			'company_id' => $ins->createNamedParameter($companyId),
			'workspace_id' => $ins->createNamedParameter($ws),
			'title' => $ins->createNamedParameter($title),
			'due_on' => $ins->createNamedParameter($data['dueOn'] ?? null),
			'status' => $ins->createNamedParameter('open'),
			'person_id' => $ins->createNamedParameter(isset($data['personId']) ? (int)$data['personId'] : null),
			'owner_user_id' => $ins->createNamedParameter($data['ownerUserId'] ?? $actor),
			'created_by' => $ins->createNamedParameter($actor),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
		return ['id' => (int)$this->db->lastInsertId('crm_cust_prm'), 'title' => $title, 'status' => 'open'];
	}

	public function complete(int $id, int $workspaceId, string $evidence, string $actor): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_cust_prm')
			->set('status', $qb->createNamedParameter('done'))
			->set('evidence', $qb->createNamedParameter($evidence))
			->set('done_at', $qb->createNamedParameter($this->timeFactory->getDateTime()->format('Y-m-d H:i:s')))
			->set('updated_at', $qb->createNamedParameter($this->timeFactory->getDateTime()->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->executeStatement();
		unset($actor);
	}

	/** @param array<string, mixed> $row */
	private function map(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'companyId' => (int)$row['company_id'],
			'workspaceId' => (int)$row['workspace_id'],
			'title' => (string)$row['title'],
			'dueOn' => $row['due_on'] ?? null,
			'status' => (string)$row['status'],
			'ownerUserId' => $row['owner_user_id'] ?? null,
			'evidence' => $row['evidence'] ?? null,
		];
	}
}
