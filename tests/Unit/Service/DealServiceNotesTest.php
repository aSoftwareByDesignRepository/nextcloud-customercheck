<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
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
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Deal notes — create/update/toArray, empty→null, 4000-char hard limit, conflict snapshot.
 */
final class DealServiceNotesTest extends TestCase
{
	private function company(): Company
	{
		$c = new Company();
		$c->setId(4);
		$c->setWorkspaceId(1);
		$c->setName('Acme');
		return $c;
	}

	private function service(
		DealMapper $mapper,
		CompanyService $companies,
		?TimelineService $timeline = null,
		?StageSequenceService $sequences = null,
		?IDBConnection $db = null,
	): DealService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-09T12:00:00+00:00'));

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getSettings')->willReturn(['leadSlaHours' => 24]);

		if ($db === null) {
			$db = $this->createMock(IDBConnection::class);
			$db->method('inTransaction')->willReturn(false);
			$db->method('beginTransaction');
			$db->method('commit');
			$db->method('rollBack');
		}

		return new DealService(
			$mapper,
			$companies,
			$workspaces,
			$sequences ?? $this->createMock(StageSequenceService::class),
			$timeline ?? $this->createMock(TimelineService::class),
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$db,
			$this->createMock(AgreementMapper::class),
		);
	}

	public function testCreateStoresTrimmedNotesAndExportsThem(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(4)->willReturn($this->company());

		$mapper = $this->createMock(DealMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Deal $deal): Deal {
				TestCase::assertSame('Needs budget approval from CFO', $deal->getNotes());
				$deal->setId(99);
				return $deal;
			},
		);

		$sequences = $this->createMock(StageSequenceService::class);
		$sequences->method('fireForStage')->willReturn([]);
		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append');

		$svc = $this->service($mapper, $companies, $timeline, $sequences);
		$deal = $svc->create([
			'companyId' => 4,
			'title' => 'Renewal',
			'notes' => "  Needs budget approval from CFO  \n",
		], 'ae');

		$this->assertSame(99, (int)$deal->getId());
		$array = $svc->toArray($deal);
		$this->assertSame('Needs budget approval from CFO', $array['notes']);
	}

	public function testCreateBlankNotesBecomesNull(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($this->company());

		$mapper = $this->createMock(DealMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Deal $deal): Deal {
				TestCase::assertNull($deal->getNotes());
				$deal->setId(7);
				return $deal;
			},
		);

		$sequences = $this->createMock(StageSequenceService::class);
		$sequences->method('fireForStage')->willReturn([]);

		$svc = $this->service($mapper, $companies, null, $sequences);
		$deal = $svc->create([
			'companyId' => 4,
			'title' => 'Lead',
			'notes' => "   \t  ",
		], 'ae');
		$this->assertNull($deal->getNotes());
		$this->assertNull($svc->toArray($deal)['notes']);
	}

	public function testCreateRejectsNotesOver4000Characters(): void
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($this->company());
		$mapper = $this->createMock(DealMapper::class);
		$mapper->expects($this->never())->method('insert');

		$svc = $this->service($mapper, $companies);
		$this->expectException(ValidationException::class);
		$svc->create([
			'companyId' => 4,
			'title' => 'Huge',
			'notes' => str_repeat('x', 4001),
		], 'ae');
	}

	public function testUpdateNotesWithOptimisticLock(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(4);
		$deal->setTitle('Quote');
		$deal->setStage('quoted');
		$deal->setNotes('old');
		$deal->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Deal $d): Deal {
				TestCase::assertSame('new note', $d->getNotes());
				return $d;
			},
		);

		$companies = $this->createMock(CompanyService::class);
		$svc = $this->service($mapper, $companies);
		$out = $svc->update(5, [
			'notes' => 'new note',
			'updatedAt' => '2026-08-01T10:00:00+00:00',
		], 'ae');
		$this->assertSame('new note', $out->getNotes());
	}

	public function testUpdateClearsNotesWithEmptyString(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(4);
		$deal->setTitle('Quote');
		$deal->setStage('quoted');
		$deal->setNotes('keep?');
		$deal->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Deal $d): Deal {
				TestCase::assertNull($d->getNotes());
				return $d;
			},
		);

		$svc = $this->service($mapper, $this->createMock(CompanyService::class));
		$out = $svc->update(5, [
			'notes' => '',
			'updatedAt' => '2026-08-01T10:00:00+00:00',
		], 'ae');
		$this->assertNull($out->getNotes());
	}

	public function testConflictSnapshotIncludesNotes(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/DealService.php');
		$this->assertStringContainsString("'notes' => (string)(\$deal->getNotes() ?? '')", $src);
		$this->assertMatchesRegularExpression(
			"/foreach \(\['title', 'ownerUserId', 'valueCents', 'notes',/",
			$src,
		);
		$this->assertStringContainsString('private function normalizeNotes', $src);
		$this->assertStringContainsString('Keep the notes under 4000 characters.', $src);
	}
}
