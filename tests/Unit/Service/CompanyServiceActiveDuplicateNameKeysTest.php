<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class CompanyServiceActiveDuplicateNameKeysTest extends TestCase
{
	public function testDelegatesPositiveWorkspaceIdToMapper(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->expects($this->once())
			->method('activeDuplicateNameKeys')
			->with(7)
			->willReturn(['acme gmbh' => true]);

		$svc = $this->service($mapper);
		$this->assertSame(['acme gmbh' => true], $svc->activeDuplicateNameKeys(7));
	}

	public function testRejectsNonPositiveWorkspaceWithoutMapperCall(): void
	{
		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->expects($this->never())->method('activeDuplicateNameKeys');

		$svc = $this->service($mapper);
		$this->assertSame([], $svc->activeDuplicateNameKeys(0));
		$this->assertSame([], $svc->activeDuplicateNameKeys(-3));
	}

	private function service(CompanyMapper $mapper): CompanyService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);

		return new CompanyService(
			$mapper,
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(DealMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(HoursMapper::class),
			$this->createMock(EmailTouchMapper::class),
			$this->createMock(TimelineEventMapper::class),
			$this->createMock(SlugService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);
	}
}
