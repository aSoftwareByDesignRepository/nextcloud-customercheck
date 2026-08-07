<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PayWatch;
use OCA\CustomerCheck\Db\PayWatchMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Invoice-paid → agreement state — PSD §14.3 / §8.14.
 *
 * Watermark in crm_pay_watch ensures exactly-once effects. Ambiguous invoice_ref
 * (>1 agreement) never mass-updates; it writes timeline invoice.paid.ambiguous.
 */
class InvoicePaidSyncService
{
	public function __construct(
		private readonly AgreementMapper $agreements,
		private readonly PayWatchMapper $payWatch,
		private readonly ActivityService $activities,
		private readonly TimelineService $timeline,
		private readonly NotificationService $notifications,
		private readonly CompanyMapper $companies,
		private readonly FacadeProbeService $facades,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Nightly poll entry point. Idle when InvoiceCheck facade is absent.
	 *
	 * @return array{processed:int,paid:int,ambiguous:int,noop:int}
	 */
	public function poll(): array
	{
		$summary = ['processed' => 0, 'paid' => 0, 'ambiguous' => 0, 'noop' => 0];
		if (!$this->facades->invoicecheckAvailable()) {
			return $summary;
		}

		foreach ($this->facades->listPaidInvoiceSignals(500) as $signal) {
			$summary['processed']++;
			$result = $this->applySignal($signal);
			$summary[$result]++;
		}

		return $summary;
	}

	/**
	 * Apply one paid signal (from poll or domain event).
	 *
	 * Ref-only matches require a PC/CRM company pin — a globally unique
	 * invoice_ref must never mutate another workspace’s agreement (§14.3).
	 *
	 * @param array{icInvoiceId?:int|null,invoiceRef?:string|null,paidTotalCents?:int,status?:string,pcCustomerId?:int|null,companyId?:int|null} $signal
	 * @return 'paid'|'ambiguous'|'noop'
	 */
	public function applySignal(array $signal): string
	{
		$icId = isset($signal['icInvoiceId']) ? (int)$signal['icInvoiceId'] : 0;
		$ref = trim((string)($signal['invoiceRef'] ?? ''));
		$paidTotal = max(0, (int)($signal['paidTotalCents'] ?? 0));
		$status = (string)($signal['status'] ?? 'paid');
		$pcCustomerId = isset($signal['pcCustomerId']) ? (int)$signal['pcCustomerId'] : 0;
		$crmCompanyId = isset($signal['companyId']) ? (int)$signal['companyId'] : 0;

		$targets = [];
		if ($icId > 0) {
			$targets = $this->agreements->findByIcInvoiceId($icId);
		} elseif ($ref !== '') {
			$candidates = $this->agreements->findByInvoiceRef($ref);
			$targets = $this->scopeInvoiceRefMatches($candidates, $pcCustomerId, $crmCompanyId);
			if ($targets === [] && $candidates !== []) {
				// Signal named a ref we know, but no safe company pin — fail closed.
				return 'noop';
			}
			if (count($targets) > 1) {
				$this->recordAmbiguous($targets, $ref);
				return 'ambiguous';
			}
		}

		if ($targets === []) {
			return 'noop';
		}

		$changed = false;
		foreach ($targets as $agreement) {
			if ($this->applyToAgreement($agreement, $status, $paidTotal)) {
				$changed = true;
			}
		}

		return $changed ? 'paid' : 'noop';
	}

	/**
	 * @param Agreement[] $candidates
	 * @return Agreement[]
	 */
	private function scopeInvoiceRefMatches(array $candidates, int $pcCustomerId, int $crmCompanyId): array
	{
		if ($candidates === []) {
			return [];
		}

		$allowedCompanyId = 0;
		if ($pcCustomerId > 0) {
			$company = $this->companies->findByPcCustomerId($pcCustomerId);
			if ($company === null) {
				return [];
			}
			$allowedCompanyId = (int)$company->getId();
		} elseif ($crmCompanyId > 0) {
			$allowedCompanyId = $crmCompanyId;
		} else {
			// No pin from InvoiceCheck — never trust a bare invoice_ref across books.
			return [];
		}

		return array_values(array_filter(
			$candidates,
			static fn (Agreement $a): bool => (int)$a->getCompanyId() === $allowedCompanyId,
		));
	}

	/**
	 * Pure watermark transition check (unit-testable).
	 */
	public function isPaidTransition(string $previousStatus, int $previousPaid, string $newStatus, int $newPaid): bool
	{
		$becamePaid = $previousStatus !== 'paid' && $newStatus === 'paid';
		$paidIncreased = $previousStatus !== 'paid' && $newPaid > $previousPaid;
		$paidGrewWhilePaid = $previousStatus === 'paid' && $newPaid > $previousPaid;
		return $becamePaid || $paidIncreased || $paidGrewWhilePaid;
	}

	private function applyToAgreement(Agreement $agreement, string $status, int $paidTotal): bool
	{
		$watch = $this->payWatch->findByAgreement((int)$agreement->getId());
		$prevStatus = $watch?->getLastStatus() ?? (string)$agreement->getInvoiceStatus();
		$prevPaid = $watch !== null ? (int)$watch->getLastPaidTotalCents() : 0;

		if (!$this->isPaidTransition($prevStatus, $prevPaid, $status, $paidTotal)) {
			$this->upsertWatch((int)$agreement->getId(), $status, $paidTotal, $watch);
			return false;
		}

		$now = $this->timeFactory->getDateTime();
		$this->db->beginTransaction();
		try {
			$agreement->setInvoiceStatus('paid');
			$agreement->setUpdatedAt($now);
			$this->agreements->update($agreement);
			$this->upsertWatch((int)$agreement->getId(), 'paid', $paidTotal, $watch);

			$this->timeline->append((int)$agreement->getCompanyId(), 'invoice.paid', 'system', [
				'agreementId' => (int)$agreement->getId(),
				'paidTotalCents' => $paidTotal,
			]);

			if (
				(string)$agreement->getKind() === 'license'
				&& (string)$agreement->getStatus() === 'draft'
				&& $agreement->getSalesLogId() === null
			) {
				$this->activities->createAttachKeyTask($agreement);
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->notifyAgreementWorkspace(
			(int)$agreement->getCompanyId(),
			Constants::NOTIFY_INVOICE_PAID,
			'agreement',
			(string)$agreement->getId(),
			['agreementId' => (int)$agreement->getId()],
		);

		return true;
	}

	/** @param Agreement[] $targets */
	private function recordAmbiguous(array $targets, string $ref): void
	{
		$ids = array_map(static fn (Agreement $a) => (int)$a->getId(), $targets);
		$companyId = (int)$targets[0]->getCompanyId();
		$this->timeline->append($companyId, 'invoice.paid.ambiguous', 'system', [
			'invoiceRef' => $ref,
			'agreementIds' => $ids,
		]);
		$this->notifyAgreementWorkspace(
			$companyId,
			Constants::NOTIFY_INVOICE_PAID,
			'invoice_ref',
			$ref,
			['ambiguous' => 1, 'invoiceRef' => $ref],
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function notifyAgreementWorkspace(
		int $companyId,
		string $subject,
		string $objectType,
		string $objectId,
		array $params,
	): void {
		$workspaceId = 0;
		try {
			$workspaceId = (int)($this->companies->findById($companyId)->getWorkspaceId() ?? 0);
		} catch (\Throwable) {
			// Fail closed — never fan out company payment signals instance-wide.
		}
		if ($workspaceId > 0) {
			$this->notifications->notifyWorkspaceUsers($workspaceId, $subject, $objectType, $objectId, $params);
		}
	}

	private function upsertWatch(int $agreementId, string $status, int $paidTotal, ?PayWatch $existing): void
	{
		$now = $this->timeFactory->getDateTime();
		if ($existing === null) {
			$row = new PayWatch();
			$row->setAgreementId($agreementId);
			$row->setLastStatus($status);
			$row->setLastPaidTotalCents($paidTotal);
			$row->setUpdatedAt($now);
			$this->payWatch->insert($row);
			return;
		}
		$existing->setLastStatus($status);
		$existing->setLastPaidTotalCents($paidTotal);
		$existing->setUpdatedAt($now);
		$this->payWatch->update($existing);
	}
}
