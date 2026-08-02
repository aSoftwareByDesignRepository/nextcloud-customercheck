<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Three-way field classify for edit conflicts — GNQ §6.3.
 *
 * @return 'noop'|'take_incoming'|'keep_current'|'conflict'
 */
final class ConflictFieldLogic
{
	public static function classify(mixed $base, mixed $current, mixed $incoming): string
	{
		$b = self::norm($base);
		$c = self::norm($current);
		$i = self::norm($incoming);
		if ($i === $c) {
			return 'noop';
		}
		if ($c === $b) {
			return 'take_incoming';
		}
		if ($i === $b) {
			return 'keep_current';
		}

		return 'conflict';
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $incoming keys = patched fields only
	 * @return array{conflicts: list<string>, takeIncoming: list<string>, keepCurrent: list<string>}
	 */
	public static function classifyPatch(array $base, array $current, array $incoming): array
	{
		$conflicts = [];
		$take = [];
		$keep = [];
		foreach ($incoming as $field => $value) {
			$field = (string)$field;
			$decision = self::classify($base[$field] ?? null, $current[$field] ?? null, $value);
			match ($decision) {
				'conflict' => $conflicts[] = $field,
				'take_incoming' => $take[] = $field,
				'keep_current' => $keep[] = $field,
				default => null,
			};
		}

		return [
			'conflicts' => $conflicts,
			'takeIncoming' => $take,
			'keepCurrent' => $keep,
		];
	}

	private static function norm(mixed $v): string
	{
		if ($v === null) {
			return '';
		}
		if (is_bool($v)) {
			return $v ? '1' : '0';
		}
		if (is_int($v) || is_float($v)) {
			return (string)$v;
		}

		return trim((string)$v);
	}
}
