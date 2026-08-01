<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Active OOO cover lookups — product §13.4.
 *
 * Ownership never changes. Backup users see the primary's open work in Mine
 * with a Covering badge for the duration of an active cover window.
 */
class OooCoverService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Primary user ids this backup actively covers today (inclusive window).
	 *
	 * @return list<string>
	 */
	public function primariesCoveredBy(
		int $workspaceId,
		string $backupUserId,
		?\DateTimeInterface $today = null,
	): array {
		$backupUserId = trim($backupUserId);
		if ($workspaceId <= 0 || $backupUserId === '') {
			return [];
		}
		$today = $today ?? $this->timeFactory->getDateTime();
		$out = [];
		foreach ($this->listActive($workspaceId, $today) as $row) {
			if ($row['backupUserId'] === $backupUserId) {
				$out[] = $row['primaryUserId'];
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * User ids whose owned work appears in "Mine" for $userId (self + covered primaries).
	 *
	 * @return list<string>
	 */
	public function mineOwnerIds(
		int $workspaceId,
		string $userId,
		?\DateTimeInterface $today = null,
	): array {
		$userId = trim($userId);
		if ($userId === '') {
			return [];
		}
		$ids = [$userId];
		foreach ($this->primariesCoveredBy($workspaceId, $userId, $today) as $primary) {
			$ids[] = $primary;
		}
		return array_values(array_unique($ids));
	}

	/**
	 * @return list<array{id: int, primaryUserId: string, backupUserId: string, startsOn: string, endsOn: string, status: string}>
	 */
	public function listActive(int $workspaceId, ?\DateTimeInterface $today = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$out = [];
		foreach ($this->listAll($workspaceId) as $row) {
			if ($row['status'] === 'ended') {
				continue;
			}
			$starts = new \DateTimeImmutable($row['startsOn']);
			$ends = new \DateTimeImmutable($row['endsOn']);
			if (!OooLogic::isActive($starts, $ends, $today)) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @return list<array{id: int, primaryUserId: string, backupUserId: string, startsOn: string, endsOn: string, status: string}>
	 */
	public function listAll(int $workspaceId): array
	{
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_ooo')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_ooo')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)));
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'primaryUserId' => (string)$row['primary_user_id'],
				'backupUserId' => (string)$row['backup_user_id'],
				'startsOn' => (string)$row['starts_on'],
				'endsOn' => (string)$row['ends_on'],
				'status' => (string)($row['status'] ?? 'scheduled'),
			];
		}
		$r->closeCursor();
		return $out;
	}
}
