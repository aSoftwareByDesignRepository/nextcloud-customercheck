<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Support\DateMath;
use PHPUnit\Framework\TestCase;

/**
 * Archive contract (PSD §4.1): an archived company keeps its history but
 * drops out of every DEFAULT list — Today queue sources, renewal candidates
 * (reminder job), pipeline, agreements, activities — while explicit company
 * context (companyId filter / 360 page) still reaches everything.
 *
 * Asserted at the mapper level (where the exclusion lives) so the test is
 * immune to Today-queue truncation and unrelated fixture noise.
 */
class ArchivedCompanyExclusionIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	private ?int $companyId = null;
	private int $workspaceId = 0;
	private string $slug = '';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
	}

	protected function tearDown(): void
	{
		if ($this->companyId !== null) {
			try {
				$this->service(CompanyService::class)
					->gdprErase($this->companyId, $this->slug, self::ACTOR);
			} catch (\Throwable) {
				// best effort cleanup
			}
			$this->companyId = null;
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function service(string $class): object
	{
		return \OC::$server->get($class);
	}

	public function testArchivedCompanyDropsOutOfAllDefaultListsButKeepsHistory(): void
	{
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$this->slug = 'arch-excl-' . $suffix;

		$companies = $this->service(CompanyService::class);
		$company = $companies->create([
			'name' => 'Arch Excl ' . $suffix,
			'slug' => $this->slug,
		], self::ACTOR);
		$this->companyId = (int)$company->getId();
		$this->workspaceId = (int)($company->getWorkspaceId() ?? 0);
		self::assertGreaterThan(0, $this->workspaceId, 'fixture company must live in a real workspace');

		$agreement = $this->service(AgreementService::class)->create([
			'companyId' => $this->companyId,
			'kind' => 'license',
			'productCode' => 'azc',
			'label' => 'Arch Excl License ' . $suffix,
			'startsAt' => (new \DateTimeImmutable('-300 days'))->format('Y-m-d'),
			'validUntil' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
			'status' => 'active',
			'limits' => ['mobileSeats' => 3, 'terminalDevices' => 0],
		], self::ACTOR);
		$agreementId = (int)$agreement->getId();

		$activity = $this->service(ActivityService::class)->create([
			'companyId' => $this->companyId,
			'type' => 'call',
			'title' => 'Arch Excl Call ' . $suffix,
			'dueDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
		], self::ACTOR);
		$activityId = (int)$activity->getId();

		$deal = $this->service(DealService::class)->create([
			'companyId' => $this->companyId,
			'title' => 'Arch Excl Deal ' . $suffix,
			'stage' => 'lead',
			'valueCents' => 1000,
		], self::ACTOR);
		$dealId = (int)$deal->getId();

		// Make the deal stale so it qualifies for the stale-deal queue.
		$dealMapper = $this->service(DealMapper::class);
		$stored = $dealMapper->findById($dealId);
		$stored->setStageChangedAt(new \DateTime('-100 days'));
		$dealMapper->update($stored);

		// ── Active: everything surfaces in the default queues ────────────
		self::assertContains($agreementId, $this->renewalCandidateIds());
		self::assertContains($activityId, $this->openDueActivityIds());
		self::assertContains($dealId, $this->staleDealIds());
		self::assertContains($agreementId, $this->defaultAgreementSearchIds());
		self::assertContains($dealId, $this->defaultDealSearchIds());
		self::assertContains($activityId, $this->defaultActivitySearchIds());
		self::assertSame([], $this->unscopedCompanySearch(), 'unscoped company search must be empty');
		self::assertSame([], $this->unscopedAgreementSearch(), 'unscoped agreement search must be empty');
		self::assertSame([], $this->unscopedDealSearch(), 'unscoped deal search must be empty');
		self::assertSame([], $this->unscopedActivitySearch(), 'unscoped activity search must be empty');
		self::assertSame([], $this->unscopedPersonSearch(), 'unscoped person search must be empty');

		// ── Archived: everything drops out of the default queues ─────────
		$companies->archive($this->companyId, self::ACTOR, null);

		self::assertNotContains($agreementId, $this->renewalCandidateIds(), 'reminder job must skip archived companies');
		self::assertNotContains($activityId, $this->openDueActivityIds(), 'Today activities must skip archived companies');
		self::assertNotContains($dealId, $this->staleDealIds(), 'stale-deal queue must skip archived companies');
		self::assertNotContains($agreementId, $this->defaultAgreementSearchIds(), 'agreements list must skip archived companies');
		self::assertNotContains($dealId, $this->defaultDealSearchIds(), 'pipeline must skip archived companies');
		self::assertNotContains($activityId, $this->defaultActivitySearchIds(), 'activities list must skip archived companies');

		// …while explicit company context still reaches the history (360 page).
		$scopedAgreements = array_map(
			static fn ($a): int => (int)$a->getId(),
			$this->service(AgreementMapper::class)->search(['companyId' => $this->companyId], 100),
		);
		self::assertContains($agreementId, $scopedAgreements);

		$scopedDeals = array_map(
			static fn (Deal $d): int => (int)$d->getId(),
			$dealMapper->search(['companyId' => $this->companyId], 100),
		);
		self::assertContains($dealId, $scopedDeals);

		$scopedActivities = array_map(
			static fn ($a): int => (int)$a->getId(),
			$this->service(ActivityMapper::class)->search(['companyId' => $this->companyId], 100),
		);
		self::assertContains($activityId, $scopedActivities);

		// ── Restored: the work items come back ───────────────────────────
		$companies->restore($this->companyId, self::ACTOR, null);
		self::assertContains($agreementId, $this->renewalCandidateIds());
		self::assertContains($activityId, $this->openDueActivityIds());
		self::assertContains($dealId, $this->staleDealIds());
	}

	/** @return list<int> */
	private function renewalCandidateIds(): array
	{
		$ids = [];
		$offset = 0;
		do {
			$chunk = $this->service(AgreementMapper::class)->findRenewalCandidates(1000, $offset);
			foreach ($chunk as $agreement) {
				$ids[] = (int)$agreement->getId();
			}
			$offset += 1000;
		} while (count($chunk) === 1000);

		return $ids;
	}

	/** @return list<int> */
	private function openDueActivityIds(): array
	{
		return array_map(
			static fn ($a): int => (int)$a->getId(),
			$this->service(ActivityMapper::class)->findOpenDueUpTo(
				DateMath::toUtcMidnight(new \DateTimeImmutable('today')),
				5000,
			),
		);
	}

	/** @return list<int> */
	private function staleDealIds(): array
	{
		return array_map(
			static fn (Deal $d): int => (int)$d->getId(),
			$this->service(DealMapper::class)->findOpenStaleBefore(
				new \DateTimeImmutable('-14 days'),
				500,
			),
		);
	}

	/** @return list<int> */
	private function defaultAgreementSearchIds(): array
	{
		return array_map(
			static fn ($a): int => (int)$a->getId(),
			$this->service(AgreementMapper::class)->search(['workspaceId' => $this->workspaceId], 5000),
		);
	}

	/** @return list<int> */
	private function defaultDealSearchIds(): array
	{
		return array_map(
			static fn (Deal $d): int => (int)$d->getId(),
			$this->service(DealMapper::class)->search(['openOnly' => true, 'workspaceId' => $this->workspaceId], 5000),
		);
	}

	/** @return list<int> */
	private function defaultActivitySearchIds(): array
	{
		return array_map(
			static fn ($a): int => (int)$a->getId(),
			$this->service(ActivityMapper::class)->search(['openOnly' => true, 'workspaceId' => $this->workspaceId], 5000),
		);
	}

	/** @return list<mixed> */
	private function unscopedCompanySearch(): array
	{
		return $this->service(CompanyMapper::class)->search([], 5000);
	}

	/** @return list<mixed> */
	private function unscopedAgreementSearch(): array
	{
		return $this->service(AgreementMapper::class)->search([], 5000);
	}

	/** @return list<mixed> */
	private function unscopedDealSearch(): array
	{
		return $this->service(DealMapper::class)->search(['openOnly' => true], 5000);
	}

	/** @return list<mixed> */
	private function unscopedActivitySearch(): array
	{
		return $this->service(ActivityMapper::class)->search(['openOnly' => true], 5000);
	}

	/** @return list<mixed> */
	private function unscopedPersonSearch(): array
	{
		return $this->service(PersonMapper::class)->search(['search' => 'Arch Excl'], 5000);
	}
}
