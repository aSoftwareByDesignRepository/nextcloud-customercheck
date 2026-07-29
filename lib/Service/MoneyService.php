<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Money / dunning overview — PSD §8.5 / §9.8 / product §15.3.
 *
 * Groups: overdue, sent (unpaid), paid30d, unknown (invoice_status = none).
 * When InvoicingCheck is available, overdue/paid prefer IC facade summaries and
 * skip CRM-mirror rows that share the same invoiceRef (no double-count).
 *
 * Workspace scoping is mandatory for Pulse / Brief / Money (§20: never blend
 * cross-workspace € totals). Pass workspaceId from every persona surface.
 */
class MoneyService
{
	public function __construct(
		private readonly AgreementMapper $agreements,
		private readonly CompanyMapper $companies,
		private readonly FacadeProbeService $facades,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{
	 *   items: list<array<string,mixed>>,
	 *   groups: array{overdue:list,sent:list,paid30d:list,unknown:list},
	 *   meta: array{total:int,facadeAvailable:bool,workspaceId:?int}
	 * }
	 */
	public function overview(int $limit = 200, ?string $stateFilter = null, ?int $workspaceId = null): array
	{
		$companyIds = $this->companyIdsForWorkspace($workspaceId);
		if ($workspaceId !== null && $workspaceId > 0 && $companyIds === []) {
			$empty = ['overdue' => [], 'sent' => [], 'paid30d' => [], 'unknown' => []];
			return [
				'items' => [],
				'groups' => $empty,
				'meta' => [
					'total' => 0,
					'facadeAvailable' => $this->facades->invoicecheckAvailable(),
					'workspaceId' => $workspaceId,
				],
			];
		}

		$facadeAvailable = $this->facades->invoicecheckAvailable();
		$groups = [
			'overdue' => $this->buildOverdue($limit, $facadeAvailable, $companyIds, $workspaceId),
			'sent' => $this->mapRows(
				$this->agreements->search($this->withCompanyScope(['invoiceStatus' => 'sent'], $companyIds), $limit),
				'sent',
			),
			'paid30d' => $this->buildPaidRecent($limit, $facadeAvailable, $companyIds, $workspaceId),
			'unknown' => $this->mapRows(
				$this->agreements->search($this->withCompanyScope(['invoiceStatus' => 'none'], $companyIds), $limit),
				'unknown',
			),
		];

		if ($stateFilter !== null && $stateFilter !== '' && isset($groups[$stateFilter])) {
			$items = $groups[$stateFilter];
			$empty = array_fill_keys(array_keys($groups), []);
			$groups = $empty;
			$groups[$stateFilter] = $items;
		} else {
			$items = array_merge(
				$groups['overdue'],
				$groups['sent'],
				$groups['paid30d'],
				$groups['unknown'],
			);
		}

		return [
			'items' => $items,
			'groups' => $groups,
			'meta' => [
				'total' => count($items),
				'facadeAvailable' => $facadeAvailable,
				'workspaceId' => $workspaceId,
			],
		];
	}

	/**
	 * @return list<int>|null null = no workspace filter (legacy global money page before WS resolve)
	 */
	private function companyIdsForWorkspace(?int $workspaceId): ?array
	{
		if ($workspaceId === null || $workspaceId <= 0) {
			return null;
		}
		$ids = [];
		foreach ($this->companies->search(['workspaceId' => $workspaceId], 5000) as $c) {
			$ids[] = (int)$c->getId();
		}
		return $ids;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @param list<int>|null $companyIds
	 * @return array<string, mixed>
	 */
	private function withCompanyScope(array $filters, ?array $companyIds): array
	{
		if ($companyIds !== null) {
			$filters['companyIds'] = $companyIds;
		}
		return $filters;
	}

	/**
	 * @param list<int>|null $companyIds
	 * @return list<array<string,mixed>>
	 */
	private function buildOverdue(int $limit, bool $facadeAvailable, ?array $companyIds, ?int $workspaceId): array
	{
		$manual = $this->mapRows(
			$this->agreements->search($this->withCompanyScope(['invoiceStatus' => 'overdue'], $companyIds), $limit),
			'overdue',
		);
		if (!$facadeAvailable) {
			return $manual;
		}

		$icRows = $this->facades->listOverdueInvoices($limit);
		if ($icRows === []) {
			return $manual;
		}

		/** @var array<string, true> $icRefs */
		$icRefs = [];
		$fromIc = [];
		foreach ($icRows as $inv) {
			$ref = trim((string)($inv['invoiceRef'] ?? ''));
			if ($ref !== '') {
				$icRefs[$ref] = true;
			}
			$pcId = (int)($inv['pcCustomerId'] ?? 0);
			$company = $pcId > 0 ? $this->companies->findByPcCustomerId($pcId) : null;
			$matchedAgreementId = null;
			if ($ref !== '') {
				$matches = $this->agreements->findByInvoiceRef($ref);
				if ($matches !== []) {
					$matchedAgreementId = (int)$matches[0]->getId();
					if ($company === null) {
						try {
							$company = $this->companies->findById((int)$matches[0]->getCompanyId());
						} catch (\Throwable) {
							$company = null;
						}
					}
				}
			}
			if (!$this->companyInScope($company, $companyIds, $workspaceId)) {
				continue;
			}
			$fromIc[] = [
				'agreementId' => $matchedAgreementId,
				'companyId' => $company !== null ? (int)$company->getId() : 0,
				'companyName' => $company !== null ? (string)$company->getName() : '',
				'label' => $ref !== '' ? $ref : (string)($inv['icInvoiceId'] ?? 'invoice'),
				'amountCents' => (int)($inv['grossTotalCents'] ?? 0) - (int)($inv['paidTotalCents'] ?? 0),
				'invoiceStatus' => 'overdue',
				'invoiceRef' => $ref !== '' ? $ref : null,
				'bucket' => 'overdue',
				'source' => 'invoicecheck',
				'icInvoiceId' => (int)($inv['icInvoiceId'] ?? 0),
				'dueDate' => $inv['dueDate'] ?? null,
				'updatedAt' => null,
			];
			if (count($fromIc) >= $limit) {
				break;
			}
		}

		$manualDeduped = [];
		foreach ($manual as $row) {
			$ref = trim((string)($row['invoiceRef'] ?? ''));
			if ($ref !== '' && isset($icRefs[$ref])) {
				continue;
			}
			$row['source'] = 'crm';
			$manualDeduped[] = $row;
		}

		return array_slice(array_merge($fromIc, $manualDeduped), 0, $limit);
	}

	/**
	 * @param list<\OCA\CustomerCheck\Db\Agreement> $rows
	 * @return list<array<string,mixed>>
	 */
	private function mapRows(array $rows, string $bucket): array
	{
		$items = [];
		foreach ($rows as $a) {
			$name = '';
			try {
				$name = (string)$this->companies->findById((int)$a->getCompanyId())->getName();
			} catch (\Throwable) {
				$name = '';
			}
			$items[] = [
				'agreementId' => (int)$a->getId(),
				'companyId' => (int)$a->getCompanyId(),
				'companyName' => $name,
				'label' => (string)$a->getLabel(),
				'amountCents' => (int)$a->getAmountCents(),
				'invoiceStatus' => (string)$a->getInvoiceStatus(),
				'invoiceRef' => $a->getInvoiceRef(),
				'bucket' => $bucket,
				'source' => 'crm',
				'updatedAt' => $a->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			];
		}

		return $items;
	}

	/**
	 * @param list<int>|null $companyIds
	 * @return list<array<string,mixed>>
	 */
	private function buildPaidRecent(int $limit, bool $facadeAvailable, ?array $companyIds, ?int $workspaceId): array
	{
		$manual = $this->mapPaidRecentCrm($limit, $companyIds);
		if (!$facadeAvailable) {
			return $manual;
		}

		$icRows = $this->facades->listPaidInvoiceSignals($limit);
		if ($icRows === []) {
			return $manual;
		}

		/** @var array<string, true> $icRefs */
		$icRefs = [];
		$fromIc = [];
		foreach ($icRows as $inv) {
			$ref = trim((string)($inv['invoiceRef'] ?? ''));
			if ($ref !== '') {
				$icRefs[$ref] = true;
			}
			$pcId = (int)($inv['pcCustomerId'] ?? 0);
			$company = $pcId > 0 ? $this->companies->findByPcCustomerId($pcId) : null;
			$matchedAgreementId = null;
			if ($ref !== '') {
				$matches = $this->agreements->findByInvoiceRef($ref);
				if ($matches !== []) {
					$matchedAgreementId = (int)$matches[0]->getId();
					if ($company === null) {
						try {
							$company = $this->companies->findById((int)$matches[0]->getCompanyId());
						} catch (\Throwable) {
							$company = null;
						}
					}
				}
			}
			if (!$this->companyInScope($company, $companyIds, $workspaceId)) {
				continue;
			}
			$fromIc[] = [
				'agreementId' => $matchedAgreementId,
				'companyId' => $company !== null ? (int)$company->getId() : 0,
				'companyName' => $company !== null ? (string)$company->getName() : '',
				'label' => $ref !== '' ? $ref : (string)($inv['icInvoiceId'] ?? 'invoice'),
				'amountCents' => (int)($inv['paidTotalCents'] ?? 0),
				'invoiceStatus' => (string)($inv['status'] ?? 'paid'),
				'invoiceRef' => $ref !== '' ? $ref : null,
				'bucket' => 'paid30d',
				'source' => 'invoicecheck',
				'icInvoiceId' => (int)($inv['icInvoiceId'] ?? 0),
				'dueDate' => null,
				'updatedAt' => null,
			];
			if (count($fromIc) >= $limit) {
				break;
			}
		}

		$manualDeduped = [];
		foreach ($manual as $row) {
			$ref = trim((string)($row['invoiceRef'] ?? ''));
			if ($ref !== '' && isset($icRefs[$ref])) {
				continue;
			}
			$row['source'] = 'crm';
			$manualDeduped[] = $row;
		}

		return array_slice(array_merge($fromIc, $manualDeduped), 0, $limit);
	}

	/**
	 * @param list<int>|null $companyIds
	 * @return list<array<string,mixed>>
	 */
	private function mapPaidRecentCrm(int $limit, ?array $companyIds): array
	{
		$cutoff = DateMath::addDays($this->timeFactory->getDateTime(), -30);
		$rows = $this->agreements->search($this->withCompanyScope(['invoiceStatus' => 'paid'], $companyIds), $limit * 2);
		$recent = [];
		foreach ($rows as $a) {
			$updated = $a->getUpdatedAt();
			if ($updated === null || $updated < $cutoff) {
				continue;
			}
			$recent[] = $a;
			if (count($recent) >= $limit) {
				break;
			}
		}

		return $this->mapRows($recent, 'paid30d');
	}

	/**
	 * @param \OCA\CustomerCheck\Db\Company|null $company
	 * @param list<int>|null $companyIds
	 */
	private function companyInScope($company, ?array $companyIds, ?int $workspaceId): bool
	{
		if ($companyIds === null) {
			return true;
		}
		if ($company === null) {
			return false;
		}
		$id = (int)$company->getId();
		if (!in_array($id, $companyIds, true)) {
			return false;
		}
		if ($workspaceId !== null && $workspaceId > 0) {
			return (int)($company->getWorkspaceId() ?? 0) === $workspaceId;
		}
		return true;
	}
}
