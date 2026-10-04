<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\ContactsSuggestService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Persons API — workspace-scoped; includes buying roles (product §5.4 / §12).
 */
class PersonApiController extends Controller
{
	use ApiControllerTrait;
	use WorkspaceScopedTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly CompanyService $companies,
		private readonly PersonService $persons,
		private readonly ContactsSuggestService $contactsSuggest,
		private readonly MailboxService $mailbox,
		private readonly ReviewService $review,
		private readonly EmailTouchMapper $emailTouches,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		try {
			$this->requireCompanyInResolvedWorkspace($id);

			return $this->ok(array_map([$this, 'ser'], $this->persons->listForCompany($id)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(int $id): JSONResponse
	{
		try {
			$ctx = $this->requireCompanyCapability($id, 'write');
			$body = $this->jsonBody();
			$person = $this->persons->create($id, $body, $ctx['uid']);
			$workspaceId = (int)($ctx['workspaceId'] ?? 0);
			if ($workspaceId <= 0) {
				$company = $this->companies->get($id);
				$workspaceId = (int)($company->getWorkspaceId() ?? 0);
			}
			if ($workspaceId > 0 && trim((string)($person->getEmail() ?? '')) !== '') {
				try {
					$this->review->reconcileKnownSharedMail($workspaceId);
				} catch (\Throwable) {
					// Person create already succeeded — rematch is best-effort.
				}
			}

			return $this->ok($this->ser($person), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		try {
			$person = $this->persons->get($id);
			$ctx = $this->requireCompanyCapability((int)$person->getCompanyId(), 'write');
			$body = $this->jsonBody();
			$updated = $this->persons->update($id, $body, $ctx['uid'], $this->updatedAtPrecondition($body));
			$workspaceId = (int)($ctx['workspaceId'] ?? 0);
			if ($workspaceId <= 0) {
				$company = $this->companies->get((int)$person->getCompanyId());
				$workspaceId = (int)($company->getWorkspaceId() ?? 0);
			}
			if ($workspaceId > 0 && trim((string)($updated->getEmail() ?? '')) !== '') {
				try {
					$this->review->reconcileKnownSharedMail($workspaceId);
				} catch (\Throwable) {
					// Person update already succeeded — rematch is best-effort.
				}
			}

			return $this->ok($this->ser($updated));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		try {
			$person = $this->persons->get($id);
			$companyId = (int)$person->getCompanyId();
			$ctx = $this->requireCompanyCapability($companyId, 'write');
			$body = $this->jsonBody();
			$returnMail = $this->bodyBool($body, 'returnMailToAssign');
			$email = trim((string)($person->getEmail() ?? ''));
			$workspaceId = (int)($ctx['workspaceId'] ?? 0);
			if ($workspaceId <= 0) {
				$company = $this->companies->get($companyId);
				$workspaceId = (int)($company->getWorkspaceId() ?? 0);
			}

			$mailReturned = 0;
			// Delete first (optimistic lock) so a conflict cannot leave mail unmatched while the person remains.
			$this->persons->delete($id, $ctx['uid'], $this->updatedAtPrecondition($body));

			if ($returnMail) {
				$this->emailTouches->clearPersonId($id);
				if ($email !== '' && $workspaceId > 0) {
					$mailIds = $this->mailbox->unmatchByCompanyAndFrom($workspaceId, $companyId, $email);
					if ($mailIds !== []) {
						$this->review->returnSharedMailMessagesToAssign($workspaceId, $mailIds);
						$mailReturned = count($mailIds);
					}
				}
			}

			return $this->ok([
				'deleted' => true,
				'contactsKept' => true,
				'mailReturnedToAssign' => $mailReturned,
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function suggestContacts(string $q = '', int $limit = 8): JSONResponse
	{
		try {
			$this->requireWorkspaceContext();

			return $this->ok([
				'available' => $this->contactsSuggest->isAvailable(),
				'items' => $this->contactsSuggest->suggest($q, $limit),
				'suggestions' => $this->contactsSuggest->suggest($q, $limit),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** @return array<string, mixed> */
	private function jsonBody(): array
	{
		$decoded = json_decode(file_get_contents('php://input') ?: '{}', true);

		return is_array($decoded) ? $decoded : [];
	}

	private function ser($p): array
	{
		return [
			'id' => (int)$p->getId(),
			'companyId' => (int)$p->getCompanyId(),
			'name' => (string)$p->getName(),
			'role' => $p->getRole(),
			'buyingRole' => $p->getBuyingRole(),
			'status' => method_exists($p, 'getStatus') ? $p->getStatus() : null,
			'email' => $p->getEmail(),
			'phone' => $p->getPhone(),
			'isPrimary' => (int)$p->getIsPrimary() === 1,
			'contactsUid' => $p->getContactsUid(),
			'reportsToPersonId' => method_exists($p, 'getReportsToPersonId') ? $p->getReportsToPersonId() : null,
			'updatedAt' => OptimisticLock::format($p->getUpdatedAt()),
		];
	}
}
