<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\App\IAppManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Soft-dep Circles directory for one-shot workspace member import (product §6).
 * Never live-syncs. When Circles is absent/disabled, all methods return empty / unavailable.
 */
class CirclesDirectoryService
{
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool
	{
		if (!$this->appManager->isEnabledForUser('circles')) {
			return false;
		}

		return class_exists(\OCA\Circles\CirclesManager::class)
			&& class_exists(\OCA\Circles\Service\MemberService::class)
			&& class_exists(\OCA\Circles\Model\Member::class);
	}

	/**
	 * Circles the actor belongs to (hidden/backend filtered).
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	public function availableCirclesForUser(string $userId): array
	{
		$userId = trim($userId);
		if ($userId === '' || !$this->isAvailable()) {
			return [];
		}

		try {
			/** @var \OCA\Circles\CirclesManager $mgr */
			$mgr = Server::get(\OCA\Circles\CirclesManager::class);
			$federated = $mgr->getLocalFederatedUser($userId);
			$mgr->startSession($federated);
			try {
				$probe = new \OCA\Circles\Model\Probes\CircleProbe();
				$probe->filterHiddenCircles()->filterBackendCircles();
				$circles = $mgr->probeCircles($probe);
				$out = [];
				foreach ($circles as $circle) {
					$id = trim((string)$circle->getSingleId());
					if ($id === '') {
						continue;
					}
					$name = trim((string)$circle->getName());
					$out[] = [
						'id' => $id,
						'displayName' => $name !== '' ? $name : $id,
					];
				}
				usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));

				return $out;
			} finally {
				$mgr->stopSession();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck Circles list failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * Local TYPE_USER member UIDs visible to the actor in that circle.
	 *
	 * @return array{circleId: string, circleName: string, userIds: list<string>}
	 */
	public function localUserIdsInCircle(string $circleId, string $actorUserId): array
	{
		$circleId = trim($circleId);
		$actorUserId = trim($actorUserId);
		if ($circleId === '' || $actorUserId === '' || !$this->isAvailable()) {
			return [
				'circleId' => $circleId,
				'circleName' => '',
				'userIds' => [],
			];
		}

		try {
			/** @var \OCA\Circles\CirclesManager $mgr */
			$mgr = Server::get(\OCA\Circles\CirclesManager::class);
			$federated = $mgr->getLocalFederatedUser($actorUserId);
			$mgr->startSession($federated);
			try {
				$circle = $mgr->getCircle($circleId);
				$circleName = trim((string)$circle->getName());
				if ($circleName === '') {
					$circleName = $circleId;
				}

				/** @var \OCA\Circles\Service\MemberService $members */
				$members = Server::get(\OCA\Circles\Service\MemberService::class);
				$raw = $members->getMembers($circleId, false);
				$typeUser = \OCA\Circles\Model\Member::TYPE_USER;
				$userIds = [];
				$seen = [];
				foreach ($raw as $member) {
					if ((int)$member->getUserType() !== $typeUser) {
						continue;
					}
					$uid = trim((string)$member->getUserId());
					if ($uid === '' || isset($seen[$uid])) {
						continue;
					}
					$seen[$uid] = true;
					$userIds[] = $uid;
				}

				return [
					'circleId' => $circleId,
					'circleName' => $circleName,
					'userIds' => $userIds,
				];
			} finally {
				$mgr->stopSession();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck Circles member resolve failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
