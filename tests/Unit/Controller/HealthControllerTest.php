<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\HealthController;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class HealthControllerTest extends TestCase
{
	public function testHealthyWhenCoreTableExists(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('crm_company')->willReturn(true);
		$ctrl = new HealthController(
			'customercheck',
			$this->createMock(IRequest::class),
			$db,
			$this->createMock(LoggerInterface::class),
		);
		$response = $ctrl->check();
		self::assertSame(200, $response->getStatus());
		$body = $response->getData();
		self::assertSame('healthy', $body['status']);
		self::assertSame('customercheck', $body['app']);
	}

	public function testDegradedWhenSchemaMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$ctrl = new HealthController(
			'customercheck',
			$this->createMock(IRequest::class),
			$db,
			$this->createMock(LoggerInterface::class),
		);
		$response = $ctrl->check();
		self::assertSame(503, $response->getStatus());
		self::assertSame('degraded', $response->getData()['status']);
	}
}
