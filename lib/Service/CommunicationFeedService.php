<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Unified communication feed merge/dedupe — COMMAND-CENTER-SPEC §6.
 *
 * Pure merge logic so mutation tests can kill sort/dedupe/filter bugs without DB.
 */
class CommunicationFeedService
{
	public const HUMAN_KINDS = ['email_touch', 'mail_related', 'activity', 'note', 'shared_mail'];

	/**
	 * @param list<array{
	 *   id: string,
	 *   kind: string,
	 *   occurredAt: string,
	 *   direction?: string|null,
	 *   personId?: int|null,
	 *   personName?: string|null,
	 *   subject?: string|null,
	 *   snippet?: string|null,
	 *   deepLink?: string|null,
	 *   mailMessageId?: string|null
	 * }> $items
	 * @return list<array<string, mixed>>
	 */
	public function mergeAndSort(array $items, bool $includeSystem = false): array
	{
		$byMailId = [];
		$out = [];

		foreach ($items as $item) {
			$kind = (string)($item['kind'] ?? '');
			if (!$includeSystem && $kind === 'system') {
				continue;
			}
			if (!$includeSystem && !in_array($kind, self::HUMAN_KINDS, true)) {
				continue;
			}

			$mailId = isset($item['mailMessageId']) ? trim((string)$item['mailMessageId']) : '';
			if ($mailId !== '') {
				if (isset($byMailId[$mailId])) {
					$existing = $byMailId[$mailId];
					// Prefer email_touch with snippet over mail_related (§6.2).
					$preferNew = $this->preferOver($item, $existing);
					if ($preferNew) {
						$out[$existing['_idx']] = null;
						$item['_idx'] = $existing['_idx'];
						$byMailId[$mailId] = $item;
						$out[$item['_idx']] = $item;
					}
					continue;
				}
				$item['_idx'] = count($out);
				$byMailId[$mailId] = $item;
				$out[] = $item;
				continue;
			}

			$item['_idx'] = count($out);
			$out[] = $item;
		}

		$clean = [];
		foreach ($out as $row) {
			if ($row === null) {
				continue;
			}
			unset($row['_idx']);
			$clean[] = $row;
		}

		usort($clean, static function (array $a, array $b): int {
			$cmp = strcmp((string)($b['occurredAt'] ?? ''), (string)($a['occurredAt'] ?? ''));
			if ($cmp !== 0) {
				return $cmp;
			}

			return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
		});

		return array_values($clean);
	}

	/**
	 * Page slice with opaque cursor = occurredAt|id of last item.
	 *
	 * @param list<array<string, mixed>> $sorted
	 * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
	 */
	public function paginate(array $sorted, int $limit = 25, ?string $cursor = null): array
	{
		$limit = max(1, min(100, $limit));
		$start = 0;
		if ($cursor !== null && $cursor !== '') {
			[$cAt, $cId] = array_pad(explode('|', $cursor, 2), 2, '');
			foreach ($sorted as $i => $row) {
				$at = (string)($row['occurredAt'] ?? '');
				$id = (string)($row['id'] ?? '');
				if ($at < $cAt || ($at === $cAt && $id <= $cId)) {
					$start = $i + 1;
				}
				if ($at < $cAt) {
					// keep scanning — list is DESC so once we pass older rows we're done finding
				}
			}
			// Recompute: find first row strictly older than cursor (DESC order).
			$start = 0;
			foreach ($sorted as $i => $row) {
				$at = (string)($row['occurredAt'] ?? '');
				$id = (string)($row['id'] ?? '');
				$cmp = strcmp($at, $cAt);
				if ($cmp < 0 || ($cmp === 0 && strcmp($id, $cId) < 0)) {
					$start = $i;
					break;
				}
				$start = $i + 1;
			}
		}

		$slice = array_slice($sorted, $start, $limit);
		$next = null;
		if (count($sorted) > $start + $limit && $slice !== []) {
			$last = $slice[array_key_last($slice)];
			$next = (string)($last['occurredAt'] ?? '') . '|' . (string)($last['id'] ?? '');
		}

		return ['items' => array_values($slice), 'nextCursor' => $next];
	}

	/**
	 * Last-touch aggregation per person — CC §5.
	 *
	 * @param list<array{personId?: int|null, occurredAt: string, subject?: string|null}> $touches
	 * @return array<int, array{at: string, subject: string|null}>
	 */
	public function lastTouchByPerson(array $touches): array
	{
		$best = [];
		foreach ($touches as $t) {
			$pid = (int)($t['personId'] ?? 0);
			if ($pid <= 0) {
				continue;
			}
			$at = (string)($t['occurredAt'] ?? '');
			if ($at === '') {
				continue;
			}
			if (!isset($best[$pid]) || strcmp($at, $best[$pid]['at']) > 0) {
				$best[$pid] = [
					'at' => $at,
					'subject' => isset($t['subject']) ? (string)$t['subject'] : null,
				];
			}
		}

		return $best;
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private function preferOver(array $a, array $b): bool
	{
		$aTouch = ($a['kind'] ?? '') === 'email_touch';
		$bTouch = ($b['kind'] ?? '') === 'email_touch';
		if ($aTouch && !$bTouch) {
			return true;
		}
		if (!$aTouch && $bTouch) {
			return false;
		}
		$aSnippet = trim((string)($a['snippet'] ?? ''));
		$bSnippet = trim((string)($b['snippet'] ?? ''));
		if ($aSnippet !== '' && $bSnippet === '') {
			return true;
		}

		return false;
	}
}
