<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\DbTransaction;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Agreements — PSD §4.3, §4.7-B, §4.9.2.
 *
 * An agreement is what a customer bought: a license term, a Check Partner
 * package or a one-time item. Three rules carry the weight:
 *
 *  - **Product code and kind must agree.** `azc|pc|mn|iv` are licenses,
 *    `cp-small|cp-std|cp-prem` are partner packages. The remaining codes
 *    default to one-time but may be overridden.
 *  - **`limits_json` is schema-validated per kind**, so a license without a
 *    single seat or a partner package without a quota cannot be saved.
 *  - **Corrections are new agreements, not rewrites.** Cancel and supersede are
 *    explicit, reason-bearing transitions; the history is never edited away.
 */
class AgreementService
{
	public function __construct(
		private readonly AgreementMapper $agreementMapper,
		private readonly CompanyService $companyService,
		private readonly AgreementStatusService $statusService,
		private readonly TimelineService $timelineService,
		private readonly ProductCodeRegistry $productCodes,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ?EditConflictService $editConflicts = null,
		private readonly ?EntityRevisionService $entityRevisions = null,
	) {
	}

	// ── Reads ───────────────────────────────────────────────────────────

	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): Agreement
	{
		try {
			return $this->agreementMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('This agreement no longer exists.'));
		}
	}

	/**
	 * @param array{companyId?: int, kind?: string, status?: string, invoiceStatus?: string, expiringBefore?: \DateTimeInterface} $filters
	 * @return Agreement[]
	 */
	public function search(array $filters = [], int $limit = 500, int $offset = 0): array
	{
		return $this->agreementMapper->search($filters, $limit, $offset);
	}

	/**
	 * @param array{companyId?: int, kind?: string, status?: string, invoiceStatus?: string, expiringBefore?: \DateTimeInterface} $filters
	 */
	public function count(array $filters = []): int
	{
		return $this->agreementMapper->countSearch($filters);
	}

	/** @return Agreement[] */
	public function listForCompany(int $companyId): array
	{
		return $this->agreementMapper->findByCompany($companyId);
	}

	// ── Product / kind / limits rules (§4.9.2) ──────────────────────────

	/**
	 * The kind implied by a product code. Codes with a *strict* mapping cannot
	 * be overridden; the remaining ones default to one-time.
	 */
	public function defaultKindFor(string $productCode): string
	{
		return $this->productCodes->kindFor($productCode);
	}

	public function isKindLockedFor(string $productCode): bool
	{
		return array_key_exists($productCode, Constants::PRODUCT_CODE_KINDS);
	}

	/**
	 * Validate and normalise `limits_json` for a kind.
	 *
	 * @param array<string, mixed> $limits
	 * @return array<string, mixed> the normalised limits to store
	 * @throws ValidationException `AGREEMENT_LIMITS_INVALID`
	 */
	public function validateLimits(string $kind, array $limits): array
	{
		if ($kind === 'license') {
			$mobileSeats = $this->intWithin($limits['mobileSeats'] ?? null, 0, 10000);
			$terminalDevices = $this->intWithin($limits['terminalDevices'] ?? null, 0, 1000);

			if ($mobileSeats === null) {
				throw $this->limitsError('limits.mobileSeats', $this->l10n->t('Enter mobile seats between 0 and 10000.'));
			}
			if ($terminalDevices === null) {
				throw $this->limitsError('limits.terminalDevices', $this->l10n->t('Enter terminal devices between 0 and 1000.'));
			}
			if ($mobileSeats + $terminalDevices <= 0) {
				throw $this->limitsError(
					'limits.mobileSeats',
					$this->l10n->t('A license needs at least one mobile seat or one terminal device.'),
				);
			}

			return [
				'mobileSeats' => $mobileSeats,
				'terminalDevices' => $terminalDevices,
				'bundle' => $this->toBool($limits['bundle'] ?? false),
			];
		}

		if ($kind === 'partner') {
			$hoursQuota = $this->intWithin($limits['hoursQuota'] ?? null, 1, 500);
			if ($hoursQuota === null) {
				throw $this->limitsError(
					'limits.hoursQuota',
					$this->l10n->t('Enter the contracted hours quota between 1 and 500.'),
				);
			}

			return ['hoursQuota' => $hoursQuota];
		}

		// One-time items carry no limits at all (§4.9.2).
		return [];
	}

	/**
	 * Default limits offered by the form when a product code is chosen.
	 *
	 * @return array<string, mixed>
	 */
	public function defaultLimitsFor(string $productCode): array
	{
		if (isset(Constants::PARTNER_DEFAULT_QUOTAS[$productCode])) {
			return ['hoursQuota' => Constants::PARTNER_DEFAULT_QUOTAS[$productCode]];
		}
		if ($this->defaultKindFor($productCode) === 'license') {
			return ['mobileSeats' => 0, 'terminalDevices' => 0, 'bundle' => false];
		}

		return [];
	}

	// ── Writes ──────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 * @throws NotFoundException
	 */
	public function create(array $input, string $actor): Agreement
	{
		$companyId = (int)($input['companyId'] ?? 0);
		if ($companyId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'companyId',
				$this->l10n->t('Pick the company this agreement belongs to.'),
			);
		}
		$this->companyService->get($companyId);

		$now = $this->timeFactory->getDateTime();
		$agreement = new Agreement();
		$agreement->setCompanyId($companyId);
		$agreement->setStatus('draft');
		$agreement->setInvoiceStatus('none');
		$agreement->setAmountCents(0);
		$agreement->setCreatedBy($actor);
		$agreement->setCreatedAt($now);
		$agreement->setUpdatedAt($now);

		$this->applyInput($agreement, $input, true);

		$requestedStatus = trim((string)($input['status'] ?? ''));
		if ($requestedStatus !== '' && $requestedStatus !== 'draft') {
			$this->statusService->assertTransitionAllowed('draft', $requestedStatus);
			$agreement->setStatus($requestedStatus);
		}
		$this->statusService->recompute($agreement, $now);

		$stored = $this->agreementMapper->insert($agreement);

		$this->timelineService->append($companyId, 'agreement.created', $actor, [
			'agreementId' => (int)$stored->getId(),
			'productCode' => (string)$stored->getProductCode(),
			'label' => (string)$stored->getLabel(),
			'validUntil' => $stored->getValidUntil()?->format('Y-m-d'),
		]);

		return $stored;
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function update(int $id, array $input, string $actor, ?string $updatedAtPrecondition, bool $skipOptimistic = false): Agreement
	{
		// User-facing updates always require updatedAt; conflict apply sets skipOptimistic (GNQ §6.4).
		$requirePrecondition = !$skipOptimistic;
		$previousValidUntil = null;
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			// Serialize vs cancel/supersede — stamp alone can resurrect a closed row.
			if (!$this->agreementMapper->lockRow($id)) {
				throw new NotFoundException($this->l10n->t('This agreement no longer exists.'));
			}
			$agreement = $this->agreementMapper->findById($id);
			$currentSnap = $this->agreementConflictSnapshot($agreement);
			try {
				OptimisticLock::assertFresh($agreement->getUpdatedAt(), $updatedAtPrecondition, $requirePrecondition);
			} catch (ConflictException $stale) {
				if (!$requirePrecondition || $this->editConflicts === null) {
					throw $stale;
				}
				$baseSnap = $this->entityRevisions?->snapshotAt('agreement', $id, $updatedAtPrecondition) ?? $currentSnap;
				$incoming = $this->agreementIncomingPatch($input);
				if ($incoming === []) {
					throw $stale;
				}
				$company = $this->companyService->get((int)$agreement->getCompanyId());
				$wsId = (int)($company->getWorkspaceId() ?? 0);
				$classified = ConflictFieldLogic::classifyPatch($baseSnap, $currentSnap, $incoming);
				if ($classified['conflicts'] !== []) {
					DbTransaction::rollBack($this->db, $ownsTxn);
					if (!$ownsTxn) {
						throw $stale;
					}
					$conflictId = $this->editConflicts->recordOpenConflict(
						$wsId,
						'agreement',
						$id,
						$baseSnap,
						$currentSnap,
						$incoming,
						$classified['conflicts'],
						(string)($baseSnap['_actor'] ?? 'unknown'),
						$actor,
					);
					throw new ConflictException(
						$this->l10n->t('Parallel edits need a resolution with a reason.'),
						Constants::ERR_EDIT_CONFLICT,
						['conflictId' => $conflictId, 'fields' => $classified['conflicts']],
					);
				}
				$filtered = [];
				foreach ($classified['takeIncoming'] as $field) {
					if (array_key_exists($field, $incoming)) {
						$filtered[$this->agreementSnapToInput($field)] = $incoming[$field];
					}
				}
				foreach ($input as $k => $v) {
					if (!array_key_exists($k, $this->agreementIncomingPatch([$k => $v])) && $k !== 'updatedAt') {
						$filtered[$k] = $v;
					}
				}
				if ($filtered === []) {
					DbTransaction::commit($this->db, $ownsTxn);

					return $agreement;
				}
				$input = $filtered;
			}

			$previousStatus = (string)$agreement->getStatus();
			if (in_array($previousStatus, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				throw ValidationException::field(
					Constants::ERR_AGREEMENT_ILLEGAL_TRANSITION,
					'status',
					$this->l10n->t('This agreement is closed. Create a new agreement instead of editing it.'),
				);
			}

			$previousValidUntil = $agreement->getValidUntil()?->format('Y-m-d');

			$this->applyInput($agreement, $input, false);

			$requestedStatus = trim((string)($input['status'] ?? ''));
			if ($requestedStatus !== '' && $requestedStatus !== $previousStatus) {
				$this->statusService->assertTransitionAllowed($previousStatus, $requestedStatus);
				if ($requestedStatus === 'cancelled') {
					throw ValidationException::field(
						Constants::ERR_CANCEL_REASON_REQUIRED,
						'cancelReason',
						$this->l10n->t('Use the cancel action so the cancellation reason is recorded.'),
					);
				}
				$agreement->setStatus($requestedStatus);
			}

			$this->statusService->recompute($agreement, $this->timeFactory->getDateTime());
			$agreement->setUpdatedAt($this->timeFactory->getDateTime());
			$stored = $this->agreementMapper->update($agreement);
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		if ($this->entityRevisions !== null) {
			$company = $this->companyService->get((int)$stored->getCompanyId());
			$this->entityRevisions->append(
				(int)($company->getWorkspaceId() ?? 0),
				'agreement',
				(int)$stored->getId(),
				$stored->getUpdatedAt() ?? $this->timeFactory->getDateTime(),
				$actor,
				$this->agreementConflictSnapshot($stored) + ['_actor' => $actor],
			);
		}

		$newValidUntil = $stored->getValidUntil()?->format('Y-m-d');
		if ($newValidUntil !== $previousValidUntil) {
			// The reminder ledger is keyed on the snapshot, so the cadence
			// re-arms by itself — record why for the audit trail.
			$this->timelineService->append((int)$stored->getCompanyId(), 'agreement.status', $actor, [
				'agreementId' => $id,
				'validUntilFrom' => $previousValidUntil,
				'validUntilTo' => $newValidUntil,
				'status' => (string)$stored->getStatus(),
			]);
		}

		return $stored;
	}

	/**
	 * Apply field choices from a resolved edit conflict (GNQ §6.4).
	 *
	 * @param array<string, mixed> $appliedPatch
	 */
	public function applyConflictResolution(int $id, array $appliedPatch, string $actor): Agreement
	{
		$input = [];
		foreach ($appliedPatch as $field => $value) {
			$input[$this->agreementSnapToInput((string)$field)] = $value;
		}
		if ($input === []) {
			return $this->get($id);
		}

		return $this->update($id, $input, $actor, null, true);
	}

	/** @return array<string, mixed> */
	private function agreementConflictSnapshot(Agreement $agreement): array
	{
		return [
			'status' => (string)$agreement->getStatus(),
			'productCode' => (string)$agreement->getProductCode(),
			'amountCents' => (int)$agreement->getAmountCents(),
			'validUntil' => $agreement->getValidUntil() !== null
				? $agreement->getValidUntil()->format('Y-m-d')
				: '',
			'label' => (string)($agreement->getLabel() ?? ''),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function agreementIncomingPatch(array $input): array
	{
		$out = [];
		$map = [
			'status' => 'status',
			'productCode' => 'productCode',
			'amountCents' => 'amountCents',
			'validUntil' => 'validUntil',
			'label' => 'label',
		];
		foreach ($map as $api => $snap) {
			if (array_key_exists($api, $input)) {
				$out[$snap] = $input[$api];
			}
		}

		return $out;
	}

	private function agreementSnapToInput(string $snapField): string
	{
		return match ($snapField) {
			'status', 'productCode', 'amountCents', 'validUntil', 'label' => $snapField,
			default => $snapField,
		};
	}

	/**
	 * Cancel with a mandatory reason (§4.7-B).
	 *
	 * Row lock + required optimistic lock prevent a cancel racing a concurrent
	 * supersede (which would leave a cancelled predecessor pointing at a live
	 * successor, or cancel after the row was already closed).
	 *
	 * @throws ValidationException `CANCEL_REASON_REQUIRED`
	 */
	public function cancel(int $id, string $reason, string $actor, ?string $updatedAtPrecondition): Agreement
	{
		$reason = trim($reason);
		if ($reason === '') {
			throw ValidationException::field(
				Constants::ERR_CANCEL_REASON_REQUIRED,
				'cancelReason',
				$this->l10n->t('Say why the agreement is cancelled — it stays on the record.'),
			);
		}
		if (mb_strlen($reason) > 255) {
			$reason = mb_substr($reason, 0, 255);
		}

		$this->db->beginTransaction();
		try {
			if (!$this->agreementMapper->lockRow($id)) {
				throw new NotFoundException($this->l10n->t('This agreement no longer exists.'));
			}
			$agreement = $this->agreementMapper->findById($id);
			OptimisticLock::assertFresh($agreement->getUpdatedAt(), $updatedAtPrecondition, true);
			$this->statusService->assertTransitionAllowed((string)$agreement->getStatus(), 'cancelled');

			$agreement->setStatus('cancelled');
			$agreement->setCancelReason($reason);
			$agreement->setUpdatedAt($this->timeFactory->getDateTime());
			$stored = $this->agreementMapper->update($agreement);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->timelineService->append((int)$stored->getCompanyId(), 'agreement.cancelled', $actor, [
			'agreementId' => $id,
			'reason' => $reason,
		]);

		return $stored;
	}

	/**
	 * Renewal / upgrade: create the successor and close the predecessor.
	 *
	 * Both writes share one transaction so the chain can never end up with a
	 * dangling `superseded_by` or an old agreement that is still `active` while
	 * its successor already runs. From the commit onwards the old agreement is
	 * excluded from the reminder scan because `superseded` is terminal (§6.1).
	 *
	 * The predecessor is locked FOR UPDATE before the optimistic-lock check so
	 * two parallel renewals cannot both insert an active successor (P0).
	 * `updatedAt` is required from user-facing callers.
	 *
	 * @param array<string, mixed> $input fields of the *new* agreement
	 * @return array{previous: Agreement, current: Agreement}
	 * @throws ValidationException
	 */
	public function supersede(int $id, array $input, string $actor, ?string $updatedAtPrecondition): array
	{
		$now = $this->timeFactory->getDateTime();

		$this->db->beginTransaction();
		try {
			if (!$this->agreementMapper->lockRow($id)) {
				throw new NotFoundException($this->l10n->t('This agreement no longer exists.'));
			}
			$previous = $this->agreementMapper->findById($id);
			OptimisticLock::assertFresh($previous->getUpdatedAt(), $updatedAtPrecondition, true);
			$this->statusService->assertTransitionAllowed((string)$previous->getStatus(), 'superseded');

			// The successor inherits everything the caller did not override.
			$input += [
				'companyId' => (int)$previous->getCompanyId(),
				'kind' => (string)$previous->getKind(),
				'productCode' => (string)$previous->getProductCode(),
				'label' => (string)$previous->getLabel(),
				'limits' => $previous->decodedLimits(),
				'amountCents' => (int)$previous->getAmountCents(),
				'status' => 'active',
			];

			if (!isset($input['startsAt']) || $input['startsAt'] === '' || $input['startsAt'] === null) {
				$previousUntil = $previous->getValidUntil();
				$input['startsAt'] = $previousUntil !== null
					? DateMath::addDays($previousUntil, 1)->format('Y-m-d')
					: $now->format('Y-m-d');
			}

			$successor = new Agreement();
			$successor->setCompanyId((int)$previous->getCompanyId());
			$successor->setStatus('active');
			$successor->setInvoiceStatus('none');
			$successor->setAmountCents(0);
			$successor->setCreatedBy($actor);
			$successor->setCreatedAt($now);
			$successor->setUpdatedAt($now);
			$this->applyInput($successor, $input, true);
			$this->statusService->recompute($successor, $now);
			$successor = $this->agreementMapper->insert($successor);

			$previous->setStatus('superseded');
			$previous->setSupersededBy((int)$successor->getId());
			$previous->setUpdatedAt($now);
			$previous = $this->agreementMapper->update($previous);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->timelineService->append((int)$previous->getCompanyId(), 'agreement.superseded', $actor, [
			'previousAgreementId' => $id,
			'agreementId' => (int)$successor->getId(),
			'previousValidUntil' => $previous->getValidUntil()?->format('Y-m-d'),
			'validUntil' => $successor->getValidUntil()?->format('Y-m-d'),
		]);

		return ['previous' => $previous, 'current' => $successor];
	}

	/**
	 * Order a set of agreements for the same (company, product) into a supersede
	 * chain: newest `valid_until` first, every older row superseded by its
	 * immediate successor (§8.6 import proposal).
	 *
	 * Pure and side-effect free — the importer only *proposes* what this returns.
	 *
	 * @param Agreement[] $agreements
	 * @return list<array{agreement: Agreement, proposedStatus: string, supersededBy: Agreement|null}>
	 */
	public function buildSupersedeChain(array $agreements, \DateTimeInterface $today): array
	{
		$sorted = array_values($agreements);
		usort($sorted, static function (Agreement $a, Agreement $b): int {
			$aUntil = $a->getValidUntil()?->format('Y-m-d') ?? '';
			$bUntil = $b->getValidUntil()?->format('Y-m-d') ?? '';
			if ($aUntil === $bUntil) {
				return (int)$b->getId() <=> (int)$a->getId();
			}

			return strcmp($bUntil, $aUntil);
		});

		$chain = [];
		foreach ($sorted as $index => $agreement) {
			if ($index === 0) {
				$chain[] = [
					'agreement' => $agreement,
					'proposedStatus' => $this->statusService->deriveStatus($this->asActive($agreement), $today),
					'supersededBy' => null,
				];
				continue;
			}

			$chain[] = [
				'agreement' => $agreement,
				'proposedStatus' => 'superseded',
				'supersededBy' => $sorted[$index - 1],
			];
		}

		return $chain;
	}

	/**
	 * Clone-ish helper: derive the status an agreement *would* have if it were
	 * active today, without mutating the caller's entity.
	 */
	private function asActive(Agreement $agreement): Agreement
	{
		$probe = new Agreement();
		$probe->setKind((string)$agreement->getKind());
		$probe->setStatus('active');
		$probe->setValidUntil($agreement->getValidUntil());

		return $probe;
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 */
	private function applyInput(Agreement $agreement, array $input, bool $isCreate): void
	{
		$fields = [];

		$productCode = (string)($input['productCode'] ?? $agreement->getProductCode() ?? '');
		if ($isCreate || array_key_exists('productCode', $input)) {
			if (!$this->productCodes->isAllowed($productCode)) {
				$fields['productCode'] = $this->l10n->t('Choose one of the known product codes.');
			} else {
				$agreement->setProductCode($productCode);
			}
		}

		$kind = (string)($input['kind'] ?? $agreement->getKind() ?? '');
		if ($kind === '') {
			$kind = $this->defaultKindFor($productCode);
		}
		if (!in_array($kind, Constants::AGREEMENT_KINDS, true)) {
			$fields['kind'] = $this->l10n->t('Choose license, partner or one-time.');
		} elseif ($this->isKindLockedFor($productCode) && $kind !== Constants::PRODUCT_CODE_KINDS[$productCode]) {
			throw ValidationException::field(
				Constants::ERR_AGREEMENT_LIMITS_INVALID,
				'kind',
				$this->l10n->t(
					'Product "%1$s" is always of kind "%2$s".',
					[$productCode, Constants::PRODUCT_CODE_KINDS[$productCode]],
				),
			);
		} else {
			$agreement->setKind($kind);
		}

		if ($isCreate || array_key_exists('label', $input)) {
			$label = trim((string)($input['label'] ?? ''));
			if ($label === '') {
				$fields['label'] = $this->l10n->t('Give the agreement a name you will recognise in a list.');
			} elseif (mb_strlen($label) > 255) {
				$fields['label'] = $this->l10n->t('Keep the name under 256 characters.');
			} else {
				$agreement->setLabel($label);
			}
		}

		if ($isCreate || array_key_exists('limits', $input) || array_key_exists('kind', $input) || array_key_exists('productCode', $input)) {
			if ($isCreate && !array_key_exists('limits', $input)) {
				// Form omits limits — apply sellable product defaults (never 0+0 seats).
				$rawLimits = $this->defaultLimitsFor($productCode);
				if ($kind === 'license') {
					$seats = (int)($rawLimits['mobileSeats'] ?? 0);
					$terminals = (int)($rawLimits['terminalDevices'] ?? 0);
					if ($seats + $terminals <= 0) {
						$rawLimits['mobileSeats'] = 1;
						$rawLimits['terminalDevices'] = $terminals;
						$rawLimits['bundle'] = $rawLimits['bundle'] ?? false;
					}
				}
			} else {
				$rawLimits = $input['limits'] ?? $agreement->decodedLimits();
			}
			if (is_string($rawLimits)) {
				$decoded = json_decode($rawLimits, true);
				$rawLimits = is_array($decoded) ? $decoded : [];
			}
			if (!is_array($rawLimits)) {
				$rawLimits = [];
			}
			if (!isset($fields['kind'])) {
				$agreement->setLimitsJson(json_encode(
					$this->validateLimits($kind, $rawLimits),
					JSON_THROW_ON_ERROR,
				));
			}
		}

		if ($isCreate || array_key_exists('startsAt', $input)) {
			$startsAt = DateMath::parseStrictDate((string)($input['startsAt'] ?? ''));
			if ($startsAt === null) {
				$fields['startsAt'] = $this->l10n->t('Enter the start date as YYYY-MM-DD.');
			} else {
				$agreement->setStartsAt(new \DateTime($startsAt->format('Y-m-d')));
			}
		}

		if ($isCreate || array_key_exists('validUntil', $input)) {
			$raw = trim((string)($input['validUntil'] ?? ''));
			if ($raw === '') {
				if ($kind !== 'onetime') {
					$fields['validUntil'] = $this->l10n->t('Licenses and Check Partner packages need an end date.');
				} else {
					$agreement->setValidUntil(null);
				}
			} else {
				if ($kind === 'onetime') {
					$fields['validUntil'] = $this->l10n->t('One-time items do not have an end date.');
				} else {
					$validUntil = DateMath::parseStrictDate($raw);
					if ($validUntil === null) {
						$fields['validUntil'] = $this->l10n->t('Enter the end date as YYYY-MM-DD.');
					} else {
						$agreement->setValidUntil(new \DateTime($validUntil->format('Y-m-d')));
					}
				}
			}
		}

		$startsAt = $agreement->getStartsAt();
		$validUntil = $agreement->getValidUntil();
		if ($startsAt !== null && $validUntil !== null && DateMath::daysBetween($startsAt, $validUntil) < 0) {
			$fields['validUntil'] = $this->l10n->t('The end date cannot be before the start date.');
		}

		if (array_key_exists('amountCents', $input)) {
			$amount = $input['amountCents'];
			if (!is_int($amount) && !(is_string($amount) && preg_match('/^-?\d+$/', $amount) === 1)) {
				$fields['amountCents'] = $this->l10n->t('Enter the net amount in euros.');
			} elseif ((int)$amount < 0) {
				$fields['amountCents'] = $this->l10n->t('The amount cannot be negative.');
			} else {
				$agreement->setAmountCents((int)$amount);
			}
		}

		if (array_key_exists('invoiceRef', $input)) {
			$ref = trim((string)($input['invoiceRef'] ?? ''));
			if (mb_strlen($ref) > 64) {
				$fields['invoiceRef'] = $this->l10n->t('Keep the invoice reference under 65 characters.');
			} else {
				$agreement->setInvoiceRef($ref === '' ? null : $ref);
			}
		}

		if (array_key_exists('invoiceStatus', $input)) {
			$invoiceStatus = (string)$input['invoiceStatus'];
			if (!in_array($invoiceStatus, Constants::INVOICE_STATUSES, true)) {
				$fields['invoiceStatus'] = $this->l10n->t('Choose none, sent, paid or overdue.');
			} else {
				$agreement->setInvoiceStatus($invoiceStatus);
			}
		}

		if (array_key_exists('icInvoiceId', $input)) {
			$icInvoiceId = (int)($input['icInvoiceId'] ?? 0);
			$agreement->setIcInvoiceId($icInvoiceId > 0 ? $icInvoiceId : null);
		}
		if (array_key_exists('salesLogId', $input)) {
			$salesLogId = (int)($input['salesLogId'] ?? 0);
			$agreement->setSalesLogId($salesLogId > 0 ? $salesLogId : null);
		}

		if (array_key_exists('keyFingerprint', $input)) {
			$fingerprint = trim((string)($input['keyFingerprint'] ?? ''));
			if (mb_strlen($fingerprint) > 64) {
				$fingerprint = mb_substr($fingerprint, 0, 64);
			}
			$agreement->setKeyFingerprint($fingerprint === '' ? null : $fingerprint);
		}

		if (array_key_exists('notes', $input)) {
			$notes = trim((string)($input['notes'] ?? ''));
			if (mb_strlen($notes) > 4000) {
				$fields['notes'] = $this->l10n->t('Keep the notes under 4000 characters.');
			} else {
				$agreement->setNotes($notes === '' ? null : $notes);
			}
		}

		if ($fields !== []) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Please correct the highlighted fields.'),
				$fields,
			);
		}
	}

	private function intWithin(mixed $value, int $min, int $max): ?int
	{
		if (is_bool($value)) {
			return null;
		}
		if (is_string($value)) {
			$value = trim($value);
			if ($value === '' || preg_match('/^-?\d+$/', $value) !== 1) {
				return null;
			}
			$value = (int)$value;
		}
		if (is_float($value)) {
			if (floor($value) !== $value) {
				return null;
			}
			$value = (int)$value;
		}
		if (!is_int($value)) {
			return null;
		}

		return ($value >= $min && $value <= $max) ? $value : null;
	}

	private function toBool(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value === 1;
		}

		return in_array(mb_strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}

	private function limitsError(string $field, string $message): ValidationException
	{
		return ValidationException::field(Constants::ERR_AGREEMENT_LIMITS_INVALID, $field, $message);
	}
}
