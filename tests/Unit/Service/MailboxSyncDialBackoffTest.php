<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * SF-02 dial backoff math + cooldown predicate.
 */
final class MailboxSyncDialBackoffTest extends TestCase
{
	public function testCooldownActiveWhenUntilInFuture(): void
	{
		$svc = $this->mailboxService();
		$now = new \DateTimeImmutable('2026-08-15 12:00:00');
		$this->assertTrue($svc->isSyncDialCooldownActive([
			'sync_cooldown_until' => '2026-08-15 12:05:00',
		], $now));
		$this->assertFalse($svc->isSyncDialCooldownActive([
			'sync_cooldown_until' => '2026-08-15 11:59:00',
		], $now));
		$this->assertFalse($svc->isSyncDialCooldownActive([], $now));
		$this->assertFalse($svc->isSyncDialCooldownActive([
			'sync_cooldown_until' => null,
		], $now));
	}

	public function testRecordSyncDialFailureWritesExponentialCooldown(): void
	{
		$captured = [];
		$qb = new class ($captured) {
			/** @var array<string, mixed> */
			private array $cap;
			public function __construct(array &$cap)
			{
				$this->cap = &$cap;
			}
			public function update(...$a)
			{
				return $this;
			}
			public function set($k, $v)
			{
				$this->cap[$k] = $v;
				return $this;
			}
			public function where(...$a)
			{
				return $this;
			}
			public function createNamedParameter($v, ...$a)
			{
				return $v;
			}
			public function executeStatement(): int
			{
				return 1;
			}
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-15 12:00:00'));
		$l10n = $this->createMock(IL10N::class);

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$l10n,
			new HtmlSanitizeService(),
		);
		// streak 0 → next 1 → 60s
		$svc->recordSyncDialFailure(7, 0, 'boom');
		$this->assertSame(1, $captured['sync_fail_streak'] ?? null);
		$this->assertSame('2026-08-15 12:01:00', $captured['sync_cooldown_until'] ?? null);

		foreach (array_keys($captured) as $k) {
			unset($captured[$k]);
		}
		// streak 3 → next 4 → 60*2^3 = 480s
		$svc->recordSyncDialFailure(7, 3, 'boom');
		$this->assertSame(4, $captured['sync_fail_streak'] ?? null);
		$this->assertSame('2026-08-15 12:08:00', $captured['sync_cooldown_until'] ?? null);
	}

	private function mailboxService(): MailboxService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-15 12:00:00'));

		return new MailboxService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$time,
			$this->createMock(IL10N::class),
			new HtmlSanitizeService(),
		);
	}
}
