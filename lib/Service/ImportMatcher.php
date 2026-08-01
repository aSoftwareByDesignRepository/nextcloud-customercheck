<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;

/**
 * Deterministic company match precedence — PSD §8.6.
 *
 *  1. Exact slug / customerId
 *  2. Case-insensitive company name
 *  3. Primary (or any) contact email
 *
 * Ambiguity (two distinct companies match different signals, or two name hits)
 * never auto-resolves: the row is flagged `needs_decision`.
 */
class ImportMatcher
{
	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly PersonMapper $personMapper,
		private readonly SlugService $slugService,
	) {
	}

	/**
	 * @param array{slug?:string,customerId?:string,companyName?:string,name?:string,email?:string} $row
	 * @return array{
	 *   action: 'create'|'link'|'skip'|'needs_decision',
	 *   companyId: int|null,
	 *   companyName: string|null,
	 *   companySlug: string|null,
	 *   candidates: list<array{id:int,slug:string,name:string,via:string}>,
	 *   suggestedSlug: string
	 * }
	 */
	public function match(array $row, int $workspaceId): array
	{
		$slug = mb_strtolower(trim((string)($row['slug'] ?? $row['customerId'] ?? '')));
		$name = trim((string)($row['companyName'] ?? $row['name'] ?? ''));
		$email = mb_strtolower(trim((string)($row['email'] ?? '')));
		// Fail closed — never match across books (§3.3 / §8.6).
		$ws = $workspaceId > 0 ? $workspaceId : 0;
		if ($ws <= 0) {
			return [
				'action' => 'needs_decision',
				'companyId' => null,
				'companyName' => null,
				'companySlug' => null,
				'candidates' => [],
				'suggestedSlug' => $slug !== '' && SlugService::isValid($slug)
					? $slug
					: $this->slugService->suggestUnique($name !== '' ? $name : 'company'),
			];
		}

		$candidates = [];
		$seen = [];

		if ($slug !== '' && SlugService::isValid($slug)) {
			$bySlug = $this->companyMapper->findBySlug($slug);
			if ($bySlug !== null && (int)($bySlug->getWorkspaceId() ?? 0) === $ws) {
				$this->addCandidate($candidates, $seen, $bySlug, 'slug');
			}
		}

		if ($name !== '') {
			foreach ($this->companyMapper->search(['search' => $name, 'workspaceId' => $ws], 20, 0) as $company) {
				if (mb_strtolower((string)$company->getName()) === mb_strtolower($name)) {
					$this->addCandidate($candidates, $seen, $company, 'name');
				}
			}
		}

		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
			foreach ($this->personMapper->findByEmail($email, $ws) as $person) {
				try {
					$company = $this->companyMapper->findById((int)$person->getCompanyId());
					if ((int)($company->getWorkspaceId() ?? 0) !== $ws) {
						continue;
					}
					$this->addCandidate($candidates, $seen, $company, 'email');
				} catch (\Throwable) {
				}
			}
		}

		$suggestedSlug = $slug !== '' && SlugService::isValid($slug)
			? $slug
			: $this->slugService->suggestUnique($name !== '' ? $name : 'company');

		if ($candidates === []) {
			return [
				'action' => 'create',
				'companyId' => null,
				'companyName' => null,
				'companySlug' => null,
				'candidates' => [],
				'suggestedSlug' => $suggestedSlug,
			];
		}

		if (count($candidates) === 1) {
			return [
				'action' => 'link',
				'companyId' => $candidates[0]['id'],
				'companyName' => $candidates[0]['name'],
				'companySlug' => $candidates[0]['slug'],
				'candidates' => $candidates,
				'suggestedSlug' => $suggestedSlug,
			];
		}

		return [
			'action' => 'needs_decision',
			'companyId' => null,
			'companyName' => null,
			'companySlug' => null,
			'candidates' => $candidates,
			'suggestedSlug' => $suggestedSlug,
		];
	}

	/**
	 * @param list<array{id:int,slug:string,name:string,via:string}> $candidates
	 * @param array<int, true> $seen
	 */
	private function addCandidate(array &$candidates, array &$seen, Company $company, string $via): void
	{
		$id = (int)$company->getId();
		if (isset($seen[$id])) {
			return;
		}
		$seen[$id] = true;
		$candidates[] = [
			'id' => $id,
			'slug' => (string)$company->getSlug(),
			'name' => (string)$company->getName(),
			'via' => $via,
		];
	}
}
