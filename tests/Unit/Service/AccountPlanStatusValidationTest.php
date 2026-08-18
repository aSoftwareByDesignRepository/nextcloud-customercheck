<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Strategy upsert must reject unknown status values (fail closed).
 */
final class AccountPlanStatusValidationTest extends TestCase
{
	public function testInvalidStatusIsRejectedBeforeWrite(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setWorkspaceId(1);
		$company->setName('Acme');

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$companies->expects($this->never())->method('lockRow');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('beginTransaction');
		$db->method('tableExists')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$labels = new DisplayLabelService($l10n);
		$coverage = $this->createMock(CoverageService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-13T12:00:00+00:00'));
		$directory = $this->createMock(DirectoryOptionsService::class);
		$directory->method('resolveUsers')->willReturn([]);

		$svc = new AccountPlanService($db, $companies, $coverage, $labels, $time, $l10n, $directory);

		try {
			$svc->upsert(4, [
				'goal' => 'Grow',
				'status' => 'deleted_by_attacker',
				'updatedAt' => '2026-08-01T10:00:00+00:00',
			], 'mgr');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('Unknown plan status.', $e->getMessage());
		}
	}

	public function testHealthOrderingPrefersAtRiskThenStalled(): void
	{
		$this->assertSame('at_risk', AccountPlanService::health([
			'nextAssignOn' => '2099-01-01',
			'renewalsHot' => true,
		]));
		$this->assertSame('stalled', AccountPlanService::health([
			'nextAssignOn' => '2099-01-01',
			'touchOverdue' => true,
		]));
		$this->assertSame('on_track', AccountPlanService::health([
			'nextAssignOn' => '2099-01-01',
		]));
		$this->assertSame('unknown', AccountPlanService::health([]));
	}

	public function testInvalidNextAssignOnIsRejectedBeforeWrite(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setWorkspaceId(1);
		$company->setName('Acme');

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$companies->expects($this->never())->method('lockRow');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('beginTransaction');
		$db->method('tableExists')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$labels = new DisplayLabelService($l10n);
		$coverage = $this->createMock(CoverageService::class);
		$time = $this->createMock(ITimeFactory::class);
		$directory = $this->createMock(DirectoryOptionsService::class);

		$svc = new AccountPlanService($db, $companies, $coverage, $labels, $time, $l10n, $directory);

		try {
			$svc->upsert(4, [
				'goal' => 'Grow',
				'nextAssignOn' => '18.08.2026',
				'updatedAt' => '2026-08-01T10:00:00+00:00',
			], 'mgr');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('Use YYYY-MM-DD.', $e->getMessage());
			$this->assertArrayHasKey('nextAssignOn', $e->getFields());
		}
	}

	public function testCalendarDayFromStorageNormalizesDriverNoise(): void
	{
		$this->assertSame('2026-08-15', AccountPlanService::calendarDayFromStorage('2026-08-15'));
		$this->assertSame('2026-08-15', AccountPlanService::calendarDayFromStorage('2026-08-15 00:00:00'));
		$this->assertSame('2026-08-15', AccountPlanService::calendarDayFromStorage('2026-08-15T00:00:00+00:00'));
		$this->assertSame('2026-08-15', AccountPlanService::calendarDayFromStorage(new \DateTimeImmutable('2026-08-15T00:00:00+00:00')));
		$this->assertNull(AccountPlanService::calendarDayFromStorage('18.08.2026'));
		$this->assertNull(AccountPlanService::calendarDayFromStorage('2026-02-30'));
		$this->assertNull(AccountPlanService::calendarDayFromStorage(''));
		$this->assertNull(AccountPlanService::calendarDayFromStorage(null));
	}

	public function testStampToWallSqlKeepsAtomUtcWallClock(): void
	{
		$this->assertSame('2026-08-18 17:34:08', AccountPlanService::stampToWallSql('2026-08-18T17:34:08+00:00'));
		$this->assertSame('2026-08-18 17:34:08', AccountPlanService::stampToWallSql('2026-08-18 17:34:08'));
		$this->assertNull(AccountPlanService::stampToWallSql('not-a-stamp'));
	}

	public function testContractKeepsNormalizeAndSortHelpers(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccountPlanService.php');
		$this->assertStringContainsString('normalizePlanStatus', $src);
		$this->assertStringContainsString('sortPlansForBoard', $src);
		$this->assertStringContainsString('calendarDayFromStorage', $src);
		$this->assertStringContainsString('fetchPlanUpdatedAt', $src);
		$this->assertStringContainsString('stampToWallSql', $src);
		$this->assertStringContainsString('if ($expectedSql !== null && $expectedSql === $storedSql)', $src);
		$this->assertStringContainsString("'at_risk' => 0", $src);
		$this->assertStringContainsString("'stalled' => 1", $src);
	}

	public function testPlanLabelsCoverPriorityAndStatus(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$labels = new DisplayLabelService($l10n);
		$this->assertSame('High', $labels->planPriority('p1'));
		$this->assertSame('Normal', $labels->planPriority('p2'));
		$this->assertSame('Low', $labels->planPriority('p3'));
		$this->assertSame('Active', $labels->planStatus('active'));
		$this->assertSame('Paused', $labels->planStatus('paused'));
		$this->assertSame('Done', $labels->planStatus('done'));
	}
}
