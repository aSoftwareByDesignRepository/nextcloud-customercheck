<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\CompanyScopedFk;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/** Customer promises — distinct from CEO commitments (§12.4). */
class PromiseService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly DisplayLabelService $labels,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly PersonMapper $personMapper,
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
		$qb->select('p.*')->from('crm_cust_prm', 'p')
			->where($qb->expr()->eq('p.workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('p.status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->lte('p.due_on', $qb->createNamedParameter($until)));
		// Archived companies drop out of Today — promises must not leak after merge.
		if ($this->db->tableExists('crm_company')) {
			$qb->innerJoin('p', 'crm_company', 'c', $qb->expr()->eq('c.id', 'p.company_id'))
				->andWhere($qb->expr()->isNull('c.archived_at'));
		}
		$qb->orderBy('p.due_on', 'ASC')
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
		$dueOn = trim((string)($data['dueOn'] ?? ''));
		if ($dueOn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'dueOn', $this->l10n->t('Enter a due date for this promise.'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$personId = isset($data['personId']) ? (int)$data['personId'] : 0;
		$boundPersonId = null;
		if ($personId > 0) {
			try {
				$person = $this->personMapper->findById($personId);
			} catch (DoesNotExistException) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'personId',
					$this->l10n->t('Pick a person on this company.'),
				);
			}
			CompanyScopedFk::assertSameCompany(
				$companyId,
				(int)$person->getCompanyId(),
				'personId',
				$this->l10n,
				$this->l10n->t('Pick a person on this company.'),
			);
			$boundPersonId = $personId;
		}
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_cust_prm')->values([
			'company_id' => $ins->createNamedParameter($companyId),
			'workspace_id' => $ins->createNamedParameter($ws),
			'title' => $ins->createNamedParameter($title),
			'due_on' => $ins->createNamedParameter($dueOn),
			'status' => $ins->createNamedParameter('open'),
			'person_id' => $ins->createNamedParameter($boundPersonId),
			'owner_user_id' => $ins->createNamedParameter($data['ownerUserId'] ?? $actor),
			'created_by' => $ins->createNamedParameter($actor),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
		return [
			'id' => (int)$this->db->lastInsertId('crm_cust_prm'),
			'title' => $title,
			'status' => 'open',
			'statusLabel' => $this->labels->promiseStatus('open'),
			'dueOn' => $dueOn,
		];
	}

	public function complete(int $id, int $workspaceId, string $evidence, string $actor, int $companyId): void
	{
		if ($companyId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'companyId',
				$this->l10n->t('Pick the company this promise belongs to.'),
			);
		}
		$evidence = trim($evidence);
		if ($evidence === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'evidence',
				$this->l10n->t('Add a short note as evidence that this promise was kept.'),
			);
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_cust_prm')
			->set('status', $qb->createNamedParameter('done'))
			->set('evidence', $qb->createNamedParameter($evidence))
			->set('done_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->executeStatement();
		if ($affected < 1) {
			throw ValidationException::field(
				Constants::ERR_NOT_FOUND,
				'id',
				$this->l10n->t('Open promise not found.'),
			);
		}
		unset($actor);
	}

	public function cancel(int $id, int $workspaceId, string $actor, int $companyId): void
	{
		if ($companyId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'companyId',
				$this->l10n->t('Pick the company this promise belongs to.'),
			);
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_cust_prm')
			->set('status', $qb->createNamedParameter('cancelled'))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->executeStatement();
		if ($affected < 1) {
			throw ValidationException::field(
				Constants::ERR_NOT_FOUND,
				'id',
				$this->l10n->t('Open promise not found.'),
			);
		}
		unset($actor);
	}

	/** @param array<string, mixed> $row */
	private function map(array $row): array
	{
		$status = (string)$row['status'];
		return [
			'id' => (int)$row['id'],
			'companyId' => (int)$row['company_id'],
			'workspaceId' => (int)$row['workspace_id'],
			'title' => (string)$row['title'],
			'dueOn' => $row['due_on'] ?? null,
			'status' => $status,
			'statusLabel' => $this->labels->promiseStatus($status),
			'ownerUserId' => $row['owner_user_id'] ?? null,
			'evidence' => $row['evidence'] ?? null,
		];
	}
}
