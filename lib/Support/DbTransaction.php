<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCP\IDBConnection;

/**
 * Nested-transaction safe begin/commit/rollback.
 *
 * When an outer caller already opened a transaction (e.g. conflict resolve),
 * entity updates must not commit/rollback the outer unit of work. Doctrine
 * savepoints help when enabled; owning the txn explicitly is foolproof.
 */
final class DbTransaction
{
	/** @return bool true when this call opened the transaction */
	public static function begin(IDBConnection $db): bool
	{
		if ($db->inTransaction()) {
			return false;
		}
		$db->beginTransaction();

		return true;
	}

	public static function commit(IDBConnection $db, bool $owns): void
	{
		if ($owns) {
			$db->commit();
		}
	}

	public static function rollBack(IDBConnection $db, bool $owns): void
	{
		// Match commit(): only the opener may end the unit of work.
		// Do not re-check inTransaction() — mocks and some drivers report
		// stale state; ownership is the source of truth after begin().
		if ($owns) {
			$db->rollBack();
		}
	}
}
