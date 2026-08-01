<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class DealServiceAgreementFkTest extends TestCase
{
	public function testUpdateRejectsForeignAgreementId(): void
	{
		$deal = new Deal();
		$deal->setId(3);
		$deal->setCompanyId(8);
		$deal->setTitle('Quote');
		$deal->setStage('quoted');
		$deal->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->never())->method('update');

		$foreign = new Agreement();
		$foreign->setId(44);
		$foreign->setCompanyId(99);
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findById')->with(44)->willReturn($foreign);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(StageSequenceService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			$db,
			$agreements,
		);

		try {
			$svc->update(3, [
				'agreementId' => 44,
				'updatedAt' => '2026-07-20T10:00:00+00:00',
			], 'ae');
			$this->fail('foreign agreement must fail');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('agreementId', $e->getFields());
		}
	}
}
