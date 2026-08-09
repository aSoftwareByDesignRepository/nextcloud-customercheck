<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\HoursMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Company workspace move — retired in single-organisation mode.
 */
final class CompanyServiceMoveWorkspaceTest extends TestCase
{
	public function testMoveRejectedInSingleOrgMode(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('findById')->willReturn($company);

		$svc = $this->service($mapper);
		try {
			$svc->moveToWorkspace(7, 2, 'acme', 'mgr', '2026-08-01T10:00:00+00:00');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_WS_LIMIT, $e->getErrorCode());
			$this->assertArrayHasKey('workspaceId', $e->getFields());
		}
	}

	public function testGenericUpdateRejectsWorkspaceChange(): void
	{
		$company = new Company();
		$company->setId(7);
		$company->setSlug('acme');
		$company->setName('Acme');
		$company->setStatus('active');
		$company->setWorkspaceId(1);
		$company->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(CompanyMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($company);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->method('inTransaction')->willReturn(false);

		$svc = $this->service($mapper, $db);
		try {
			$svc->update(7, ['workspaceId' => 2], 'mgr', '2026-08-01T10:00:00+00:00');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('workspaceId', $e->getFields());
			$this->assertStringContainsString('single organisation', strtolower($e->getFields()['workspaceId']));
		}
	}

	private function service(CompanyMapper $mapper, ?IDBConnection $db = null): CompanyService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		$conn = $db ?? $this->createMock(IDBConnection::class);
		$conn->method('inTransaction')->willReturn(false);
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
			$conn,
			$this->createMock(ITimeFactory::class),
			$l10n,
		);
	}
}
