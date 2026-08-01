<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ContactsSuggestService;
use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use PHPUnit\Framework\TestCase;

final class ContactsSuggestServiceTest extends TestCase
{
	public function testUnavailableWhenContactsAppDisabled(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('contacts')->willReturn(false);
		$contacts = $this->createMock(IManager::class);
		$contacts->expects($this->never())->method('search');

		$svc = new ContactsSuggestService($apps, $contacts);
		self::assertFalse($svc->isAvailable());
		self::assertSame([], $svc->suggest('Alice'));
	}

	public function testShortQueryReturnsEmpty(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$contacts = $this->createMock(IManager::class);
		$contacts->expects($this->never())->method('search');

		$svc = new ContactsSuggestService($apps, $contacts);
		self::assertSame([], $svc->suggest('A'));
	}

	public function testMapsFnEmailTelAndDedupes(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$contacts = $this->createMock(IManager::class);
		$contacts->method('search')->willReturn([
			['FN' => 'Alice Example', 'EMAIL' => 'alice@example.com', 'TEL' => '+49123', 'UID' => 'uid-alice'],
			['FN' => 'Alice Example', 'EMAIL' => 'alice@example.com', 'TEL' => '+49999', 'UID' => 'uid-alice'],
			['FN' => '', 'EMAIL' => 'orphan@example.com'],
		]);

		$svc = new ContactsSuggestService($apps, $contacts);
		self::assertTrue($svc->isAvailable());
		$rows = $svc->suggest('Ali', 10);
		self::assertCount(2, $rows);
		self::assertSame('Alice Example', $rows[0]['name']);
		self::assertSame('alice@example.com', $rows[0]['email']);
		self::assertSame('+49123', $rows[0]['phone']);
		self::assertSame('uid-alice', $rows[0]['uid']);
		self::assertSame('orphan@example.com', $rows[1]['name']);
		self::assertArrayHasKey('uid', $rows[1]);
	}

	public function testSearchFailureDegradesToEmpty(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$contacts = $this->createMock(IManager::class);
		$contacts->method('search')->willThrowException(new \RuntimeException('dav down'));

		$svc = new ContactsSuggestService($apps, $contacts);
		self::assertSame([], $svc->suggest('Alice'));
	}
}
