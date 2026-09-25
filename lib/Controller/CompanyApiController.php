<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyMergeService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\LinkIdempotencyService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Company registry API — always workspace-scoped (product §3.3).
 * Cross-workspace access → HTTP 404.
 */
class CompanyApiController extends Controller
{
	use ApiControllerTrait;
	use WorkspaceScopedTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly CompanyService $companies,
		private readonly CompanyMergeService $merges,
		private readonly SiblingCustomerWriteService $siblingWrites,
		private readonly LinkIdempotencyService $linkIdempotency,
		private readonly SlugService $slugs,
		private readonly TimelineService $timeline,
		private readonly DisplayLabelService $labels,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceContext();
			$filters = [
				'status' => (string)$this->request->getParam('status', ''),
				'search' => (string)$this->request->getParam('q', ''),
				'archived' => $this->request->getParam('archived') === '1',
				'workspaceId' => $ctx['workspaceId'],
			];
			$limit = (int)$this->request->getParam('limit', 100);
			$offset = (int)$this->request->getParam('offset', 0);
			$rows = $this->companies->search($filters, $limit, $offset);
			$total = $this->companies->count($filters);

			return $this->ok(array_map([$this, 'serialize'], $rows), Http::STATUS_OK, [
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'workspaceId' => $ctx['workspaceId'],
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		try {
			$ctx = $this->requireWorkspaceCapability('write');
			$body = $this->jsonBody();
			$body['workspaceId'] = $ctx['workspaceId'];
			$canReassign = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'bulk', false);
			$company = $this->companies->create($body, $ctx['uid'], $canReassign);

			return $this->ok($this->serialize($company), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function suggestSlug(): JSONResponse
	{
		try {
			$this->requireWorkspaceContext();
			$body = $this->jsonBody();

			return $this->ok(['slug' => $this->slugs->suggestUnique((string)($body['name'] ?? ''))]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);

			return $this->ok($this->serialize($company));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			// Prevent silent workspace escape via body.
			unset($body['workspaceId']);
			// Files folder must go through CompanyDocumentsService (actor ACL).
			unset($body['filesFolderId'], $body['filesFolderPath']);
			$previousName = (string)$company->getName();
			$canReassign = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'bulk', false);
			$updated = $this->companies->update(
				$id,
				$body,
				$ctx['uid'],
				$this->updatedAtPrecondition($body),
				$canReassign,
			);

			$payload = $this->serialize($updated);
			// Rename push AFTER CRM commit — never inside CRM TX (SHARED-IDENTITY MH-03).
			if (array_key_exists('name', $body) && (string)$updated->getName() !== $previousName) {
				$push = $this->siblingWrites->pushDisplayNameAfterRename($updated, $ctx['uid'], $previousName);
				if (!empty($push['warning'])) {
					$payload['renamePushWarning'] = $push['warning'];
					$payload['renamePushCode'] = $push['code'];
					$payload['pcCustomerId'] = $push['pcCustomerId'];
				}
			}

			return $this->ok($payload);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'delete');
			$body = $this->jsonBody();
			$this->companies->gdprErase($id, (string)($body['confirmSlug'] ?? ''), $ctx['uid']);

			return $this->ok(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function archive(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'delete');

			$body = $this->jsonBody();

			return $this->ok($this->serialize($this->companies->archive(
				$id,
				$ctx['uid'],
				$this->updatedAtPrecondition($body),
			)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function restore(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'delete');
			$body = $this->jsonBody();

			return $this->ok($this->serialize($this->companies->restore(
				$id,
				$ctx['uid'],
				$this->updatedAtPrecondition($body),
			)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function merge(int $id): JSONResponse
	{
		try {
			$source = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($source, 'delete');
			$body = $this->jsonBody();
			$into = (int)($body['intoCompanyId'] ?? 0);
			$keeper = $this->companies->get($into);
			$this->workspaces->assertCompanyInWorkspace(
				(int)($keeper->getWorkspaceId() ?? 0),
				$ctx['workspaceId'],
			);
			$intoUpdatedAt = isset($body['intoUpdatedAt']) ? (string)$body['intoUpdatedAt'] : null;
			$result = $this->merges->merge(
				$id,
				$into,
				$ctx['uid'],
				$this->updatedAtPrecondition($body),
				$intoUpdatedAt,
			);

			return $this->ok([
				'keeperId' => $result['keeperId'],
				'sourceId' => $result['sourceId'],
				'sourceSlug' => $result['sourceSlug'],
				'moved' => $result['moved'],
				'company' => $this->serialize($result['company']),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createPcCustomer(int $id): JSONResponse
	{
		return $this->linkProjectcheck($id);
	}

	#[NoAdminRequired]
	public function createTcCustomer(int $id): JSONResponse
	{
		return $this->linkTicketcheck($id);
	}

	#[NoAdminRequired]
	public function linkProjectcheck(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$existing = $this->resolveSiblingLinkId($body, 'pcCustomerId', 'existingPcCustomerId');
			$idemKey = $this->linkIdempotency->requireKey($this->request, $body);
			$result = $this->siblingWrites->createOrLinkPcCustomer(
				$id,
				$ctx['uid'],
				$existing,
				$this->updatedAtPrecondition($body),
				$idemKey,
			);

			return $this->ok([
				'company' => $this->serialize($result['company']),
				'pcCustomerId' => $result['pcCustomerId'],
				'created' => $result['created'],
				'idempotentReplay' => !empty($result['idempotentReplay']),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function linkTicketcheck(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$existing = $this->resolveSiblingLinkId($body, 'tcCustomerId', 'existingTcCustomerId');
			$idemKey = $this->linkIdempotency->requireKey($this->request, $body);
			$result = $this->siblingWrites->createOrLinkTcCustomer(
				$id,
				$ctx['uid'],
				$existing,
				$this->updatedAtPrecondition($body),
				$idemKey,
			);

			return $this->ok([
				'company' => $this->serialize($result['company']),
				'tcCustomerId' => $result['tcCustomerId'],
				'created' => $result['created'],
				'idempotentReplay' => !empty($result['idempotentReplay']),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function unlinkProjectcheck(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$updated = $this->siblingWrites->unlinkPcCustomer(
				$id,
				$ctx['uid'],
				$this->updatedAtPrecondition($body),
			);

			return $this->ok(['company' => $this->serialize($updated)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function unlinkTicketcheck(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = $this->jsonBody();
			$updated = $this->siblingWrites->unlinkTcCustomer(
				$id,
				$ctx['uid'],
				$this->updatedAtPrecondition($body),
			);

			return $this->ok(['company' => $this->serialize($updated)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createFieldCustomer(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ctx = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$result = $this->siblingWrites->createFieldCustomerFromHub($id, $ctx['uid']);

			return $this->ok($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function resolveSiblingLinkId(array $body, string $planKey, string $legacyKey): ?int
	{
		$mode = strtolower(trim((string)($body['mode'] ?? '')));
		$candidate = null;
		if (isset($body[$planKey]) && $body[$planKey] !== '' && $body[$planKey] !== null) {
			$candidate = (int)$body[$planKey];
		} elseif (isset($body[$legacyKey]) && $body[$legacyKey] !== '' && $body[$legacyKey] !== null) {
			$candidate = (int)$body[$legacyKey];
		}
		if ($mode === 'create') {
			return null;
		}
		if ($mode === 'link') {
			if ($candidate === null || $candidate <= 0) {
				throw new \OCA\CustomerCheck\Exception\ValidationException(
					\OCA\CustomerCheck\Constants::ERR_VALIDATION,
					'Link mode requires an existing sibling customer id.',
				);
			}

			return $candidate;
		}

		return ($candidate !== null && $candidate > 0) ? $candidate : null;
	}

	#[NoAdminRequired]
	public function export(int $id): JSONResponse
	{
		try {
			$this->requireCompanyInResolvedWorkspace($id);

			return $this->ok($this->companies->gdprExport($id));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function timeline(int $id): JSONResponse
	{
		try {
			$this->requireCompanyInResolvedWorkspace($id);
			$events = $this->timeline->listForCompany($id, 100);
			$data = array_map(function ($e): array {
				$createdAt = $e->getCreatedAt();
				$type = (string)$e->getEventType();
				$actor = (string)$e->getActor();

				return [
					'id' => (int)$e->getId(),
					'eventType' => $type,
					'eventTypeLabel' => $this->labels->timelineEventType($type),
					'actor' => $actor,
					'actorLabel' => $this->labels->timelineActor($actor),
					'payloadJson' => $e->getPayloadJson(),
					'createdAt' => $createdAt?->format(\DateTimeInterface::ATOM),
					'createdAtLabel' => $this->labels->timelineWhen($createdAt),
				];
			}, $events);

			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** @return array<string,mixed> */
	private function jsonBody(): array
	{
		$raw = file_get_contents('php://input') ?: '';
		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function serialize(\OCA\CustomerCheck\Db\Company $c): array
	{
		return [
			'id' => (int)$c->getId(),
			'slug' => (string)$c->getSlug(),
			'name' => (string)$c->getName(),
			'status' => (string)$c->getStatus(),
			'lang' => (string)$c->getLang(),
			'country' => (string)$c->getCountry(),
			'vatId' => $c->getVatId(),
			'website' => $c->getWebsite(),
			'notes' => $c->getNotes(),
			'addrStreet' => $c->getAddrStreet(),
			'addrZip' => $c->getAddrZip(),
			'addrCity' => $c->getAddrCity(),
			'workspaceId' => $c->getWorkspaceId(),
			'kamTier' => $c->getKamTier(),
			'accountOwnerUserId' => $c->getAccountOwnerUserId(),
			'nextTouchOn' => $c->getNextTouchOn()?->format('Y-m-d'),
			'pcCustomerId' => $c->getPcCustomerId(),
			'tcCustomerId' => $c->getTcCustomerId(),
			'filesFolderId' => $c->getFilesFolderId(),
			'filesFolderPath' => $c->getFilesFolderPath(),
			'archivedAt' => OptimisticLock::format($c->getArchivedAt()),
			'updatedAt' => OptimisticLock::format($c->getUpdatedAt()),
		];
	}
}
