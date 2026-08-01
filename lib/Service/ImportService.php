<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\ImportJob;
use OCA\CustomerCheck\Db\ImportJobMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Two-phase import — PSD §8.6.
 *
 * scan() builds proposals only. apply() writes accepted rows transactionally
 * with lock order company → agreement → person → event, and skips rows already
 * linked by sales_log_id (idempotent re-run).
 */
class ImportService
{
	/** @var list<string> */
	public const CSV_COLUMNS = [
		'customerId', 'companyName', 'contactName', 'email',
		'productCode',
		'mobileSeats', 'terminalDevices', 'validUntil', 'invoiceId',
		'amountEur', 'paidAt', 'keyIssuedAt', 'renewalDue', 'notes',
	];

	public function __construct(
		private readonly ImportMatcher $matcher,
		private readonly CompanyService $companies,
		private readonly AgreementService $agreements,
		private readonly AgreementMapper $agreementMapper,
		private readonly PersonMapper $personMapper,
		private readonly AgreementStatusService $statusService,
		private readonly TimelineService $timeline,
		private readonly ImportJobMapper $importJobs,
		private readonly FacadeProbeService $facades,
		private readonly ProductCodeRegistry $productCodes,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @param array{source?:string,csv?:string,rows?:list<array<string,mixed>>} $payload
	 * @return array{source:string,proposals:list<array<string,mixed>>,errors:list<array<string,mixed>>,meta:array<string,int>}
	 */
	public function scan(array $payload): array
	{
		$source = (string)($payload['source'] ?? 'csv');
		$workspaceId = (int)($payload['workspaceId'] ?? 0);
		if ($workspaceId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'workspaceId',
				$this->l10n->t('Choose a workspace before importing.'),
			);
		}
		$errors = [];
		$rows = [];

		if ($source === 'csv') {
			$parsed = $this->parseCsv((string)($payload['csv'] ?? ''));
			$rows = $parsed['rows'];
			$errors = $parsed['errors'];
		} elseif ($source === 'sales_log') {
			$rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : $this->facades->listSalesLogRows(2000);
			$rows = $this->buildSalesLogSupersedeProposals($rows);
		} elseif ($source === 'projectcheck') {
			$rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : $this->facades->listProjectCheckCustomers(2000);
		} elseif ($source === 'ticketcheck') {
			$rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : $this->facades->listTicketCheckCustomers(2000);
		} else {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'source', $this->l10n->t('Unknown import source.'));
		}

		$proposals = [];
		foreach ($rows as $index => $row) {
			$match = $this->matcher->match($row, $workspaceId);
			$salesLogId = isset($row['salesLogId']) ? (int)$row['salesLogId'] : 0;
			$already = $salesLogId > 0 ? $this->agreementMapper->findBySalesLogId($salesLogId) : null;

			$action = $already !== null ? 'skip' : $match['action'];
			$companyHint = $match['companyId'] ?? null;
			$proposals[] = [
				'index' => $index,
				'action' => $action,
				'row' => $row,
				'match' => $match,
				'agreementPlan' => $this->planAgreements($row, $source, is_int($companyHint) ? $companyHint : null),
				'skipReason' => $already !== null ? 'already_linked_sales_log' : null,
			];
		}

