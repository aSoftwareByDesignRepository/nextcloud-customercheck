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
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCA\CustomerCheck\Service\LinkIdempotencyService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** @param array<string,mixed>|null $data */
final class FakeFacadeResultV2
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

	public static function fail(string $code, string $message = ''): self
	{
		return new self(false, $code, $message, []);
	}
}

/**
 * SHARED-IDENTITY W1 — rename push, idempotency, unlink.
 */
class SiblingCustomerWriteServiceIdentityTest extends TestCase
{
	private CompanyService&MockObject $companies;
	private CompanyMapper&MockObject $mapper;
	private FacadeProbeService&MockObject $facades;
	private TimelineService&MockObject $timeline;
	private PersonMapper&MockObject $persons;
	private LinkIdempotencyService&MockObject $idem;
	private IdentityLinkHealthService&MockObject $health;

	protected function setUp(): void
	{
		$this->companies = $this->createMock(CompanyService::class);
		$this->mapper = $this->createMock(CompanyMapper::class);
		$this->facades = $this->createMock(FacadeProbeService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->persons = $this->createMock(PersonMapper::class);
		$this->idem = $this->createMock(LinkIdempotencyService::class);
		$this->health = $this->createMock(IdentityLinkHealthService::class);
		$this->persons->method('findPrimary')->willReturn(null);
	}

	private const LOCK = '2026-08-07T12:00:00+00:00';

	private function makeService(?object $pcFacade = null): SiblingCustomerWriteService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => $s);

		return new SiblingCustomerWriteService(
			$this->companies,
			$this->mapper,
			$this->facades,
			$this->timeline,
			$this->persons,
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->idem,
			$this->health,
			$pcFacade !== null ? static fn () => $pcFacade : null,
			null,
			static fn (array $p) => (object)$p,
			null,
		);
	}

	private function company(int $id, string $name, ?int $pc = null): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setName($name);
		$c->setSlug('acme-' . $id);
		$c->setUpdatedAt(new \DateTime(self::LOCK));
		if ($pc !== null) {
			$c->setPcCustomerId($pc);
		}

		return $c;
	}

	public function testRenamePushCallsUpdateDisplayNameOnce(): void
	{
		$company = $this->company(17, 'Acme Neu', 42);
		$this->health->method('renamePushEnabled')->willReturn(true);
		$this->facades->method('projectcheckWriteAvailable')->willReturn(true);

		$pcFacade = new class {
			public int $calls = 0;
			public ?string $name = null;
			public function updateDisplayName(object $req): FakeFacadeResultV2
			{
				$this->calls++;
				$this->name = (string)($req->displayName ?? '');

				return FakeFacadeResultV2::success(['pcCustomerId' => 42]);
			}
		};

		$service = $this->makeService($pcFacade);
		$result = $service->pushDisplayNameAfterRename($company, 'alice', 'Acme Alt');
		$this->assertTrue($result['pushed']);
		$this->assertSame(1, $pcFacade->calls);
		$this->assertSame('Acme Neu', $pcFacade->name);
	}

	public function testRenamePushSkippedWhenFlagOff(): void
	{
		$company = $this->company(17, 'Acme Neu', 42);
		$this->health->method('renamePushEnabled')->willReturn(false);
		// If the flag gate were removed, write is available and the facade would be called.
		$this->facades->method('projectcheckWriteAvailable')->willReturn(true);
		$pcFacade = new class {
			public int $calls = 0;
			public function updateDisplayName(object $req): FakeFacadeResultV2
			{
				$this->calls++;

				return FakeFacadeResultV2::success([]);
			}
		};
		$service = $this->makeService($pcFacade);
		$result = $service->pushDisplayNameAfterRename($company, 'alice', 'Acme Alt');
		$this->assertTrue($result['skipped']);
		$this->assertFalse($result['pushed']);
		$this->assertNull($result['warning']);
		$this->assertSame(0, $pcFacade->calls);
	}

	public function testRenamePushKeepsCrmNameOnPermissionDenied(): void
	{
		$company = $this->company(17, 'Acme Neu', 42);
		$this->health->method('renamePushEnabled')->willReturn(true);
		$this->facades->method('projectcheckWriteAvailable')->willReturn(true);
		$this->timeline->expects($this->once())->method('append');
		$pcFacade = new class {
			public function updateDisplayName(object $req): FakeFacadeResultV2
			{
				return FakeFacadeResultV2::fail('permission_denied', 'nope');
			}
		};
		$service = $this->makeService($pcFacade);
		$result = $service->pushDisplayNameAfterRename($company, 'alice', 'Acme Alt');
		$this->assertFalse($result['pushed']);
		$this->assertNotNull($result['warning']);
		$this->assertSame('permission_denied', $result['code']);
	}

	public function testIdempotentReplayReturnsSamePcId(): void
	{
		$company = $this->company(17, 'Acme');
		$this->companies->method('get')->willReturn($company);
		$this->idem->method('findReplay')->willReturn([
			'companyId' => 17,
			'siblingId' => 99,
			'created' => true,
		]);
		$service = $this->makeService();
		$result = $service->createOrLinkPcCustomer(17, 'alice', null, self::LOCK, 'key-1');
		$this->assertSame(99, $result['pcCustomerId']);
		$this->assertTrue($result['idempotentReplay']);
	}

	public function testUnlinkClearsPcId(): void
	{
		$company = $this->company(17, 'Acme', 42);
		$updated = $this->company(17, 'Acme', null);
		$this->companies->method('get')->willReturn($company);
		$this->companies->expects($this->once())
			->method('update')
			->with(17, ['pcCustomerId' => null], 'alice', self::LOCK)
			->willReturn($updated);
		$this->timeline->expects($this->once())->method('append');
		$service = $this->makeService();
		$out = $service->unlinkPcCustomer(17, 'alice', self::LOCK);
		$this->assertNull($out->getPcCustomerId());
	}
}
