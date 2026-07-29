<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\TasksDeepLinkBuilder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class TasksDeepLinkBuilderTest extends TestCase
{
	public function testKnownUriBuildsSpecificPath(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.test' . $path,
		);
		$url->method('linkToRouteAbsolute')->willReturn('');
		$b = new TasksDeepLinkBuilder($url);
		$r = $b->fromTodoUri('personal/crm-act-55.ics');
		self::assertTrue($r['specific']);
		self::assertStringContainsString('/apps/tasks/calendars/', $r['url']);
		self::assertStringContainsString('crm-act-55.ics', $r['url']);
	}

	public function testGarbageUriFallsBackToHome(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.test' . $path,
		);
		$url->method('linkToRouteAbsolute')->willThrowException(new \RuntimeException('no route'));
		$b = new TasksDeepLinkBuilder($url);
		$r = $b->fromTodoUri('not-a-uri');
		self::assertFalse($r['specific']);
		self::assertStringContainsString('/apps/tasks/', $r['url']);
	}
}
