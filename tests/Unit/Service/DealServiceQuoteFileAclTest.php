<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * quoteFileId must resolve via actor Files ACL — never raw filecache trust.
 */
final class DealServiceQuoteFileAclTest extends TestCase
{
	private function baseDeal(): Deal
	{
		$d = new Deal();
		$d->setId(3);
		$d->setCompanyId(8);
		$d->setTitle('Quote');
		$d->setStage('quoted');
		$d->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));
		return $d;
	}

	public function testQuoteFileIdRequiresReadablePdf(): void
	{
		$deal = $this->baseDeal();
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->never())->method('update');

		$files = $this->createMock(FilesAccessService::class);
		$files->expects($this->once())
			->method('resolveReadableFile')
			->with('ae', 99, Constants::QUOTE_FILE_ALLOWED_MIMES, Constants::QUOTE_FILE_MAX_BYTES)
			->willThrowException(new ValidationException(
				Constants::ERR_FILES_NOT_FOUND,
				'That file is gone or you cannot read it.',
			));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(StageSequenceService::class),
			$this->createMock(TimelineService::class),
			$files,
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(AgreementMapper::class),
		);

		try {
			$svc->update(3, [
				'quoteFileId' => 99,
				'updatedAt' => '2026-07-20T10:00:00+00:00',
			], 'ae');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_FILES_NOT_FOUND, $e->getErrorCode());
		}
	}

	public function testQuoteFileIdClearsWithoutAclWhenNull(): void
	{
		$deal = $this->baseDeal();
		$deal->setQuoteFileId(44);
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Deal $d): Deal {
				return $d;
			},
		);

		$files = $this->createMock(FilesAccessService::class);
		$files->expects($this->never())->method('resolveReadableFile');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(StageSequenceService::class),
			$this->createMock(TimelineService::class),
			$files,
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(AgreementMapper::class),
		);

		$result = $svc->update(3, [
			'quoteFileId' => null,
			'updatedAt' => '2026-07-20T10:00:00+00:00',
		], 'ae');
		$this->assertNull($result->getQuoteFileId());
	}

	public function testQuoteFileIdPersistsAfterAcl(): void
	{
		$deal = $this->baseDeal();
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Deal $d): Deal {
				return $d;
			},
		);

		$files = $this->createMock(FilesAccessService::class);
		$files->expects($this->once())
			->method('resolveReadableFile')
			->with('ae', 77, Constants::QUOTE_FILE_ALLOWED_MIMES, Constants::QUOTE_FILE_MAX_BYTES)
			->willReturn($this->createMock(File::class));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(StageSequenceService::class),
			$this->createMock(TimelineService::class),
			$files,
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(AgreementMapper::class),
		);

		$result = $svc->update(3, [
			'quoteFileId' => 77,
			'updatedAt' => '2026-07-20T10:00:00+00:00',
		], 'ae');
		$this->assertSame(77, $result->getQuoteFileId());
	}
}