		return [
			'source' => $source,
			'workspaceId' => $workspaceId,
			'proposals' => $proposals,
			'errors' => $errors,
			'meta' => [
				'total' => count($proposals),
				'create' => count(array_filter($proposals, static fn ($p) => $p['action'] === 'create')),
				'link' => count(array_filter($proposals, static fn ($p) => $p['action'] === 'link')),
				'skip' => count(array_filter($proposals, static fn ($p) => $p['action'] === 'skip')),
				'needsDecision' => count(array_filter($proposals, static fn ($p) => $p['action'] === 'needs_decision')),
				'rowErrors' => count($errors),
			],
		];
	}

	/**
	 * @param array{source:string,decisions:list<array{index:int,action:string,companyId?:int|null}> ,proposals?:list<array<string,mixed>>,csv?:string,rows?:list<array<string,mixed>>} $payload
	 * @return array{created:int,updated:int,skipped:int,linked:int,agreements:int,errors:list<array<string,mixed>>}
	 */
	public function apply(array $payload, string $actor): array
	{
		$scan = $this->scan($payload);
		$workspaceId = (int)$scan['workspaceId'];
		$decisions = [];
		foreach ($payload['decisions'] ?? [] as $d) {
			$decisions[(int)($d['index'] ?? -1)] = $d;
		}

		$summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'linked' => 0, 'agreements' => 0, 'errors' => []];
		$now = $this->timeFactory->getDateTime();

		foreach ($scan['proposals'] as $proposal) {
			$index = (int)$proposal['index'];
			$decision = $decisions[$index] ?? null;
			$action = is_array($decision) ? (string)($decision['action'] ?? $proposal['action']) : (string)$proposal['action'];

			// Ambiguous rows require an explicit companyId (or create) in the decision.
			if ($action === 'needs_decision') {
				$summary['skipped']++;
				$summary['errors'][] = ['index' => $index, 'reason' => Constants::ERR_IMPORT_AMBIGUOUS];
				continue;
			}
			if ($action === 'skip') {
				$summary['skipped']++;
				continue;
			}
			if ($proposal['action'] === 'needs_decision' && $action === 'link' && !isset($decision['companyId'])) {
				$summary['skipped']++;
				$summary['errors'][] = ['index' => $index, 'reason' => Constants::ERR_IMPORT_AMBIGUOUS];
				continue;
			}

			$row = $proposal['row'];
			try {
				$this->db->beginTransaction();
				$companyId = null;
				if ($action === 'create') {
					$company = $this->companies->create([
						'name' => (string)($row['companyName'] ?? $row['name'] ?? 'Imported'),
						'slug' => (string)($proposal['match']['suggestedSlug'] ?? ''),
						'status' => 'active',
						'lang' => 'de',
						'notes' => isset($row['notes']) ? (string)$row['notes'] : null,
						'workspaceId' => $workspaceId,
					], $actor);
					$companyId = (int)$company->getId();
					$summary['created']++;
					$this->maybeCreatePerson($companyId, $row, $actor, $now);
				} else {
					$companyId = (int)($decision['companyId'] ?? $proposal['match']['companyId'] ?? 0);
					$company = $this->companies->get($companyId);
					if ((int)($company->getWorkspaceId() ?? 0) !== $workspaceId) {
						throw ValidationException::field(
							Constants::ERR_NOT_FOUND,
							'companyId',
							$this->l10n->t('That company is not in the current workspace.'),
						);
					}
					$summary['linked']++;
				}

				$plans = $this->planAgreements($row, $scan['source'], $companyId);
				foreach ($plans as $plan) {
					if (($plan['skip'] ?? false) === true) {
						$summary['skipped']++;
						continue;
					}
					$input = $plan['input'];
					unset($input['_chainRole']);
					$agreement = $this->agreements->create(array_merge($input, [
						'companyId' => $companyId,
					]), $actor);
					if (($plan['chainRole'] ?? 'active') === 'superseded') {
						$agreement->setStatus('superseded');
						$agreement->setUpdatedAt($now);
						$this->agreementMapper->update($agreement);
					}
					if (!empty($plan['supersedePreviousId'])) {
						$prev = $this->agreementMapper->findById((int)$plan['supersedePreviousId']);
						$prev->setStatus('superseded');
						$prev->setSupersededBy((int)$agreement->getId());
						$prev->setUpdatedAt($now);
						$this->agreementMapper->update($prev);
					}
					$summary['agreements']++;
				}

				$this->timeline->append($companyId, 'import.linked', $actor, [
					'source' => $scan['source'],
					'index' => $index,
				]);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				$summary['errors'][] = [
					'index' => $index,
					'reason' => $e instanceof ValidationException ? $e->getErrorCode() : 'IMPORT_FAILED',
					'message' => $e->getMessage(),
				];
			}
		}

		$job = new ImportJob();
		$job->setSource($scan['source']);
		$job->setCreatedBy($actor);
		$job->setCreatedAt($now);
		$job->setSummaryJson(json_encode($summary, JSON_THROW_ON_ERROR));
		$this->importJobs->insert($job);

		return $summary;
	}

	/**
	 * @return array{rows:list<array<string,mixed>>,errors:list<array{line:int,column:?string,reason:string}>}
	 */
	public function parseCsv(string $csv): array
	{
		$csv = str_replace("\r\n", "\n", $csv);
		$csv = str_replace("\r", "\n", $csv);
		$lines = array_values(array_filter(explode("\n", trim($csv)), static fn ($l) => trim($l) !== ''));
		$errors = [];
		$rows = [];
		if ($lines === []) {
			return ['rows' => [], 'errors' => [['line' => 0, 'column' => null, 'reason' => 'empty']]];
		}

		$header = str_getcsv(array_shift($lines));
		$header = array_map(static fn ($h) => trim((string)$h), $header);
		$required = ['customerId', 'companyName'];
		foreach ($required as $col) {
			if (!in_array($col, $header, true)) {
				$errors[] = ['line' => 1, 'column' => $col, 'reason' => 'missing_header'];
			}
		}
		if ($errors !== []) {
			return ['rows' => [], 'errors' => $errors];
		}

		foreach ($lines as $i => $line) {
			$lineNo = $i + 2;
			$cells = str_getcsv($line);
			if (count($cells) !== count($header)) {
				$errors[] = ['line' => $lineNo, 'column' => null, 'reason' => 'column_count'];
				continue;
			}
			$row = [];
			foreach ($header as $idx => $col) {
				$row[$col] = trim((string)($cells[$idx] ?? ''));
			}
			$rowError = $this->validateCsvRow($row, $lineNo);
			if ($rowError !== null) {
				$errors[] = $rowError;
				continue;
			}
			$rows[] = $this->normalizeCsvRow($row);
		}

		return ['rows' => $rows, 'errors' => $errors];
	}

	/**
	 * Build agreement create plan for one import row — PSD §8.6 / S6.
	 *
	 * When an active agreement already exists for company+product and this row
	 * has a newer (or equal) validUntil, the proposal includes supersedePreviousId.
	 * Older-chain sales-log rows are created as superseded drafts when `_chainRole`
	 * is set by buildSalesLogSupersedeProposals().
	 *
	 * @param array<string,mixed> $row
	 * @return list<array{input:array<string,mixed>,supersedePreviousId:?int,skip:bool}>
	 */
	public function planAgreements(array $row, string $source, ?int $companyId = null): array
	{
		$salesLogId = isset($row['salesLogId']) ? (int)$row['salesLogId'] : 0;
		if ($salesLogId > 0 && $this->agreementMapper->findBySalesLogId($salesLogId) !== null) {
			return [['input' => [], 'supersedePreviousId' => null, 'skip' => true]];
		}

		// ProjectCheck / TicketCheck company-only imports may carry no license fields.
		if (in_array($source, ['projectcheck', 'ticketcheck'], true)
			&& trim((string)($row['validUntil'] ?? '')) === ''
			&& !isset($row['amountEurCents'])
			&& trim((string)($row['product'] ?? $row['productCode'] ?? '')) === ''
		) {
			return [['input' => [], 'supersedePreviousId' => null, 'skip' => true]];
		}

		$validUntil = DateMath::parseStrictDate((string)($row['validUntil'] ?? ''));
		$startsAt = DateMath::parseStrictDate((string)($row['startsAt'] ?? ''));
		if ($startsAt === null) {
			$startsAt = $validUntil !== null
				? DateMath::addDays($validUntil, -365)
				: DateMath::toUtcMidnight($this->timeFactory->getDateTime());
		}

		$product = mb_strtolower(trim((string)($row['product'] ?? $row['productCode'] ?? '')));
		if ($product === '') {
			$product = $this->inferProductCode($row);
		}
		if (!$this->productCodes->isAllowed($product)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'productCode',
				$this->l10n->t('The product code "%s" is not part of the agreement catalogue yet.', [$product]),
			);
		}
		$kind = $this->productCodes->kindFor($product);

		$limits = [];
		if ($kind === 'license') {
			$limits = [
				'mobileSeats' => max(0, (int)($row['mobileSeats'] ?? 0)),
				'terminalDevices' => max(0, (int)($row['terminalDevices'] ?? 0)),
				'bundle' => !empty($row['bundle']),
			];
			if ($limits['mobileSeats'] + $limits['terminalDevices'] <= 0) {
				$limits['mobileSeats'] = 1;
			}
		} elseif ($kind === 'partner') {
			$limits = ['hoursQuota' => Constants::PARTNER_DEFAULT_QUOTAS[$product] ?? 10];
		}

		$amountCents = isset($row['amountEurCents'])
			? (int)$row['amountEurCents']
			: (int)round(((float)($row['amountEur'] ?? 0)) * 100);

		$fingerprint = (string)($row['keyFingerprint'] ?? $row['licenseKeyPrefix'] ?? '');
		$invoiceRef = (string)($row['invoiceId'] ?? $row['invoice_ref'] ?? '');
		$paidAt = trim((string)($row['paidAt'] ?? ''));
		$invoiceStatus = $paidAt !== '' ? 'paid' : ($invoiceRef !== '' ? 'sent' : 'none');

		// Always create via the legal draft→active path; historical “older key”
		// rows are marked superseded after insert (draft→superseded is illegal).
		$chainRole = (string)($row['_chainRole'] ?? 'active');

		$input = [
			'kind' => $kind,
			'productCode' => $product,
			'label' => (string)($row['label'] ?? ($row['companyName'] ?? 'License') . ' ' . $product),
			'limits' => $limits,
			'startsAt' => $startsAt->format('Y-m-d'),
			'validUntil' => $validUntil?->format('Y-m-d'),
			'amountCents' => max(0, $amountCents),
			'invoiceRef' => $invoiceRef !== '' ? $invoiceRef : null,
			'invoiceStatus' => $invoiceStatus,
			'salesLogId' => $salesLogId > 0 ? $salesLogId : null,
			'keyFingerprint' => $fingerprint !== '' ? mb_substr($fingerprint, 0, 64) : null,
			'status' => 'active',
			'notes' => isset($row['notes']) ? (string)$row['notes'] : null,
			'_chainRole' => $chainRole,
		];

		$supersedePreviousId = null;
		if ($companyId !== null && $companyId > 0 && $chainRole === 'active') {
			$existing = $this->agreementMapper->findActiveByCompanyProduct($companyId, $product);
			if ($existing !== null) {
				$existingUntil = $existing->getValidUntil();
				$existingDate = $existingUntil instanceof \DateTimeInterface
					? $existingUntil->format('Y-m-d')
					: '';
				$newDate = $validUntil?->format('Y-m-d') ?? '';
				if ($newDate !== '' && ($existingDate === '' || strcmp($newDate, $existingDate) >= 0)) {
					$supersedePreviousId = (int)$existing->getId();
				}
			}
		}

		return [['input' => $input, 'supersedePreviousId' => $supersedePreviousId, 'skip' => false, 'chainRole' => $chainRole]];
	}

	/**
	 * Build supersede chain for multiple sales-log rows of the same customer+product.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>> proposals ordered oldest→newest with chain hints
	 */
	public function buildSalesLogSupersedeProposals(array $rows): array
	{
		$groups = [];
		foreach ($rows as $row) {
			$key = mb_strtolower((string)($row['customerId'] ?? '')) . '|' . mb_strtolower((string)($row['product'] ?? 'azc'));
			$groups[$key][] = $row;
		}
		$out = [];
		foreach ($groups as $group) {
			usort($group, static function (array $a, array $b): int {
				return strcmp((string)($a['validUntil'] ?? ''), (string)($b['validUntil'] ?? ''));
			});
			$prevSalesLogId = null;
			foreach ($group as $i => $row) {
				$isNewest = $i === count($group) - 1;
				$row['_chainRole'] = $isNewest ? 'active' : 'superseded';
				$row['_supersedesSalesLogId'] = $prevSalesLogId;
				$out[] = $row;
				$prevSalesLogId = isset($row['salesLogId']) ? (int)$row['salesLogId'] : null;
			}
		}
		return $out;
	}

	/** @param array<string,string> $row */
	private function validateCsvRow(array $row, int $lineNo): ?array
	{
		if (trim($row['companyName'] ?? '') === '') {
			return ['line' => $lineNo, 'column' => 'companyName', 'reason' => 'required'];
		}
		$slug = trim($row['customerId'] ?? '');
		if ($slug !== '' && !SlugService::isValid(mb_strtolower($slug))) {
			return ['line' => $lineNo, 'column' => 'customerId', 'reason' => 'SLUG_INVALID'];
		}
		$email = trim($row['email'] ?? '');
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['line' => $lineNo, 'column' => 'email', 'reason' => 'EMAIL_INVALID'];
		}
		$until = trim($row['validUntil'] ?? '');
		if ($until !== '' && DateMath::parseStrictDate($until) === null) {
			return ['line' => $lineNo, 'column' => 'validUntil', 'reason' => 'bad_date'];
		}
		$productCode = mb_strtolower(trim($row['productCode'] ?? $row['product'] ?? ''));
		if ($productCode !== '' && preg_match(ProductCodeRegistry::CUSTOM_PATTERN, $productCode) !== 1) {
			return ['line' => $lineNo, 'column' => 'productCode', 'reason' => 'PRODUCT_CODE_INVALID'];
		}
		return null;
	}

	/** @param array<string,string> $row @return array<string,mixed> */
	private function normalizeCsvRow(array $row): array
	{
		return [
			'customerId' => mb_strtolower(trim($row['customerId'] ?? '')),
			'companyName' => trim($row['companyName'] ?? ''),
			'contactName' => trim($row['contactName'] ?? ''),
			'email' => mb_strtolower(trim($row['email'] ?? '')),
			'product' => mb_strtolower(trim($row['product'] ?? $row['productCode'] ?? '')),
			'productCode' => mb_strtolower(trim($row['productCode'] ?? $row['product'] ?? '')),
			'mobileSeats' => (int)($row['mobileSeats'] ?? 0),
			'terminalDevices' => (int)($row['terminalDevices'] ?? 0),
			'validUntil' => trim($row['validUntil'] ?? ''),
			'invoiceId' => trim($row['invoiceId'] ?? ''),
			'amountEur' => trim($row['amountEur'] ?? '0'),
			'paidAt' => trim($row['paidAt'] ?? ''),
			'notes' => trim($row['notes'] ?? ''),
		];
	}

	/** @param array<string,mixed> $row */
	private function inferProductCode(array $row): string
	{
		$mobileSeats = max(0, (int)($row['mobileSeats'] ?? 0));
		$terminalDevices = max(0, (int)($row['terminalDevices'] ?? 0));
		if ($mobileSeats > 0 || $terminalDevices > 0) {
			return 'azc';
		}

		return 'custom';
	}

	/** @param array<string,mixed> $row */
	private function maybeCreatePerson(int $companyId, array $row, string $actor, \DateTimeInterface $now): void
	{
		$name = trim((string)($row['contactName'] ?? ''));
		$email = trim((string)($row['email'] ?? ''));
		if ($name === '' && $email === '') {
			return;
		}
		$person = new Person();
		$person->setCompanyId($companyId);
		$person->setName($name !== '' ? $name : $email);
		$person->setEmail($email !== '' ? $email : null);
		$person->setIsPrimary(1);
		$person->setCreatedBy($actor);
		$person->setCreatedAt($now instanceof \DateTime ? $now : new \DateTime($now->format('c')));
		$person->setUpdatedAt($person->getCreatedAt());
		$this->personMapper->insert($person);
	}
}
