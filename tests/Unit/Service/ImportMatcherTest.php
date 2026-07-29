<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\ImportMatcher;
use OCA\CustomerCheck\Service\SlugService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * PSD §8.6 — deterministic match precedence + ambiguity fail-closed.
 */
class ImportMatcherTest extends TestCase
{
	private function company(int $id, string $slug, string $name): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setSlug($slug);
		$c->setName($name);
		return $c;
	}

	private function matcher(
		?Company $bySlug = null,
		array $byName = [],
		array $byEmail = [],
	): ImportMatcher {
		$companies = $this->createMock(CompanyMapper::class);
		$persons = $this->createMock(PersonMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$slugService = new SlugService($companies, $l10n);

		$companies->method('findBySlug')->willReturnCallback(
			static function (string $slug) use ($bySlug) {
				return ($bySlug !== null && $bySlug->getSlug() === $slug) ? $bySlug : null;
			}
		);
		$companies->method('search')->willReturn($byName);
		$companies->method('findById')->willReturnCallback(
			static function (int $id) use ($byName, $bySlug, $byEmail) {
				foreach (array_merge($byName, $bySlug ? [$bySlug] : []) as $c) {
					if ((int)$c->getId() === $id) {
						return $c;
					}
				}
				foreach ($byEmail as [$person, $company]) {
					if ((int)$company->getId() === $id) {
						return $company;
					}
				}
				throw new \RuntimeException('missing');
			}
		);
		$persons->method('findByEmail')->willReturnCallback(
			static function (string $email) use ($byEmail) {
				$out = [];
				foreach ($byEmail as [$person, $company]) {
					if (mb_strtolower((string)$person->getEmail()) === mb_strtolower($email)) {
						$out[] = $person;
					}
				}
				return $out;
			}
		);

		return new ImportMatcher($companies, $persons, $slugService);
	}

	public function testExactSlugLinks(): void
	{
		$c = $this->company(7, 'acme-gmbh', 'Acme GmbH');
		$m = $this->matcher($c);
		$result = $m->match(['customerId' => 'acme-gmbh', 'companyName' => 'Other']);
		$this->assertSame('link', $result['action']);
		$this->assertSame(7, $result['companyId']);
		$this->assertSame('Acme GmbH', $result['companyName']);
		$this->assertSame('acme-gmbh', $result['companySlug']);
		$this->assertSame('slug', $result['candidates'][0]['via']);
	}

	public function testExactNameLinksWhenNoSlugHit(): void
	{
		$c = $this->company(3, 'foo-co', 'Exact Name AG');
		$m = $this->matcher(null, [$c]);
		$result = $m->match(['companyName' => 'Exact Name AG']);
		$this->assertSame('link', $result['action']);
		$this->assertSame(3, $result['companyId']);
	}

	public function testEmailLinksWhenSlugAndNameMiss(): void
	{
		$c = $this->company(9, 'mail-co', 'Mail Co');
		$p = new Person();
		$p->setCompanyId(9);
		$p->setEmail('boss@mail.co');
		$m = $this->matcher(null, [], [[$p, $c]]);
		$result = $m->match(['companyName' => 'Totally New', 'email' => 'boss@mail.co']);
		$this->assertSame('link', $result['action']);
		$this->assertSame(9, $result['companyId']);
		$this->assertSame('email', $result['candidates'][0]['via']);
	}

	public function testAmbiguousNameAndEmailNeedsDecision(): void
	{
		$byName = $this->company(1, 'one-co', 'Shared Name');
		$byEmailCompany = $this->company(2, 'two-co', 'Other');
		$p = new Person();
		$p->setCompanyId(2);
		$p->setEmail('a@b.co');
		$m = $this->matcher(null, [$byName], [[$p, $byEmailCompany]]);
		$result = $m->match(['companyName' => 'Shared Name', 'email' => 'a@b.co']);
		$this->assertSame('needs_decision', $result['action']);
		$this->assertNull($result['companyId']);
		$this->assertCount(2, $result['candidates']);
	}

	public function testNoMatchCreatesWithSuggestedSlug(): void
	{
		$m = $this->matcher();
		$result = $m->match(['companyName' => 'Brand New GmbH', 'customerId' => '']);
		$this->assertSame('create', $result['action']);
		$this->assertNull($result['companyId']);
		$this->assertSame('brand-new-gmbh', $result['suggestedSlug']);
	}

	public function testProvidedValidCustomerIdPreferredAsSuggestedSlug(): void
	{
		$m = $this->matcher();
		$result = $m->match(['companyName' => 'X', 'customerId' => 'custom-slug-1']);
		$this->assertSame('create', $result['action']);
		$this->assertSame('custom-slug-1', $result['suggestedSlug']);
	}
}
