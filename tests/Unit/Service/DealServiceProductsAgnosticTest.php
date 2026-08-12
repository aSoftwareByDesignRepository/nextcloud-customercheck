<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
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

/**
 * Deals accept hardware / physical goods / mixed carts via free-text products (AC-F-10).
 */
final class DealServiceProductsAgnosticTest extends TestCase
{
	private function company(): Company
	{
		$c = new Company();
		$c->setId(9);
		$c->setWorkspaceId(2);
		$c->setName('Goods Co');

		return $c;
	}

	private function service(DealMapper $mapper, CompanyService $companies): DealService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-11T12:00:00+00:00'));

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getSettings')->willReturn(['leadSlaHours' => 24]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);

		$sequences = $this->createMock(StageSequenceService::class);
		$sequences->method('fireForStage')->willReturn([]);

		return new DealService(
			$mapper,
			$companies,
			$workspaces,
			$sequences,
			$this->createMock(TimelineService::class),
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$db,
			$this->createMock(AgreementMapper::class),
		);
	}

	public function testCreateAcceptsHardwareAndMixedProductsText(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(9)->willReturn($this->company());

		$mapper = $this->createMock(DealMapper::class);
		$mapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (Deal $deal): bool {
				return $deal->getTitle() === 'AP kit for Site B'
					&& $deal->getProducts() === '3× AP + mount kit + 1d install';
			}))
			->willReturnCallback(static function (Deal $deal): Deal {
				$deal->setId(77);

				return $deal;
			});

		$svc = $this->service($mapper, $companies);
		$out = $svc->create([
			'companyId' => 9,
			'title' => 'AP kit for Site B',
			'products' => "  3× AP + mount kit + 1d install\n",
			'stage' => 'lead',
		], 'alex');

		$this->assertSame(77, (int)$out->getId());
		$this->assertSame('3× AP + mount kit + 1d install', $out->getProducts());
	}

	public function testCreateDoesNotRequireProductsField(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(9)->willReturn($this->company());

		$mapper = $this->createMock(DealMapper::class);
		$mapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (Deal $deal): bool {
				return $deal->getProducts() === null;
			}))
			->willReturnCallback(static function (Deal $deal): Deal {
				$deal->setId(78);

				return $deal;
			});

		$svc = $this->service($mapper, $companies);
		$out = $svc->create([
			'companyId' => 9,
			'title' => 'TBD cart',
			'stage' => 'lead',
		], 'alex');

		$this->assertNull($out->getProducts());
	}
}
