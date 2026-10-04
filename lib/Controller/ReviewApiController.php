<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\EditConflictService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\ValueMapService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;

class ReviewApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly WorkspaceAccessService $workspaces,
		private readonly WorkspaceService $workspaceService,
		private readonly ReviewService $review,
		private readonly EditConflictService $conflicts,
		private readonly ValueMapService $valueMaps,
		private readonly CompanyService $companies,
		private readonly DealService $deals,
		private readonly PersonService $persons,
		private readonly AgreementService $agreements,
		private readonly MailboxService $mailbox,
		private readonly IDBConnection $db,
		private readonly TimelineService $timeline,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listItems(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'review.read');
			$status = trim((string)$this->request->getParam('status', ''));
			$kind = trim((string)$this->request->getParam('kind', ''));
			$q = trim((string)$this->request->getParam('q', ''));
			$includeArchived = in_array(
				strtolower(trim((string)$this->request->getParam('includeArchived', '0'))),
				['1', 'true', 'yes'],
				true,
			);

			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$role = (string)($ctx['role'] ?? 'viewer');
			$canWrite = WorkspaceCapability::allows($role, 'write', $this->access->isAppAdmin($uid));
			$canReopen = WorkspaceCapability::allows(
				$role,
				'conflicts.resolve',
				$this->access->isAppAdmin($uid),
			);

			$kindParam = $kind !== '' ? $kind : null;

			return $this->ok([
				'items' => $this->review->listItems(
					$workspaceId,
					$status !== '' ? $status : null,
					$kindParam,
					$q,
					100,
					$includeArchived,
				),
				'openCount' => $this->review->countOpen($workspaceId, $kindParam),
				'sources' => $this->workspaceService->getReviewSources($workspaceId),
				'includeArchived' => $includeArchived,
				'canWrite' => $canWrite,
				'canReopen' => $canReopen,
				'mailSync' => MailboxSyncService::mailSyncSummaryFromBoxes(
					$this->mailbox->listMailboxes($workspaceId),
				),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function assign(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();
			$dealId = array_key_exists('dealId', $body) ? (int)$body['dealId'] : null;
			if ($dealId !== null && $dealId <= 0) {
				$dealId = null;
			}

			return $this->ok($this->review->assign(
				$workspaceId,
				$id,
				(int)($body['companyId'] ?? 0),
				$dealId,
				$uid,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function snooze(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();
			$until = $this->parseSnoozeUntil($body);

			return $this->ok($this->review->snooze($workspaceId, $id, $until));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function noRelation(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();

			return $this->ok($this->review->noRelation(
				$workspaceId,
				$id,
				(string)($body['note'] ?? ''),
				$uid,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Remove junk from Assign. Shared mail is deleted on the IMAP host.
	 */
	#[NoAdminRequired]
	public function discard(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);

			return $this->ok($this->review->discard($workspaceId, $id, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Bulk remove junk from Assign (same semantics as single discard — shared mail deleted on IMAP).
	 */
	#[NoAdminRequired]
	public function discardBulk(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();
			$ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];

			return $this->ok($this->review->discardBulk($workspaceId, $ids, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Mark one shared-mail Assign item as spam (IMAP move to Junk/Spam, then leave Assign).
	 */
	#[NoAdminRequired]
	public function markSpam(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);

			return $this->ok($this->review->markSpam($workspaceId, $id, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Bulk mark shared-mail Assign items as spam (IMAP Junk/Spam).
	 */
	#[NoAdminRequired]
	public function markSpamBulk(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();
			$ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];

			return $this->ok($this->review->markSpamBulk($workspaceId, $ids, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function followUp(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();

			return $this->ok($this->review->followUp($workspaceId, $id, (string)($body['note'] ?? '')));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function reopen(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'review.read');
			$this->workspaces->assertCapability($uid, $workspaceId, 'conflicts.resolve');

			return $this->ok($this->review->reopen($workspaceId, $id, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function enqueue(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();
			$kind = (string)($body['sourceKind'] ?? $body['kind'] ?? '');
			$ref = is_array($body['sourceRef'] ?? null) ? $body['sourceRef'] : [];
			$refKey = trim((string)($body['refKey'] ?? $body['ref'] ?? ''));
			if ($refKey === '' && isset($ref['uid'])) {
				$refKey = (string)$ref['uid'];
			}
			if ($refKey === '' && isset($ref['fileId'])) {
				$refKey = (string)(int)$ref['fileId'];
			}

			$result = $this->review->enqueueSourceItem(
				$workspaceId,
				$kind,
				$refKey,
				$ref,
				(string)($body['subject'] ?? ''),
				(string)($body['snippet'] ?? ''),
				isset($body['email']) ? (string)$body['email'] : null,
				isset($body['org']) ? (string)$body['org'] : null,
			);

			return $this->ok($result);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function applyCountry(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->assertReviewWrite($uid, $workspaceId);
			$body = $this->requestBody();

			return $this->ok($this->review->applyCountryMapping(
				$workspaceId,
				$id,
				(string)($body['canonical'] ?? ''),
				$this->bodyBool($body, 'learn'),
				$uid,
				$this->valueMaps,
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listConflicts(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'review.read');
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$role = (string)($ctx['role'] ?? 'viewer');
			$isManager = WorkspaceCapability::allows(
				$role,
				'conflicts.resolve',
				$this->access->isAppAdmin($uid),
			);
			$filter = $isManager ? null : $uid;

			return $this->ok([
				'items' => $this->conflicts->listOpen($workspaceId, $filter),
				'openCount' => $this->conflicts->countOpen($workspaceId),
				'canResolveAll' => $isManager,
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function resolveConflict(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'review.read');
			$body = $this->requestBody();
			$choices = is_array($body['choices'] ?? null) ? $body['choices'] : [];
			$conflict = $this->conflicts->get($workspaceId, $id);
			$ctx = $this->workspaces->resolveForUser($uid, $workspaceId);
			$role = (string)($ctx['role'] ?? 'viewer');
			$isManager = WorkspaceCapability::allows($role, 'conflicts.resolve', $this->access->isAppAdmin($uid));
			if (!$this->conflicts->actorMayResolve($conflict, $uid, $isManager)) {
				throw new AccessDeniedException('You cannot resolve this conflict.');
			}

			// Atomic mark+apply (GNQ §6.4): one outer txn so a kill mid-apply cannot
			// leave status=resolved without the chosen patch. Nested entity updates
			// use savepoints; outer rollback undoes the mark. Compensator remains a
			// last-resort if mark somehow committed outside this transaction.
			$payload = null;
			$entityType = '';
			$entityId = 0;
			$patch = [];
			$this->db->beginTransaction();
			try {
				$payload = $this->conflicts->resolve(
					$workspaceId,
					$id,
					$choices,
					(string)($body['reason'] ?? ''),
					$uid,
					(int)($body['rowVersion'] ?? 1),
				);

				$entityType = (string)($payload['entityType'] ?? '');
				$entityId = (int)($payload['entityId'] ?? 0);
				$patch = is_array($payload['appliedPatch'] ?? null) ? $payload['appliedPatch'] : [];
				if ($entityId > 0 && $patch !== []) {
					match ($entityType) {
						'company' => $this->companies->applyConflictResolution($entityId, $patch, $uid, $isManager),
						'deal' => $this->deals->applyConflictResolution($entityId, $patch, $uid, $isManager),
						'person' => $this->persons->applyConflictResolution($entityId, $patch, $uid),
						'agreement' => $this->agreements->applyConflictResolution($entityId, $patch, $uid),
						default => null,
					};
				}
				$this->db->commit();
			} catch (\Throwable $applyError) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				} else {
					try {
						$this->conflicts->reopenAfterFailedApply($workspaceId, $id, $uid);
					} catch (\Throwable) {
						// Prefer the original apply failure for the client.
					}
				}
				throw $applyError;
			}

			$companyId = $this->companyIdForConflictEntity($entityType, $entityId);
			if ($companyId !== null && $companyId > 0) {
				try {
					$this->timeline->append($companyId, 'conflict.resolved', $uid, [
						'conflictId' => $id,
						'entityType' => $entityType,
						'entityId' => $entityId,
						'fields' => array_keys($patch),
					]);
				} catch (\Throwable) {
					// Resolve already committed; never fail the HTTP response on audit.
				}
			}

			return $this->ok($payload);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	private function companyIdForConflictEntity(string $entityType, int $entityId): ?int
	{
		if ($entityId <= 0) {
			return null;
		}
		try {
			return match ($entityType) {
				'company' => $entityId,
				'deal' => (int)$this->deals->get($entityId)->getCompanyId(),
				'person' => (int)$this->persons->get($entityId)->getCompanyId(),
				'agreement' => (int)$this->agreements->get($entityId)->getCompanyId(),
				default => null,
			};
		} catch (\Throwable) {
			return null;
		}
	}

	#[NoAdminRequired]
	public function listValueMaps(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');

			return $this->ok(['items' => $this->valueMaps->listForWorkspace($workspaceId)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function applyValueMap(int $workspaceId): JSONResponse
	{
		$uid = '';
		$body = [];
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$body = $this->requestBody();
			$fieldKey = (string)($body['fieldKey'] ?? 'country');
			$result = $this->valueMaps->apply(
				$workspaceId,
				$fieldKey,
				(string)($body['raw'] ?? ''),
				(string)($body['canonical'] ?? ''),
				$this->bodyBool($body, 'learn'),
				$uid,
			);
			$companyId = (int)($body['companyId'] ?? 0);
			if ($companyId > 0 && $fieldKey === 'country') {
				$company = $this->companies->get($companyId);
				if ((int)$company->getWorkspaceId() !== $workspaceId) {
					throw new \OCA\CustomerCheck\Exception\NotFoundException(
						'This company is not in the current workspace.',
					);
				}
				$stamp = \OCA\CustomerCheck\Support\OptimisticLock::format($company->getUpdatedAt());
				$this->companies->update(
					$companyId,
					['country' => (string)$result['canonical']],
					$uid,
					$stamp,
				);
				$result['companyId'] = $companyId;
				$result['companyUpdated'] = true;
			}

			return $this->ok($result);
		} catch (\Throwable $e) {
			// Unmapped raw without a valid canonical → Assign import_anomaly when requested.
			if ($uid !== '' && $this->bodyBool($body, 'enqueueAnomaly')) {
				try {
					return $this->ok($this->review->enqueueImportAnomaly(
						$workspaceId,
						(string)($body['fieldKey'] ?? 'country'),
						(string)($body['raw'] ?? ''),
						['actor' => $uid],
					));
				} catch (\Throwable $inner) {
					return $this->fromThrowable($inner);
				}
			}
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteValueMap(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$this->valueMaps->delete($workspaceId, $id);

			return $this->ok(['deleted' => true, 'id' => $id]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateReviewSources(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->access->currentUserId();
			$this->workspaces->assertCapability($uid, $workspaceId, 'members');
			$body = $this->requestBody();
			$sources = is_array($body['sources'] ?? null) ? $body['sources'] : $body;

			return $this->ok([
				'sources' => $this->workspaceService->updateReviewSources($workspaceId, $sources, $uid),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	private function assertReviewWrite(string $uid, int $workspaceId): void
	{
		$this->workspaces->assertCapability($uid, $workspaceId, 'review.read');
		$this->workspaces->assertCapability($uid, $workspaceId, 'write');
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function parseSnoozeUntil(array $body): \DateTimeImmutable
	{
		$preset = (string)($body['preset'] ?? '');
		$base = new \DateTimeImmutable('now');
		return match ($preset) {
			'1d' => $base->modify('+1 day'),
			'7d' => $base->modify('+7 days'),
			'30d' => $base->modify('+30 days'),
			default => new \DateTimeImmutable((string)($body['until'] ?? '+1 day')),
		};
	}

	/** @return array<string, mixed> */
	private function requestBody(): array
	{
		$raw = file_get_contents('php://input');
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}
}
