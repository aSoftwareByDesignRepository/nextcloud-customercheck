<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Support\DateMath;

/**
 * Deterministic “Next action” priority — COMMAND-CENTER-SPEC §4.2.
 *
 * Score descending; first wins. Tie-break: earlier due date, then lower entity id.
 */
class NextActionPriority
{
	/**
	 * @param list<array{
	 *   kind: string,
	 *   label: string,
	 *   dueDate?: string|null,
	 *   targetType: string,
	 *   targetId: int,
	 *   preferCall?: bool
	 * }> $candidates
	 * @return array{kind: string, label: string, dueDate: string|null, target: array{type: string, id: int}}|null
	 */
	public function pick(array $candidates, \DateTimeInterface $today): ?array
	{
		if ($candidates === []) {
			return null;
		}

		$ranked = [];
		foreach ($candidates as $c) {
			$score = $this->score($c, $today);
			if ($score === null) {
				continue;
			}
			$ranked[] = [
				'score' => $score,
				'due' => (string)($c['dueDate'] ?? ''),
				'id' => (int)$c['targetId'],
				'kind' => (string)$c['kind'],
				'label' => (string)$c['label'],
				'targetType' => (string)$c['targetType'],
				'targetId' => (int)$c['targetId'],
				'dueDate' => isset($c['dueDate']) && $c['dueDate'] !== '' ? (string)$c['dueDate'] : null,
			];
		}

		if ($ranked === []) {
			return null;
		}

		usort($ranked, static function (array $a, array $b): int {
			$s = $b['score'] <=> $a['score'];
			if ($s !== 0) {
				return $s;
			}
			$d = strcmp($a['due'], $b['due']);
			if ($d !== 0) {
				return $d;
			}

			return $a['id'] <=> $b['id'];
		});

		$winner = $ranked[0];

		return [
			'kind' => $winner['kind'],
			'label' => $winner['label'],
			'dueDate' => $winner['dueDate'],
			'target' => [
				'type' => $winner['targetType'],
				'id' => $winner['targetId'],
			],
		];
	}

	/**
	 * @param array{kind: string, dueDate?: string|null, preferCall?: bool} $c
	 */
	private function score(array $c, \DateTimeInterface $today): ?int
	{
		$kind = (string)$c['kind'];
		$due = isset($c['dueDate']) && is_string($c['dueDate']) && $c['dueDate'] !== ''
			? new \DateTimeImmutable($c['dueDate'])
			: null;

		return match ($kind) {
			'callback_overdue', 'activity_overdue' => 700 + (!empty($c['preferCall']) ? 10 : 0),
			'renewal_urgent' => 600,
			'invoice_overdue' => 500,
			'activity_due_today' => 400,
			'deal_stale' => 300,
			'ticket_waiting' => 200,
			default => null,
		};
	}

	/**
	 * Days until due (negative = overdue). Null when no due date.
	 */
	public function daysUntil(?\DateTimeInterface $due, \DateTimeInterface $today): ?int
	{
		if ($due === null) {
			return null;
		}

		return DateMath::daysBetween($today, $due);
	}
}
