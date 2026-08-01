<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\ImportJob;
use OCA\CustomerCheck\Db\ImportJobMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Handles LicenseKeyGeneratedEvent from SbdLicenseOps — PSD §5.2 / S7.
 *
 * Matching company by slug → draft license agreement (idempotent on sales_log_id).
 * Unknown slug → import-inbox row (no auto-create company).
 */
class LicenseKeyEventService
{
	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly AgreementService $agreementService,
		private readonly ImportJobMapper $importJobMapper,
		private readonly NotificationService $notificationService,
		private readonly TimelineService $timelineService,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{
	 *   salesLogId?: ?int,
	 *   product?: string,
	 *   customerId?: string,
	 *   companyName?: string,
	 *   contactEmail?: string,
	 *   limits?: array<string, mixed>,
	 *   validUntil?: string,
	 *   amountEurCents?: int,
	 *   fingerprint?: string,
	 *   generatedBy?: string
	 * } $payload
	 * @return array{action: string, companyId?: int, agreementId?: int, importJobId?: int}
	 */
	public function handle(array $payload): array
	{
		$slug = strtolower(trim((string)($payload['customerId'] ?? '')));
		$salesLogId = isset($payload['salesLogId']) ? (int)$payload['salesLogId'] : 0;
		$fingerprint = trim((string)($payload['fingerprint'] ?? ''));

		if ($slug === '') {
			$this->logger->warning('CustomerCheck ignored license event without customerId');
			return ['action' => 'ignored'];
		}

		if ($salesLogId > 0) {
			$existing = $this->agreementMapper->findBySalesLogId($salesLogId);
			if ($existing !== null) {
				return [
					'action' => 'skip',
					'companyId' => (int)$existing->getCompanyId(),
					'agreementId' => (int)$existing->getId(),
				];
			}
		}

		$company = $this->companyMapper->findBySlug($slug);
		if ($company === null) {
			$job = new ImportJob();
			$job->setSource('license_event_orphan');
			$job->setCreatedBy((string)($payload['generatedBy'] ?? 'system'));
			$job->setCreatedAt($this->timeFactory->getDateTime());
			$job->setSummaryJson(json_encode([
				'kind' => 'orphan_license_key',
				'payload' => $payload,
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
			$stored = $this->importJobMapper->insert($job);
			return ['action' => 'inbox', 'importJobId' => (int)$stored->getId()];
		}

		$product = $this->mapProductCode((string)($payload['product'] ?? 'azc'));
		$limits = is_array($payload['limits'] ?? null) ? $payload['limits'] : [];
		$validUntil = trim((string)($payload['validUntil'] ?? ''));
		$startsAt = $this->timeFactory->getDateTime()->format('Y-m-d');
		$label = trim((string)($payload['companyName'] ?? '')) !== ''
			? trim((string)$payload['companyName']) . ' — ' . strtoupper($product)
			: strtoupper($product) . ' license';

		$mobileSeats = max(0, (int)($limits['mobileSeats'] ?? 0));
		$terminalDevices = max(0, (int)($limits['terminalDevices'] ?? 0));
		if ($mobileSeats + $terminalDevices <= 0) {
			$mobileSeats = 1;
		}

		$agreement = $this->agreementService->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'license',
			'productCode' => $product,
			'label' => $label,
			'limits' => [
				'mobileSeats' => $mobileSeats,
				'terminalDevices' => $terminalDevices,
				'bundle' => (bool)($limits['bundle'] ?? false),
			],
			'startsAt' => $startsAt,
			'validUntil' => $validUntil !== '' ? $validUntil : null,
			'amountCents' => max(0, (int)($payload['amountEurCents'] ?? 0)),
			'salesLogId' => $salesLogId > 0 ? $salesLogId : null,
			'keyFingerprint' => $fingerprint !== '' ? $fingerprint : null,
			'status' => 'draft',
		], 'system');

		$params = [
			'companyName' => (string)$company->getName(),
			'companyId' => (int)$company->getId(),
			'agreementId' => (int)$agreement->getId(),
		];
		$workspaceId = (int)($company->getWorkspaceId() ?? 0);
		if ($workspaceId > 0) {
			$this->notificationService->notifyWorkspaceUsers(
				$workspaceId,
				Constants::NOTIFY_KEY_GENERATED,
				'agreement',
				(string)$agreement->getId(),
				$params,
			);
		}

		return [
			'action' => 'draft',
			'companyId' => (int)$company->getId(),
			'agreementId' => (int)$agreement->getId(),
		];
	}

	private function mapProductCode(string $product): string
	{
		$p = strtolower(trim($product));
		return match ($p) {
			'arbeitszeitcheck', 'azc', 'azc2' => 'azc',
			'projectcheck', 'pc', 'pc2' => 'pc',
			'maintenancecheck', 'mn', 'mn2' => 'mn',
			'invoicecheck', 'iv', 'iv2' => 'iv',
			default => in_array($p, Constants::PRODUCT_CODES, true) ? $p : 'azc',
		};
	}
}
