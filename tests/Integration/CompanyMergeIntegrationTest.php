<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyMergeService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Support\OptimisticLock;
use PHPUnit\Framework\TestCase;

/**
 * WP-S1-CUC-MERGE — live DB merge + rollback on sibling link conflict.
 */
final class CompanyMergeIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	/** @var list<array{id: int, slug: string}> */
	private array $cleanup = [];

	private string $suffix = '';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
		$this->suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$this->cleanup = [];
	}

	protected function tearDown(): void
	{
		if ($this->cleanup === [] || !isset(\OC::$server)) {
			return;
		}
		$companies = \OC::$server->get(CompanyService::class);
		foreach (array_reverse($this->cleanup) as $row) {
			try {
				$companies->gdprErase($row['id'], $row['slug'], self::ACTOR);
			} catch (\Throwable) {
				// best effort
			}
		}
		$this->cleanup = [];
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

	private function track(object $company): void
	{
		$this->cleanup[] = [
			'id' => (int)$company->getId(),
			'slug' => (string)$company->getSlug(),
		];
	}

	public function testMergeMovesPersonsAndArchivesSource(): void
	{
		$companies = $this->service(CompanyService::class);
		$persons = $this->service(PersonService::class);
		$merges = $this->service(CompanyMergeService::class);

		$keeper = $companies->create([
			'name' => 'Keep ' . $this->suffix,
			'slug' => 'keep-' . $this->suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$this->track($keeper);
		$source = $companies->create([
			'name' => 'Dup ' . $this->suffix,
			'slug' => 'dup-' . $this->suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'lead',
		], self::ACTOR);
		$this->track($source);
		$person = $persons->create((int)$source->getId(), [
			'name' => 'Moved Person ' . $this->suffix,
			'email' => 'moved-' . $this->suffix . '@example.com',
			'isPrimary' => true,
		], self::ACTOR);

		$result = $merges->merge(
			(int)$source->getId(),
			(int)$keeper->getId(),
			self::ACTOR,
			OptimisticLock::format($source->getUpdatedAt()),
			OptimisticLock::format($keeper->getUpdatedAt()),
		);

		$this->assertSame((int)$keeper->getId(), $result['keeperId']);
		$this->assertSame(1, $result['moved']['persons']);
		$moved = $persons->get((int)$person->getId());
		$this->assertSame((int)$keeper->getId(), (int)$moved->getCompanyId());
		$archived = $companies->get((int)$source->getId());
		$this->assertTrue($archived->isArchived());
	}

	public function testConflictingPcLinksRollBackEntireMerge(): void
	{
		$companies = $this->service(CompanyService::class);
		$persons = $this->service(PersonService::class);
		$merges = $this->service(CompanyMergeService::class);

		// Wide unique fake PC ids — small ranges collide with leftover integration rows.
		$pcKeep = 9_100_000 + (hexdec($this->suffix) % 90_000);
		$pcDup = 9_200_000 + (hexdec($this->suffix) % 90_000);
		$keeper = $companies->create([
			'name' => 'KeepPc ' . $this->suffix,
			'slug' => 'keeppc-' . $this->suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
			'pcCustomerId' => $pcKeep,
		], self::ACTOR);
		$this->track($keeper);
		$source = $companies->create([
			'name' => 'DupPc ' . $this->suffix,
			'slug' => 'duppc-' . $this->suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'lead',
			'pcCustomerId' => $pcDup,
		], self::ACTOR);
		$this->track($source);
		$person = $persons->create((int)$source->getId(), [
			'name' => 'Stay ' . $this->suffix,
			'email' => 'stay-' . $this->suffix . '@example.com',
			'isPrimary' => true,
		], self::ACTOR);

		try {
			$merges->merge(
				(int)$source->getId(),
				(int)$keeper->getId(),
				self::ACTOR,
				OptimisticLock::format($source->getUpdatedAt()),
				OptimisticLock::format($keeper->getUpdatedAt()),
			);
			$this->fail('Expected ValidationException for conflicting PC links');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_MERGE_LINK_CONFLICT, $e->getErrorCode());
		}

		$stillSource = $persons->get((int)$person->getId());
		$this->assertSame((int)$source->getId(), (int)$stillSource->getCompanyId());
		$sourceReload = $companies->get((int)$source->getId());
		$this->assertFalse($sourceReload->isArchived());
	}
}
