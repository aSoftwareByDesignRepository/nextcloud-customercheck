<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Notification;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

final class NotifierUnknownAppTest extends TestCase
{
	public function testWrongAppThrowsUnknownNotificationException(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);

		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('files');

		$notifier = new Notifier($factory, $url);
		$this->expectException(UnknownNotificationException::class);
		$notifier->prepare($n, 'en');
	}

	public function testOwnAppDoesNotThrowUnknownForKnownSubject(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/today');

		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn(Application::APP_ID);
		$n->method('getSubject')->willReturn(Constants::NOTIFY_DIGEST);
		$n->method('getSubjectParameters')->willReturn([
			'renewals' => 1,
			'overdue' => 2,
			'unpaid' => 3,
			'quota80' => 0,
		]);
		$n->expects($this->once())->method('setParsedSubject')->willReturnSelf();
		$n->expects($this->once())->method('setLink')->willReturnSelf();

		$notifier = new Notifier($factory, $url);
		$result = $notifier->prepare($n, 'en');
		$this->assertSame($n, $result);
	}

	public function testEditConflictDeepLinksToConflictsPage(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);
		$url->expects($this->once())->method('linkToRouteAbsolute')
			->with('customercheck.page.conflicts', ['workspaceId' => 7, 'conflictId' => 42])
			->willReturn('https://example.test/conflicts?workspaceId=7&conflictId=42');

		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn(Application::APP_ID);
		$n->method('getSubject')->willReturn(Constants::NOTIFY_EDIT_CONFLICT);
		$n->method('getSubjectParameters')->willReturn([
			'conflictId' => 42,
			'entityType' => 'company',
			'entityId' => 9,
			'workspaceId' => 7,
		]);
		$n->expects($this->once())->method('setParsedSubject')->willReturnSelf();
		$n->expects($this->once())->method('setLink')
			->with('https://example.test/conflicts?workspaceId=7&conflictId=42')
			->willReturnSelf();

		$notifier = new Notifier($factory, $url);
		$this->assertSame($n, $notifier->prepare($n, 'en'));
	}
}
