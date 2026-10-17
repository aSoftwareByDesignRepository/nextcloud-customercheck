<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\CompanyScopedFk;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\DbTransaction;
use OCA\CustomerCheck\Support\LeadSource;
use OCA\CustomerCheck\Support\WinChance;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Pipeline deals — PSD §4.4, §4.7-C, §14.2.
 */
class DealService
{
	public function __construct(
		private readonly DealMapper $dealMapper,
		private readonly CompanyService $companyService,
		private readonly WorkspaceService $workspaces,
		private readonly StageSequenceService $stageSequences,
		private readonly TimelineService $timelineService,
		private readonly FilesAccessService $filesAccess,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly IDBConnection $db,
		private readonly AgreementMapper $agreementMapper,
		private readonly PersonMapper $personMapper,
		private readonly ?EditConflictService $editConflicts = null,
		private readonly ?EntityRevisionService $entityRevisions = null,
		private readonly ?NotificationService $notifications = null,
	) {
	}

	public function get(int $id): Deal
	{
		try {
			return $this->dealMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('This deal no longer exists.'));
		}
	}

	/** @return Deal[] */
	public function search(array $filters = [], int $limit = 500, int $offset = 0): array
	{
		return $this->dealMapper->search($filters, $limit, $offset);
	}

	public function create(array $data, string $actor, bool $canReassignOwners = false): Deal
	{
		$companyId = (int)($data['companyId'] ?? 0);
		$company = $this->companyService->get($companyId);

		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a deal title.'));
		}

		$stage = (string)($data['stage'] ?? 'lead');
		if (!in_array($stage, Constants::DEAL_STAGES, true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'stage', $this->l10n->t('Unknown pipeline stage.'));
		}

		$now = $this->timeFactory->getDateTime();
		$deal = new Deal();
		$deal->setCompanyId($companyId);
		$deal->setTitle($title);
		$deal->setStage($stage);
		$deal->setValueCents(max(0, (int)($data['valueCents'] ?? 0)));
		$deal->setProducts(isset($data['products']) ? trim((string)$data['products']) : null);
		$deal->setNotes($this->normalizeNotes($data['notes'] ?? null));
		$this->applyPersonId($deal, $companyId, $data['personId'] ?? null);
		$deal->setLeadSource($this->resolveLeadSource($data['leadSource'] ?? null, true));
		$close = DateMath::parseStrictDate((string)($data['expectedClose'] ?? ''));
		$deal->setExpectedClose($close !== null ? new \DateTime($close->format('Y-m-d')) : null);
		$owner = trim((string)($data['ownerUserId'] ?? $actor));
		if (!$canReassignOwners) {
			// Same strip the mobile create path applies — without the reassign
			// capability the owner is always the actor (§13.5).
			$owner = $actor;
		} elseif ($owner !== '' && $owner !== $actor) {
			$this->workspaces->assertOwnerCandidate(
				(int)($company->getWorkspaceId() ?? 0),
				$owner,
				'ownerUserId',
			);
		}
		$deal->setOwnerUserId($owner !== '' ? $owner : null);
		$deal->setForecastBucket($this->resolveForecastBucket($data['forecastBucket'] ?? ($data['winChance'] ?? null)));
		if ($stage === 'lead') {
			$slaHours = isset($data['leadSlaHours'])
				? max(1, (int)$data['leadSlaHours'])
				: $this->leadSlaHoursForCompany($company);
			$deal->setLeadSlaDueAt((clone $now)->modify('+' . $slaHours . ' hours'));
		}
		$deal->setStageChangedAt($now);
		$deal->setCreatedBy($actor);
		$deal->setCreatedAt($now);
		$deal->setUpdatedAt($now);

		$deal = $this->dealMapper->insert($deal);
		$fired = $this->stageSequences->fireForStage($deal, $stage, $actor);
		$this->timelineService->append($companyId, 'deal.stage', $actor, [
			'dealId' => (int)$deal->getId(),
			'stage' => $stage,
			'sequencesFired' => $fired,
		]);

		return $deal;
	}

	/**
	 * Workspace lead SLA hours, falling back to the product default (§11.8).
	 */
	public function leadSlaHoursForCompany(\OCA\CustomerCheck\Db\Company $company): int
	{
		$wsId = (int)($company->getWorkspaceId() ?? 0);
		if ($wsId <= 0) {
			return Constants::LEAD_SLA_HOURS_DEFAULT;
		}
		return max(1, (int)$this->workspaces->getSettings($wsId)['leadSlaHours']);
	}

	public function update(int $id, array $data, string $actor, bool $canReassignOwners = false, bool $skipOptimistic = false): Deal
	{
		$stageFired = null;
		$stageFrom = null;
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			if (!$this->dealMapper->lockRow($id)) {
				throw new NotFoundException($this->l10n->t('This deal no longer exists.'));
			}
			$deal = $this->dealMapper->findById($id);
			$currentSnap = $this->dealConflictSnapshot($deal);
			$requirePrecondition = !$skipOptimistic;
			$precondition = array_key_exists('updatedAt', $data) ? (string)$data['updatedAt'] : null;
			try {
				// Conflict apply sets skipOptimistic — skip required stamp (GNQ §6.4).
				OptimisticLock::assertFresh($deal->getUpdatedAt(), $precondition, $requirePrecondition);
			} catch (ConflictException $stale) {
				if (!$requirePrecondition || $this->editConflicts === null || $precondition === null || $precondition === '') {
					throw $stale;
				}
				// Fail closed: no recorded base revision → cannot prove what the
				// other writer changed; merging against current is silent LWW.
				$baseSnap = $this->entityRevisions?->snapshotAt('deal', $id, (string)$precondition);
				if ($baseSnap === null) {
					throw $stale;
				}
				$incoming = $this->dealIncomingPatch($data);
				if ($incoming === []) {
					throw $stale;
				}
				$company = $this->companyService->get((int)$deal->getCompanyId());
				$wsId = (int)($company->getWorkspaceId() ?? 0);
				$classified = ConflictFieldLogic::classifyPatch($baseSnap, $currentSnap, $incoming);
				if ($classified['conflicts'] !== []) {
					if (!$ownsTxn) {
						// Outer txn owns the unit of work — a conflict recorded
						// inside it would roll back with it.
						throw $stale;
					}
					DbTransaction::rollBack($this->db, $ownsTxn);
					$ownsTxn = false; // consumed — catch must not rollBack a closed txn
					$conflictId = $this->editConflicts->recordOpenConflict(
						$wsId,
						'deal',
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
					if (array_key_exists($field, $data)) {
						$filtered[$field] = $data[$field];
					} elseif (array_key_exists($field, $incoming)) {
						$filtered[$field] = $incoming[$field];
					}
				}
				// Preserve non-protected keys (agreementId, products, …) that cannot conflict.
				foreach ($data as $k => $v) {
					if (!array_key_exists($k, $incoming) && $k !== 'updatedAt') {
						$filtered[$k] = $v;
					}
				}
				if ($filtered === []) {
					DbTransaction::commit($this->db, $ownsTxn);

					return $deal;
				}
				$data = $filtered;
			}

			if (isset($data['title'])) {
				$title = trim((string)$data['title']);
				if ($title === '') {
					throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a deal title.'));
				}
				$deal->setTitle($title);
			}
			if (array_key_exists('valueCents', $data)) {
				$deal->setValueCents(max(0, (int)$data['valueCents']));
			}
			if (array_key_exists('products', $data)) {
				$deal->setProducts($data['products'] === null ? null : trim((string)$data['products']));
			}
			if (array_key_exists('notes', $data)) {
				$deal->setNotes($this->normalizeNotes($data['notes']));
			}
			if (array_key_exists('personId', $data)) {
				$this->applyPersonId($deal, (int)$deal->getCompanyId(), $data['personId']);
			}
			if (array_key_exists('leadSource', $data)) {
				$deal->setLeadSource($this->resolveLeadSource($data['leadSource'], false));
			}
			if (array_key_exists('expectedClose', $data)) {
				$close = DateMath::parseStrictDate((string)($data['expectedClose'] ?? ''));
				$deal->setExpectedClose($close !== null ? new \DateTime($close->format('Y-m-d')) : null);
			}
			if (array_key_exists('agreementId', $data)) {
				if ($data['agreementId'] === null || (int)$data['agreementId'] <= 0) {
					$deal->setAgreementId(null);
				} else {
					$agreementId = (int)$data['agreementId'];
					try {
						$agreement = $this->agreementMapper->findById($agreementId);
					} catch (DoesNotExistException) {
						throw ValidationException::field(
							Constants::ERR_VALIDATION,
							'agreementId',
							$this->l10n->t('Pick an agreement that belongs to this company.'),
						);
					}
					CompanyScopedFk::assertSameCompany(
						(int)$deal->getCompanyId(),
						(int)$agreement->getCompanyId(),
						'agreementId',
						$this->l10n,
						$this->l10n->t('Pick an agreement that belongs to this company.'),
					);
					$deal->setAgreementId($agreementId);
				}
			}
			if (array_key_exists('ownerUserId', $data)) {
				$newOwner = trim((string)($data['ownerUserId'] ?? ''));
				$current = trim((string)($deal->getOwnerUserId() ?? ''));
				if ($newOwner !== $current) {
					// Steal guard (§13.5 / §18.7): owner or manager/app-admin (bulk) may reassign;
					// unowned deals may be assigned by any writer (CAS / claim()).
					$mayReassign = $canReassignOwners || ($current !== '' && $current === $actor);
					if ($current !== '' && !$mayReassign) {
						throw ValidationException::field(
							Constants::ERR_DEAL_OWNED,
							'ownerUserId',
							$this->l10n->t('This deal already has an owner. Ask them or a manager to reassign it.'),
						);
					}
					if ($newOwner !== '') {
						$ownerCompany = $this->companyService->get((int)$deal->getCompanyId());
						$this->workspaces->assertOwnerCandidate(
							(int)($ownerCompany->getWorkspaceId() ?? 0),
							$newOwner,
							'ownerUserId',
						);
					}
					if ($current === '') {
						if ($newOwner !== '') {
							$expectedStamp = $deal->getUpdatedAt();
							if ($expectedStamp === null) {
								throw new ConflictException(
									$this->l10n->t('This deal changed. Reload and try again.'),
								);
							}
							$nowStamp = $this->timeFactory->getDateTime();
							$affected = $this->dealMapper->claimUnowned($id, $newOwner, $expectedStamp, $nowStamp);
							if ($affected !== 1) {
								throw new ConflictException(
									$this->l10n->t('Someone else just claimed this deal. Reload and try again.'),
									Constants::ERR_DEAL_OWNED,
								);
							}
							$deal = $this->dealMapper->findById($id);
						}
					} else {
						$deal->setOwnerUserId($newOwner !== '' ? $newOwner : null);
					}
				}
			}
			if (array_key_exists('forecastBucket', $data) || array_key_exists('winChance', $data)) {
				$raw = array_key_exists('forecastBucket', $data) ? $data['forecastBucket'] : $data['winChance'];
				$deal->setForecastBucket($this->resolveForecastBucket($raw));
			}
			if (array_key_exists('quoteSentOn', $data)) {
				$qs = DateMath::parseStrictDate((string)($data['quoteSentOn'] ?? ''));
				$deal->setQuoteSentOn($qs !== null ? new \DateTime($qs->format('Y-m-d')) : null);
			}
			if (array_key_exists('quoteFileId', $data)) {
				if ($data['quoteFileId'] === null || $data['quoteFileId'] === '' || (int)$data['quoteFileId'] === 0) {
					$deal->setQuoteFileId(null);
				} else {
					$fileId = (int)$data['quoteFileId'];
					// Actor-scoped ACL — never trust a raw filecache id (§18.4 / Files).
					$this->filesAccess->resolveReadableFile(
						$actor,
						$fileId,
						Constants::QUOTE_FILE_ALLOWED_MIMES,
						Constants::QUOTE_FILE_MAX_BYTES,
					);
					$deal->setQuoteFileId($fileId);
				}
			}
			if (array_key_exists('nextActionSkipUntil', $data)) {
				$skip = DateMath::parseStrictDate((string)($data['nextActionSkipUntil'] ?? ''));
				$skipDt = $skip !== null ? new \DateTime($skip->format('Y-m-d')) : null;
				if (!ChaseLogic::isSkipAllowed($skipDt, $this->timeFactory->getDateTime())) {
					throw ValidationException::field(
						Constants::ERR_SKIP_TOO_FAR,
						'nextActionSkipUntil',
						$this->l10n->t('Skip date cannot be more than 7 days ahead.'),
					);
				}
				$deal->setNextActionSkipUntil($skipDt);
			}
			if (array_key_exists('leadFirstTouchAt', $data) && $data['leadFirstTouchAt']) {
				$deal->setLeadFirstTouchAt($this->timeFactory->getDateTime());
			}

			// Conflict apply may include stage (GNQ §6 / §8.4) — never via normal PUT.
			if (array_key_exists('stage', $data) && $skipOptimistic) {
				$stage = trim((string)$data['stage']);
				$currentStage = (string)$deal->getStage();
				if ($stage !== $currentStage) {
					if (in_array($currentStage, Constants::DEAL_TERMINAL_STAGES, true)) {
						throw ValidationException::field(
							Constants::ERR_DEAL_TERMINAL,
							'stage',
							$this->l10n->t('Won and lost deals cannot be reopened. Create a new deal instead.'),
						);
					}
					if (!in_array($stage, Constants::DEAL_STAGES, true)) {
						throw ValidationException::field(
							Constants::ERR_VALIDATION,
							'stage',
							$this->l10n->t('Unknown pipeline stage.'),
						);
					}
					if ($stage === 'lost') {
						$code = trim((string)($data['lostReasonCode'] ?? $deal->getLostReasonCode() ?? ''));
						$detail = trim((string)($data['lostReason'] ?? $deal->getLostReason() ?? ''));
						if ($code === '' || !in_array($code, Constants::LOSS_REASON_CODES, true)) {
							throw ValidationException::field(
								Constants::ERR_LOSS_CODE_REQUIRED,
								'lostReasonCode',
								$this->l10n->t('Pick a loss reason code.'),
							);
						}
						$deal->setLostReasonCode($code);
						if ($code === 'other' && $detail === '') {
							throw ValidationException::field(
								Constants::ERR_LOST_REASON_REQUIRED,
								'lostReason',
								$this->l10n->t('Explain why the deal was lost.'),
							);
						}
						$deal->setLostReason($detail !== '' ? $detail : $code);
					}
					$stageFrom = $currentStage;
					$deal->setStage($stage);
					$deal->setStageChangedAt($this->timeFactory->getDateTime());
					$stageFired = $stage;
				}
			}

			$deal->setUpdatedAt($this->timeFactory->getDateTime());
			$stored = $this->dealMapper->update($deal);
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		if ($stageFired !== null) {
			if ($stageFired === 'lost') {
				// Same as changeStage — conflict resolve must not leave open handoffs alive (AC-F-07).
				$this->cancelOpenDealChecklistItems((int)$stored->getId());
			}
			$fired = $this->stageSequences->fireForStage($stored, $stageFired, $actor);
			$this->timelineService->append((int)$stored->getCompanyId(), 'deal.stage', $actor, [
				'dealId' => (int)$stored->getId(),
				'from' => $stageFrom,
				'to' => $stageFired,
				'sequencesFired' => $fired,
				'via' => 'conflict_resolve',
			]);
		}

		if ($this->entityRevisions !== null) {
			$company = $this->companyService->get((int)$stored->getCompanyId());
			$this->entityRevisions->append(
				(int)($company->getWorkspaceId() ?? 0),
				'deal',
				(int)$stored->getId(),
				$stored->getUpdatedAt() ?? $this->timeFactory->getDateTime(),
				$actor,
				$this->dealConflictSnapshot($stored) + ['_actor' => $actor],
			);
		}

		return $stored;
	}

	/**
	 * Apply field choices from a resolved edit conflict (GNQ §6.4).
	 *
	 * @param array<string, mixed> $appliedPatch
	 */
	public function applyConflictResolution(int $id, array $appliedPatch, string $actor, bool $canReassignOwners = false): Deal
	{
		if ($appliedPatch === []) {
			return $this->get($id);
		}

		return $this->update($id, $appliedPatch, $actor, $canReassignOwners, true);
	}

	/** @return array<string, mixed> */
	private function dealConflictSnapshot(Deal $deal): array
	{
		return [
			'title' => (string)$deal->getTitle(),
			'ownerUserId' => (string)($deal->getOwnerUserId() ?? ''),
			'valueCents' => (int)$deal->getValueCents(),
			'notes' => (string)($deal->getNotes() ?? ''),
			'personId' => $deal->getPersonId() !== null ? (int)$deal->getPersonId() : '',
			'leadSource' => (string)($deal->getLeadSource() ?? ''),
			'quoteSentOn' => $deal->getQuoteSentOn() !== null ? $deal->getQuoteSentOn()->format('Y-m-d') : '',
			'forecastBucket' => (string)($deal->getForecastBucket() ?? ''),
			'nextActionSkipUntil' => $deal->getNextActionSkipUntil() !== null
				? $deal->getNextActionSkipUntil()->format('Y-m-d')
				: '',
			'stage' => (string)$deal->getStage(),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function dealIncomingPatch(array $data): array
	{
		$out = [];
		foreach (['title', 'ownerUserId', 'valueCents', 'notes', 'personId', 'leadSource', 'quoteSentOn', 'forecastBucket', 'nextActionSkipUntil', 'stage'] as $field) {
			if (array_key_exists($field, $data)) {
				$out[$field] = $data[$field];
			}
		}

		return $out;
	}

	/**
	 * Team-only deal notes (not shown to the customer). Empty → null; max 4000 chars.
	 */
	private function normalizeNotes(mixed $raw): ?string
	{
		$notes = trim((string)($raw ?? ''));
		if (mb_strlen($notes) > 4000) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'notes',
				$this->l10n->t('Keep the notes under 4000 characters.'),
			);
		}

		return $notes === '' ? null : $notes;
	}

	/**
	 * @throws ValidationException
	 */
	private function applyPersonId(Deal $deal, int $companyId, mixed $raw): void
	{
		if ($raw === null || $raw === '' || (is_int($raw) && $raw <= 0) || (is_string($raw) && trim($raw) === '')) {
			$deal->setPersonId(null);

			return;
		}
		$personId = (int)$raw;
		if ($personId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'personId',
				$this->l10n->t('Pick a contact that belongs to this company.'),
			);
		}
		try {
			$person = $this->personMapper->findById($personId);
		} catch (DoesNotExistException) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'personId',
				$this->l10n->t('Pick a contact that belongs to this company.'),
			);
		}
		CompanyScopedFk::assertSameCompany(
			$companyId,
			(int)$person->getCompanyId(),
			'personId',
			$this->l10n,
			$this->l10n->t('Pick a contact that belongs to this company.'),
		);
		$deal->setPersonId($personId);
	}

	/**
	 * @throws ValidationException
	 */
	/**
	 * @throws ValidationException
	 */
	private function resolveForecastBucket(mixed $raw): ?string
	{
		$normalized = WinChance::normalize($raw);
		if ($normalized === WinChance::INVALID) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'forecastBucket',
				$this->l10n->t('Pick 25%%, 50%%, 75%%, or 100%%.'),
			);
		}

		return $normalized;
	}

	private function resolveLeadSource(mixed $raw, bool $defaultManual): ?string
	{
		if ($raw === null || $raw === '') {
			return $defaultManual ? 'manual' : null;
		}
		$normalized = LeadSource::normalize(is_string($raw) || is_numeric($raw) ? (string)$raw : '', false);
		if ($normalized === null) {
			return $defaultManual ? 'manual' : null;
		}
		if (!LeadSource::isKnown($normalized)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'leadSource',
				$this->l10n->t('Unknown lead source.'),
			);
		}

		return $normalized;
	}

	/**
	 * Claim an unowned deal for the actor (§18.7). Idempotent if already owned by actor.
	 * Uses a CAS update so two claimers cannot both win.
	 */
	public function claim(int $id, string $actor, ?string $updatedAt): Deal
	{
		$actor = trim($actor);
		if ($actor === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'ownerUserId',
				$this->l10n->t('You must be signed in to claim a deal.'),
			);
		}

		$deal = $this->get($id);
		OptimisticLock::assertFresh($deal->getUpdatedAt(), $updatedAt);

		$current = trim((string)($deal->getOwnerUserId() ?? ''));
		if ($current === $actor) {
			return $deal;
		}
		if ($current !== '') {
			throw ValidationException::field(
				Constants::ERR_DEAL_OWNED,
				'ownerUserId',
				$this->l10n->t('This deal already has an owner. Ask them or a manager to reassign it.'),
			);
		}

		$now = $this->timeFactory->getDateTime();
		$expectedStamp = $deal->getUpdatedAt();
		if ($expectedStamp === null) {
			throw new ConflictException(
				$this->l10n->t('This deal changed. Reload and try again.'),
			);
		}
		$affected = $this->dealMapper->claimUnowned($id, $actor, $expectedStamp, $now);
		if ($affected !== 1) {
			throw new ConflictException(
				$this->l10n->t('Someone else just claimed this deal. Reload and try again.'),
				Constants::ERR_DEAL_OWNED,
			);
		}

		$deal = $this->get($id);
		$this->timelineService->append((int)$deal->getCompanyId(), 'deal.claimed', $actor, [
			'dealId' => $id,
			'ownerUserId' => $actor,
		]);

		return $deal;
	}

	/**
	 * @param array<string, mixed> $extra lostReasonCode, wonReasonCode, lostReason detail
	 * @return array{deal: Deal, sequencesFired: list<string>}
	 */
	public function changeStage(int $id, string $stage, ?string $lostReason, ?string $updatedAt, string $actor, array $extra = []): array
	{
		$deal = $this->get($id);
		OptimisticLock::assertFresh($deal->getUpdatedAt(), $updatedAt);

		$current = (string)$deal->getStage();
		if (in_array($current, Constants::DEAL_TERMINAL_STAGES, true) && $stage !== $current) {
			throw ValidationException::field(
				Constants::ERR_DEAL_TERMINAL,
				'stage',
				$this->l10n->t('Won and lost deals cannot be reopened. Create a new deal instead.'),
			);
		}
		if (!in_array($stage, Constants::DEAL_STAGES, true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'stage', $this->l10n->t('Unknown pipeline stage.'));
		}
		if ($stage === 'lost') {
			$code = trim((string)($extra['lostReasonCode'] ?? ''));
			$detail = trim((string)($extra['lostReason'] ?? $lostReason ?? ''));
			// Free-text alone is never enough — always require a known loss code (§11.6 / §18.7).
			if ($code === '' && $detail !== '' && in_array($detail, Constants::LOSS_REASON_CODES, true)) {
				$code = $detail;
				$detail = '';
			}
			if ($code === '' || !in_array($code, Constants::LOSS_REASON_CODES, true)) {
				throw ValidationException::field(
					Constants::ERR_LOSS_CODE_REQUIRED,
					'lostReasonCode',
					$this->l10n->t('Pick a loss reason code.'),
				);
			}
			$deal->setLostReasonCode($code);
			if ($code === 'other' && $detail === '') {
				throw ValidationException::field(
					Constants::ERR_LOST_REASON_REQUIRED,
					'lostReason',
					$this->l10n->t('Explain why the deal was lost.'),
				);
			}
			$deal->setLostReason($detail !== '' ? $detail : $code);
		}
		if ($stage === 'won' && isset($extra['wonReasonCode'])) {
			$w = trim((string)$extra['wonReasonCode']);
			$deal->setWonReasonCode($w !== '' ? $w : null);
		}

		$now = $this->timeFactory->getDateTime();
		$expectedUpdatedAt = $deal->getUpdatedAt();
		if ($expectedUpdatedAt === null) {
			throw new ConflictException(
				$this->l10n->t('This deal changed. Reload and try again.'),
			);
		}

		$lostReasonCode = $stage === 'lost' ? $deal->getLostReasonCode() : null;
		$lostReasonDetail = $stage === 'lost' ? $deal->getLostReason() : null;
		$wonReasonCode = null;
		if ($stage === 'won' && isset($extra['wonReasonCode'])) {
			$wonReasonCode = trim((string)$extra['wonReasonCode']);
		}

		// CAS on updated_at — two concurrent stage moves with the same optimistic
		// token must not both win (sequences / terminal transitions).
		$affected = $this->dealMapper->updateStageCas(
			$id,
			$expectedUpdatedAt,
			$stage,
			$now,
			$lostReasonCode,
			$lostReasonDetail,
			$wonReasonCode,
		);
		if ($affected !== 1) {
			// Stale stage move → three-way conflict queue when another writer changed stage (GNQ §6).
			if ($this->editConflicts !== null && $updatedAt !== null && trim($updatedAt) !== '') {
				$fresh = $this->get($id);
				$currentSnap = $this->dealConflictSnapshot($fresh);
				$baseSnap = $this->entityRevisions?->snapshotAt('deal', $id, $updatedAt) ?? $currentSnap;
				$incoming = ['stage' => $stage];
				$classified = ConflictFieldLogic::classifyPatch($baseSnap, $currentSnap, $incoming);
				if ($classified['conflicts'] !== []) {
					$company = $this->companyService->get((int)$fresh->getCompanyId());
					$wsId = (int)($company->getWorkspaceId() ?? 0);
					$conflictId = $this->editConflicts->recordOpenConflict(
						$wsId,
						'deal',
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
			}
			throw new ConflictException(
				$this->l10n->t('Someone else just updated this deal. Reload and try again.'),
			);
		}

		$deal = $this->get($id);
		if ($stage === 'lost') {
			// Raw QB avoids circular DI with DealChecklistService → CompanyService → …
			$this->cancelOpenDealChecklistItems($id);
		}
		$fired = $this->stageSequences->fireForStage($deal, $stage, $actor);
		$this->timelineService->append((int)$deal->getCompanyId(), 'deal.stage', $actor, [
			'dealId' => (int)$deal->getId(),
			'from' => $current,
			'to' => $stage,
			'sequencesFired' => $fired,
		]);

		return ['deal' => $deal, 'sequencesFired' => $fired];
	}

	public function staleDealDays(): int
	{
		$raw = $this->config->getAppValue(
			\OCA\CustomerCheck\AppInfo\Application::APP_ID,
			Constants::CONFIG_STALE_DEAL_DAYS,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_STALE_DEAL_DAYS],
		);
		$days = (int)$raw;
		return $days > 0 ? $days : 14;
	}

	/** Cancel open checklist items when a deal is marked lost (CUC-DEAL-CHECKLIST-1). */
	private function cancelOpenDealChecklistItems(int $dealId): void
	{
		if (!$this->db->tableExists('crm_deal_cl_item')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('crm_deal_cl_item')
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')));
		$r = $qb->executeQuery();
		$ids = [];
		while (($row = $r->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		if ($ids === []) {
			return;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$u = $this->db->getQueryBuilder();
		$u->update('crm_deal_cl_item')
			->set('status', $u->createNamedParameter('cancelled'))
			->set('updated_at', $u->createNamedParameter($now))
			->where($u->expr()->eq('deal_id', $u->createNamedParameter($dealId)))
			->andWhere($u->expr()->eq('status', $u->createNamedParameter('open')))
			->executeStatement();
		if ($this->notifications !== null) {
			foreach ($ids as $id) {
				$this->notifications->withdraw('deal_checklist_item', (string)$id);
			}
		}
	}

	public function isStale(Deal $deal, ?\DateTimeInterface $now = null): bool
	{
		$now = $now ?? $this->timeFactory->getDateTime();

		return DealFreshnessLogic::isRotting(
			(string)$deal->getStage(),
			$deal->getStageChangedAt(),
			$this->staleDealDays(),
			$now,
		);
	}

	/** @return array<string, mixed> */
	public function toArray(Deal $deal): array
	{
		$today = $this->timeFactory->getDateTime();
		$fresh = DealFreshnessLogic::snapshot(
			$deal->getCreatedAt(),
			$deal->getUpdatedAt(),
			$deal->getStageChangedAt(),
			(string)$deal->getStage(),
			$this->staleDealDays(),
			$today,
		);

		return [
			'id' => (int)$deal->getId(),
			'title' => (string)$deal->getTitle(),
			'stage' => (string)$deal->getStage(),
			'companyId' => (int)$deal->getCompanyId(),
			'valueCents' => (int)$deal->getValueCents(),
			'products' => $deal->getProducts(),
			'notes' => $deal->getNotes(),
			'personId' => $deal->getPersonId(),
			'leadSource' => $deal->getLeadSource(),
			'expectedClose' => $deal->getExpectedClose()?->format('Y-m-d'),
			'stageChangedAt' => OptimisticLock::format($deal->getStageChangedAt()),
			'lostReason' => $deal->getLostReason(),
			'lostReasonCode' => $deal->getLostReasonCode(),
			'wonReasonCode' => $deal->getWonReasonCode(),
			'agreementId' => $deal->getAgreementId(),
			'ownerUserId' => $deal->getOwnerUserId(),
			'quoteSentOn' => $deal->getQuoteSentOn()?->format('Y-m-d'),
			'quoteAgeDays' => ChaseLogic::quoteAgeDays($deal->getQuoteSentOn(), $today),
			'quoteFileId' => $deal->getQuoteFileId(),
			'forecastBucket' => $deal->getForecastBucket(),
			'leadFirstTouchAt' => OptimisticLock::format($deal->getLeadFirstTouchAt()),
			'leadSlaDueAt' => OptimisticLock::format($deal->getLeadSlaDueAt()),
			'nextActionSkipUntil' => $deal->getNextActionSkipUntil()?->format('Y-m-d'),
			'stale' => $fresh['stale'],
			'rotting' => $fresh['rotting'],
			'createdAt' => $fresh['createdAt'],
			'ageDays' => $fresh['ageDays'],
			'idleDays' => $fresh['idleDays'],
			'stageIdleDays' => $fresh['stageIdleDays'],
			'updatedAt' => OptimisticLock::format($deal->getUpdatedAt()),
		];
	}
}
