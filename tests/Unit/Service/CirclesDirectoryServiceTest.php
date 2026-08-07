<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\CirclesDirectoryService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CirclesDirectoryServiceTest extends TestCase
{
	public function testUnavailableWhenCirclesDisabled(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('circles')->willReturn(false);
		$svc = new CirclesDirectoryService($apps, $this->createMock(LoggerInterface::class));
		$this->assertFalse($svc->isAvailable());
		$this->assertSame([], $svc->availableCirclesForUser('admin'));
		$empty = $svc->localUserIdsInCircle('x', 'admin');
		$this->assertSame([], $empty['userIds']);
	}

	public function testBlankActorYieldsEmptyListEvenIfCirclesOn(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$svc = new CirclesDirectoryService($apps, $this->createMock(LoggerInterface::class));
		// Without Circles classes loaded in unit isolation, isAvailable may still be true
		// if Circles is installed in the tree — still must not crash on blank actor.
		$this->assertSame([], $svc->availableCirclesForUser(''));
		$this->assertSame([], $svc->availableCirclesForUser('   '));
	}
}
