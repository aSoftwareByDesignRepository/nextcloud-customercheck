<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Money / dunning overview — PSD §8.5 / §9.8.
 *
 * Groups agreements by payment state: overdue, sent (unpaid), paid in the last
 * 30 days. Manual invoice_status is the source when InvoicingCheck is absent;
 * when the facade is available the UI still shows the CRM mirror (invoice-paid
 * sync keeps it fresh) and surfaces a capability flag for the panel note.
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
	 *   groups: array{overdue:list,sent:list,paid30d:list},
	 *   meta: array{total:int,facadeAvailable:bool}
	 * }
	 */
	public function overview(int $limit = 200, ?string $stateFilter = null): array
	{
		$facadeAvailable = $this->facades->invoicecheckAvailable();
		$groups = [
			'overdue' => $this->mapRows($this->agreements->search(['invoiceStatus' => 'overdue'], $limit), 'overdue'),
			'sent' => $this->mapRows($this->agreements->search(['invoiceStatus' => 'sent'], $limit), 'sent'),
			'paid30d' => $this->mapPaidRecent($limit),
		];

		if ($stateFilter !== null && $stateFilter !== '' && isset($groups[$stateFilter])) {
			$items = $groups[$stateFilter];
			$groups = [$stateFilter => $items] + array_fill_keys(array_keys($groups), []);
			$groups[$stateFilter] = $items;
		} else {
			$items = array_merge($groups['overdue'], $groups['sent'], $groups['paid30d']);
		}

		return [
			'items' => $items,
			'groups' => $groups,
			'meta' => [
				'total' => count($items),
				'facadeAvailable' => $facadeAvailable,
			],
		];
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
				'updatedAt' => $a->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
			];
		}

		return $items;
	}

	/**
	 * Paid agreements touched in the last 30 days (manual status mirror).
	 *
	 * @return list<array<string,mixed>>
	 */
	private function mapPaidRecent(int $limit): array
	{
		$cutoff = DateMath::addDays($this->timeFactory->getDateTime(), -30);
		$rows = $this->agreements->search(['invoiceStatus' => 'paid'], $limit * 2);
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
}
