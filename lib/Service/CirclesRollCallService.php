<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Circles roll-call — product §10.6.
 *
 * App-admin view: workspaces with commitment completeness / confidence.
 * Never includes a summed overdue € column (forbidden blend).
 */
class CirclesRollCallService
{
	public function __construct(
		private readonly WorkspaceService $workspaces,
		private readonly BriefService $brief,
	) {
	}

	/**
	 * @return list<array{
	 *   workspaceId: int,
	 *   name: string,
	 *   slug: string,
	 *   weekKey: string,
	 *   wipUsed: int,
	 *   wipLimit: int,
	 *   committed: int,
	 *   done: int,
	 *   missed: int,
	 *   completeness: float,
	 *   confidence: string
	 * }>
	 */
	public function build(?\DateTimeInterface $today = null): array
	{
		$today = $today ?? new \DateTimeImmutable('today');
		$weekKey = BriefLogic::isoWeekKey($today);
		$out = [];
		foreach ($this->workspaces->listAll() as $ws) {
			if ($ws->isArchived()) {
				continue;
			}
			$wsId = (int)$ws->getId();
			$brief = $this->brief->build($wsId, $today);
			$commits = $brief['commitments'] ?? [];
			$committed = 0;
			$done = 0;
			$missed = 0;
			foreach ($commits as $c) {
				$status = (string)($c['status'] ?? '');
				if ($status === 'committed') {
					$committed++;
				} elseif ($status === 'done') {
					$done++;
				} elseif ($status === 'missed') {
					$missed++;
				}
			}
			$last = $brief['lastWeek'] ?? [];
			$lastTotal = count($last);
			$lastDone = 0;
			foreach ($last as $c) {
				if (($c['status'] ?? '') === 'done') {
					$lastDone++;
				}
			}
			$completeness = $lastTotal > 0 ? round($lastDone / $lastTotal, 2) : 0.0;
			$confidence = match (true) {
				$completeness >= 0.8 => 'high',
				$completeness >= 0.5 => 'medium',
				$lastTotal === 0 => 'unknown',
				default => 'low',
			};
			$out[] = [
				'workspaceId' => $wsId,
				'name' => (string)$ws->getName(),
				'slug' => (string)$ws->getSlug(),
				'weekKey' => $weekKey,
				'wipUsed' => (int)($brief['wipUsed'] ?? 0),
				'wipLimit' => (int)($brief['wipLimit'] ?? 5),
				'committed' => $committed,
				'done' => $done,
				'missed' => $missed,
				'completeness' => $completeness,
				'confidence' => $confidence,
			];
		}
		return $out;
	}
}
