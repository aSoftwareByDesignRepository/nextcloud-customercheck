<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Repair;

use OCA\CustomerCheck\Constants;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * One-shot: create Default workspace, attach orphan companies, seed members.
 *
 * Product §6 — existing installs must not lose companies outside a workspace.
 */
final class BootstrapDefaultWorkspace implements IRepairStep
{
	public const APP_ID = 'customercheck';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Bootstrap CustomerCheck Default workspace';
	}

	public function run(IOutput $output): void
	{
		if (!$this->db->tableExists('crm_workspace') || !$this->db->tableExists('crm_company')) {
			$output->info('CustomerCheck workspace tables not ready — skip bootstrap.');
			return;
		}

		$wsId = $this->ensureDefaultWorkspace($output);
		$this->attachOrphanCompanies($wsId, $output);
		$this->seedMembers($wsId, $output);
		$this->ensureSettings($wsId);
	}

	private function ensureDefaultWorkspace(IOutput $output): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('crm_workspace')
			->where($qb->expr()->eq('is_default', $qb->createNamedParameter(1)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row !== false) {
			return (int)$row['id'];
		}

		// Single-org / fail-closed: never mint a second book. Prefer any existing row.
		$any = $this->db->getQueryBuilder();
		$any->select('id')
			->from('crm_workspace')
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$anyResult = $any->executeQuery();
		$anyRow = $anyResult->fetch();
		$anyResult->closeCursor();
		if ($anyRow !== false) {
			$id = (int)$anyRow['id'];
			$promote = $this->db->getQueryBuilder();
			$promote->update('crm_workspace')
				->set('is_default', $promote->createNamedParameter(1))
				->where($promote->expr()->eq('id', $promote->createNamedParameter($id)))
				->executeStatement();
			$output->info('Promoted existing workspace id=' . $id . ' to Default (single organisation).');
			return $id;
		}

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_workspace')
			->values([
				'slug' => $ins->createNamedParameter('organisation'),
				'name' => $ins->createNamedParameter('Organisation'),
				'is_default' => $ins->createNamedParameter(1),
				'created_by' => $ins->createNamedParameter('system'),
				'created_at' => $ins->createNamedParameter($now),
				'updated_at' => $ins->createNamedParameter($now),
			])
			->executeStatement();

		$id = (int)$this->db->lastInsertId('crm_workspace');
		if ($id <= 0) {
			// PostgreSQL / drivers: re-select
			$qb2 = $this->db->getQueryBuilder();
			$qb2->select('id')->from('crm_workspace')
				->where($qb2->expr()->eq('slug', $qb2->createNamedParameter('organisation')))
				->setMaxResults(1);
			$r2 = $qb2->executeQuery();
			$row2 = $r2->fetch();
			$r2->closeCursor();
			$id = (int)($row2['id'] ?? 0);
		}
		$output->info('Created Default workspace (Organisation) id=' . $id);
		return $id;
	}

	private function attachOrphanCompanies(int $wsId, IOutput $output): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_company')
			->set('workspace_id', $qb->createNamedParameter($wsId))
			->where($qb->expr()->isNull('workspace_id'));
		$n = $qb->executeStatement();
		if ($n > 0) {
			$output->info(sprintf('Attached %d companies to Default workspace.', $n));
		}
	}

	private function seedMembers(int $wsId, IOutput $output): void
	{
		$rawUsers = $this->config->getAppValue(
			self::APP_ID,
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_ACCESS_ALLOWED_USER_IDS],
		);
		$users = json_decode($rawUsers, true);
		if (!is_array($users)) {
			$users = [];
		}

		// Also include configured app admins who are system admins — membership helps switcher.
		$rawAdmins = $this->config->getAppValue(
			self::APP_ID,
			Constants::CONFIG_APP_ADMIN_USER_IDS,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_APP_ADMIN_USER_IDS],
		);
		$admins = json_decode($rawAdmins, true);
		if (!is_array($admins)) {
			$admins = [];
		}

		$uids = array_values(array_unique(array_filter(array_map('strval', array_merge($users, $admins)))));
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$added = 0;
		foreach ($uids as $uid) {
			$uid = trim($uid);
			if ($uid === '') {
				continue;
			}
			$check = $this->db->getQueryBuilder();
			$check->select($check->func()->count('*', 'cnt'))
				->from('crm_ws_member')
				->where($check->expr()->eq('workspace_id', $check->createNamedParameter($wsId)))
				->andWhere($check->expr()->eq('user_id', $check->createNamedParameter($uid)));
			$r = $check->executeQuery();
			$exists = (int)$r->fetchOne() > 0;
			$r->closeCursor();
			if ($exists) {
				continue;
			}
			$role = in_array($uid, $admins, true) ? 'manager' : 'member';
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_ws_member')
				->values([
					'workspace_id' => $ins->createNamedParameter($wsId),
					'user_id' => $ins->createNamedParameter($uid),
					'role' => $ins->createNamedParameter($role),
					'can_mail_send' => $ins->createNamedParameter(1),
					'created_at' => $ins->createNamedParameter($now),
					'updated_at' => $ins->createNamedParameter($now),
				])
				->executeStatement();
			$added++;
		}
		if ($added > 0) {
			$output->info(sprintf('Seeded %d workspace members.', $added));
		}
		unset($output); // silence unused when zero — still OK
	}

	private function ensureSettings(int $wsId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('crm_ws_set')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($wsId)));
		$r = $qb->executeQuery();
		$n = (int)$r->fetchOne();
		$r->closeCursor();
		if ($n > 0) {
			return;
		}
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_ws_set')
			->values([
				'workspace_id' => $ins->createNamedParameter($wsId),
				'wip_limit' => $ins->createNamedParameter(Constants::WIP_LIMIT_DEFAULT),
				'lead_sla_hours' => $ins->createNamedParameter(Constants::LEAD_SLA_HOURS_DEFAULT),
				'stuck_chase_days' => $ins->createNamedParameter(3),
				'mail_retention_days' => $ins->createNamedParameter(365),
				'digest_enabled' => $ins->createNamedParameter(1),
				'updated_at' => $ins->createNamedParameter($now),
			])
			->executeStatement();
	}
}
