<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\BillingIntegrityService;
use OCA\CustomerCheck\Service\CommunicationFeedService;
use OCA\CustomerCheck\Service\ContactsUidValidator;
use OCA\CustomerCheck\Service\NextActionPriority;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class CommandCenterCoreLogicTest extends TestCase
{
	public function testBillingCriticalWhenOverdue(): void
	{
		$svc = new BillingIntegrityService();
		$r = $svc->evaluate([
			'pcLinked' => true,
			'icLinked' => true,
			'overdueCount' => 1,
			'openCount' => 0,
			'billableKnown' => true,
			'openBillableMinutes' => 0,
			'uninvoicedKnown' => true,
			'uninvoicedProjects' => 0,
		]);
		self::assertSame('critical', $r['state']);
	}

	public function testBillingAttentionOnOpenBillable(): void
	{
		$svc = new BillingIntegrityService();
		$r = $svc->evaluate([
			'pcLinked' => true,
			'icLinked' => true,
			'overdueCount' => 0,
			'openCount' => 0,
			'billableKnown' => true,
			'openBillableMinutes' => 15,
			'uninvoicedKnown' => false,
			'uninvoicedProjects' => null,
		]);
		self::assertSame('attention', $r['state']);
	}

	public function testBillingOkWhenClean(): void
	{
		$svc = new BillingIntegrityService();
		$r = $svc->evaluate([
			'pcLinked' => true,
			'icLinked' => true,
			'overdueCount' => 0,
			'openCount' => 0,
			'billableKnown' => true,
			'openBillableMinutes' => 0,
			'uninvoicedKnown' => true,
			'uninvoicedProjects' => 0,
		]);
		self::assertSame('ok', $r['state']);
	}

	public function testBillingUnknownWhenUnlinked(): void
	{
		$svc = new BillingIntegrityService();
		$r = $svc->evaluate(['pcLinked' => false, 'icLinked' => false]);
		self::assertSame('unknown', $r['state']);
	}

	public function testClassifyInvoiceOverdueFlag(): void
	{
		$svc = new BillingIntegrityService();
		self::assertSame('overdue', $svc->classifyInvoiceRow(['overdue' => true, 'status' => 'sent']));
		self::assertSame('open', $svc->classifyInvoiceRow(['overdue' => false, 'status' => 'sent']));
		self::assertSame('paid', $svc->classifyInvoiceRow(['status' => 'paid']));
	}

	public function testNextActionPrefersOverdueCallback(): void
	{
		$prio = new NextActionPriority();
		$today = new \DateTimeImmutable('2026-07-27');
		$pick = $prio->pick([
			[
				'kind' => 'deal_stale',
				'label' => 'Stale',
				'dueDate' => '2026-07-01',
				'targetType' => 'deal',
				'targetId' => 9,
			],
			[
				'kind' => 'callback_overdue',
				'label' => 'Call Ada',
				'dueDate' => '2026-07-20',
				'targetType' => 'activity',
				'targetId' => 55,
				'preferCall' => true,
			],
			[
				'kind' => 'renewal_urgent',
				'label' => 'Renew',
				'dueDate' => '2026-07-30',
				'targetType' => 'agreement',
				'targetId' => 3,
			],
		], $today);
		self::assertNotNull($pick);
		self::assertSame('callback_overdue', $pick['kind']);
		self::assertSame(55, $pick['target']['id']);
	}

	public function testNextActionTieBreakEarlierDueThenLowerId(): void
	{
		$prio = new NextActionPriority();
		$today = new \DateTimeImmutable('2026-07-27');
		$pick = $prio->pick([
			[
				'kind' => 'activity_due_today',
				'label' => 'B',
				'dueDate' => '2026-07-27',
				'targetType' => 'activity',
				'targetId' => 20,
			],
			[
				'kind' => 'activity_due_today',
				'label' => 'A',
				'dueDate' => '2026-07-27',
				'targetType' => 'activity',
				'targetId' => 10,
			],
		], $today);
		self::assertSame(10, $pick['target']['id']);
	}

	public function testFeedDedupePrefersEmailTouchWithSnippet(): void
	{
		$feed = new CommunicationFeedService();
		$merged = $feed->mergeAndSort([
			[
				'id' => 'mail_related:1',
				'kind' => 'mail_related',
				'occurredAt' => '2026-07-26T12:00:00+00:00',
				'subject' => 'Hi',
				'mailMessageId' => 'mid-1',
				'snippet' => '',
			],
			[
				'id' => 'email_touch:2',
				'kind' => 'email_touch',
				'occurredAt' => '2026-07-26T12:00:00+00:00',
				'subject' => 'Hi',
				'mailMessageId' => 'mid-1',
				'snippet' => 'Hello there',
			],
			[
				'id' => 'system:9',
				'kind' => 'system',
				'occurredAt' => '2026-07-27T12:00:00+00:00',
				'subject' => 'sys',
			],
		], false);
		self::assertCount(1, $merged);
		self::assertSame('email_touch:2', $merged[0]['id']);
	}

	public function testFeedSortNewestFirstAndPaginate(): void
	{
		$feed = new CommunicationFeedService();
		$merged = $feed->mergeAndSort([
			['id' => 'a:1', 'kind' => 'note', 'occurredAt' => '2026-07-20T10:00:00+00:00', 'subject' => 'old'],
			['id' => 'a:2', 'kind' => 'note', 'occurredAt' => '2026-07-26T10:00:00+00:00', 'subject' => 'new'],
		]);
		self::assertSame('a:2', $merged[0]['id']);
		$page = $feed->paginate($merged, 1, null);
		self::assertCount(1, $page['items']);
		self::assertNotNull($page['nextCursor']);
		$page2 = $feed->paginate($merged, 1, $page['nextCursor']);
		self::assertSame('a:1', $page2['items'][0]['id']);
		self::assertNull($page2['nextCursor']);
	}

	public function testLastTouchByPersonKeepsNewest(): void
	{
		$feed = new CommunicationFeedService();
		$best = $feed->lastTouchByPerson([
			['personId' => 9, 'occurredAt' => '2026-07-20T10:00:00+00:00', 'subject' => 'old'],
			['personId' => 9, 'occurredAt' => '2026-07-26T10:00:00+00:00', 'subject' => 'new'],
			['personId' => 0, 'occurredAt' => '2026-07-27T10:00:00+00:00', 'subject' => ' Ignored'],
		]);
		self::assertSame('new', $best[9]['subject']);
	}

	public function testContactsUidRejectsControlCharsAndTooLong(): void
	{
		$v = new ContactsUidValidator();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		self::assertNull($v->normalize('', $l));
		self::assertNull($v->normalize(null, $l));
		self::assertSame('abc-123', $v->normalize('abc-123', $l));
		$this->expectException(ValidationException::class);
		$v->normalize("bad\nuid", $l);
	}

	public function testContactsUidMaxLength(): void
	{
		$v = new ContactsUidValidator();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$this->expectException(ValidationException::class);
		$v->normalize(str_repeat('x', 256), $l);
	}
}
