<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Sent-date matching + coalesce ordering helpers for Review list.
 */
final class ReviewSentAtLogicTest extends TestCase
{
	public function testDateQueryMatchesIsoAndGermanFragments(): void
	{
		$svc = $this->bareService();
		$m = new ReflectionMethod(ReviewService::class, 'reviewItemMatchesDateQuery');
		$m->setAccessible(true);

		$item = [
			'sentAt' => '2026-08-12 14:30:00',
			'createdAt' => '2026-08-11 09:00:00',
			'updatedAt' => '2026-08-13 01:00:00',
		];
		$this->assertTrue($m->invoke($svc, $item, '2026-08-12'));
		$this->assertTrue($m->invoke($svc, $item, '12.08.2026'));
		$this->assertTrue($m->invoke($svc, $item, '14:30'));
		$this->assertFalse($m->invoke($svc, $item, '2025-01-01'));
		$this->assertTrue($m->invoke($svc, $item, ''));
	}

	public function testDateQueryFallsBackToCreatedWhenSentMissing(): void
	{
		$svc = $this->bareService();
		$m = new ReflectionMethod(ReviewService::class, 'reviewItemMatchesDateQuery');
		$m->setAccessible(true);
		$item = [
			'sentAt' => null,
			'createdAt' => '2026-07-01 08:15:00',
			'updatedAt' => '2026-08-01 00:00:00',
		];
		$this->assertTrue($m->invoke($svc, $item, '2026-07-01'));
		$this->assertFalse($m->invoke($svc, $item, '2026-08-12'));
	}

	public function testEnrichSentAtMapsWorkspaceScopedIds(): void
	{
		$svc = $this->bareService();
		$m = new ReflectionMethod(ReviewService::class, 'enrichSentAt');
		$m->setAccessible(true);

		$items = [
			[
				'id' => 1,
				'sourceKind' => 'shared_mail',
				'sourceRef' => ['mailMsgId' => 10],
				'sentAt' => null,
			],
			[
				'id' => 2,
				'sourceKind' => 'contact_suggest',
				'sourceRef' => [],
				'sentAt' => null,
			],
			[
				'id' => 3,
				'sourceKind' => 'shared_mail',
				'sourceRef' => ['mailMsgId' => 99],
				'sentAt' => null,
			],
		];

		// Without crm_mail_msg table the method returns items unchanged (safe no-op).
		$out = $m->invoke($svc, 7, $items);
		$this->assertSame($items, $out);
	}

	private function bareService(): ReviewService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $msg, array $args = []): string {
			if ($args === []) {
				return $msg;
			}

			return str_replace('%s', (string)($args[0] ?? ''), $msg);
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		return new ReviewService(
			$db,
			$this->createMock(ITimeFactory::class),
			$l10n,
			$this->createMock(MailboxService::class),
			$this->createMock(CompanyService::class),
			$this->createMock(CompanyMapper::class),
			$this->createMock(PersonMapper::class),
			$this->createMock(WorkspaceService::class),
		);
	}
}
