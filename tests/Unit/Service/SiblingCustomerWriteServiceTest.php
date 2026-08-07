<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** @param array<string,mixed>|null $data */
final class FakeFacadeResult
{
	public function __construct(
		public readonly bool $ok,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?array $data = null,
	) {
	}

	/** @param array<string,mixed> $data */
	public static function success(array $data): self
	{
		return new self(true, null, null, $data);
	}
}

/**
 * CHECK-SUITE AC-S1.2 — create/link orchestration (no cross-app TX).
 */
class SiblingCustomerWriteServiceTest extends TestCase
{
	private CompanyService&MockObject $companies;
	private CompanyMapper&MockObject $mapper;
	private FacadeProbeService&MockObject $facades;
	private TimelineService&MockObject $timeline;
	private PersonMapper&MockObject $persons;

	protected function setUp(): void
	{
		$this->companies = $this->createMock(CompanyService::class);
		$this->mapper = $this->createMock(CompanyMapper::class);
		$this->facades = $this->createMock(FacadeProbeService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->persons = $this->createMock(PersonMapper::class);
		$this->persons->method('findPrimary')->willReturn(null);
	}

	private const LOCK = '2026-07-26T12:00:00+00:00';

	public function testCreatePcFailsWhenWriteUnavailable(): void
	{
		$company = $this->company(17, 'Acme');
		$this->companies->method('get')->with(17)->willReturn($company);
		$this->facades->method('projectcheckWriteAvailable')->willReturn(false);

		$service = $this->makeService();
		$this->expectException(ValidationException::class);
		$service->createOrLinkPcCustomer(17, 'alice', null, self::LOCK);
	}

	public function testCreatePcSuccess(): void
	{
		$company = $this->company(17, 'Acme GmbH');
		$updated = $this->company(17, 'Acme GmbH');
		$updated->setPcCustomerId(42);

		$this->companies->method('get')->willReturn($company);
		$this->facades->method('projectcheckWriteAvailable')->willReturn(true);
		$this->mapper->method('findByPcCustomerId')->willReturn(null);
		$this->companies->expects($this->once())
			->method('update')
			->with(17, ['pcCustomerId' => 42], 'alice', self::LOCK)
			->willReturn($updated);
		$this->timeline->expects($this->once())->method('append');

		$pcFacade = new class {
			public function createCustomer(object $req): FakeFacadeResult
			{
				return FakeFacadeResult::success([
					'pcCustomerId' => 42,
					'displayName' => 'Acme GmbH',
					'created' => true,
				]);
			}

			public function ensureLink(object $req): FakeFacadeResult
			{
				throw new \RuntimeException('unexpected');
			}
		};

		$service = $this->makeService(fn () => $pcFacade, static fn (array $p): object => (object)$p);
		$result = $service->createOrLinkPcCustomer(17, 'alice', null, self::LOCK);

		$this->assertSame(42, $result['pcCustomerId']);
		$this->assertTrue($result['created']);
	}

	public function testCreatePcIdempotentWhenAlreadyLinked(): void
	{
		$company = $this->company(17, 'Acme GmbH');
		$company->setPcCustomerId(42);

		$this->companies->method('get')->willReturn($company);
		$this->facades->method('projectcheckWriteAvailable')->willReturn(true);
		$this->mapper->method('findByPcCustomerId')->willReturn($company);
		$this->companies->expects($this->never())->method('update');
		$this->timeline->expects($this->never())->method('append');

		$pcFacade = new class {
			public function createCustomer(object $req): FakeFacadeResult
			{
				throw new \RuntimeException('must not create again');
			}

			public function ensureLink(object $req): FakeFacadeResult
			{
				return FakeFacadeResult::success([
					'pcCustomerId' => 42,
					'displayName' => 'Acme GmbH',
					'created' => false,
				]);
			}
		};

		$service = $this->makeService(fn () => $pcFacade, static fn (array $p): object => (object)$p);
		$result = $service->createOrLinkPcCustomer(17, 'alice', null, self::LOCK);

		$this->assertSame(42, $result['pcCustomerId']);
		$this->assertFalse($result['created']);
	}

	public function testEnsureLinkConflict(): void
	{
		$company = $this->company(17, 'Acme');
		$this->companies->method('get')->willReturn($company);
		$this->facades->method('projectcheckWriteAvailable')->willReturn(true);
		$other = $this->company(99, 'Other');
		$this->mapper->method('findByPcCustomerId')->with(42)->willReturn($other);

		$service = $this->makeService(
			fn () => new class {
				public function ensureLink(object $req): FakeFacadeResult
				{
					throw new \RuntimeException('should not call facade');
				}
			},
			static fn (array $p): object => (object)$p,
		);

		$this->expectException(ConflictException::class);
		$service->createOrLinkPcCustomer(17, 'alice', 42, self::LOCK);
	}

	public function testDuplicateNamePropagatesExistingPcCustomerId(): void
	{
		$company = $this->company(17, 'Acme GmbH');
		$this->companies->method('get')->willReturn($company);
		$this->facades->method('projectcheckWriteAvailable')->willReturn(true);

		$pcFacade = new class {
			public function createCustomer(object $req): FakeFacadeResult
			{
				return new FakeFacadeResult(false, 'duplicate_name', 'exists', [
					'existingPcCustomerId' => 77,
				]);
			}
		};

		$service = $this->makeService(fn () => $pcFacade, static fn (array $p): object => (object)$p);
		try {
			$service->createOrLinkPcCustomer(17, 'alice', null, self::LOCK);
			$this->fail('expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame(Constants::ERR_LINK_CONFLICT, $e->getErrorCode());
			$this->assertSame(77, $e->getFields()['existingPcCustomerId']);
		}
	}

	public function testErrorConstants(): void
	{
		$this->assertSame('LINK_CONFLICT', Constants::ERR_LINK_CONFLICT);
		$this->assertSame('SIBLING_UNAVAILABLE', Constants::ERR_SIBLING_UNAVAILABLE);
	}

	/**
	 * @param callable():object|null $pcFactory
	 * @param callable(array):object|null $pcRequestFactory
	 */
	private function makeService($pcFactory = null, $pcRequestFactory = null): SiblingCustomerWriteService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		return new SiblingCustomerWriteService(
			$this->companies,
			$this->mapper,
			$this->facades,
			$this->timeline,
			$this->persons,
			$l10n,
			$this->createMock(LoggerInterface::class),
			null,
			null,
			$pcFactory,
			null,
			$pcRequestFactory,
			null,
		);
	}

	private function company(int $id, string $name): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setName($name);
		$c->setSlug('acme-gmbh');
		$c->setUpdatedAt(new \DateTime('2026-07-26T12:00:00+00:00'));
		return $c;
	}
}
