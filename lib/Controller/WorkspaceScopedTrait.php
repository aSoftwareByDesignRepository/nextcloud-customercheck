<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;

/**
 * Shared workspace resolution for classic CRM controllers.
 *
 * Product §3.3: resolve workspace → membership → capability.
 * Cross-workspace object access → HTTP 404 (no existence leak).
 *
 * Controllers using this trait must inject:
 * - AccessControlService $accessControl (or $access)
 * - WorkspaceAccessService $workspaces (or $workspaceAccess)
 * - CompanyService $companies (when asserting company-scoped objects)
 */
trait WorkspaceScopedTrait
{
	/**
	 * @return array{
	 *   uid: string,
	 *   workspaceId: int,
	 *   role: string,
	 *   isAppAdmin: bool,
	 *   ctx: array
	 * }
	 */
	protected function requireWorkspaceContext(?int $requestedWorkspaceId = null): array
	{
		$access = $this->accessControl ?? $this->access;
		$workspaces = $this->workspaces ?? $this->workspaceAccess;
		$uid = $access->requireCanUseApp();
		$ctx = $workspaces->resolveForUser($uid, $requestedWorkspaceId);
		$wsId = (int)$ctx['workspace']->getId();
		$workspaces->assertCapability($uid, $wsId, 'read');

		return [
			'uid' => $uid,
			'workspaceId' => $wsId,
			'role' => (string)$ctx['role'],
			'isAppAdmin' => (bool)$ctx['isAppAdmin'],
			'ctx' => $ctx,
		];
	}

	/**
	 * Resolve workspace and assert a capability (write/delete/…).
	 *
	 * @return array{uid: string, workspaceId: int, role: string, isAppAdmin: bool, ctx: array}
	 */
	protected function requireWorkspaceCapability(string $capability, ?int $requestedWorkspaceId = null): array
	{
		$ctx = $this->requireWorkspaceContext($requestedWorkspaceId);
		$workspaces = $this->workspaces ?? $this->workspaceAccess;
		$workspaces->assertCapability($ctx['uid'], $ctx['workspaceId'], $capability);

		return $ctx;
	}

	/**
	 * Load company and prove the caller may access its workspace.
	 *
	 * Resolves ACL against the **company's** workspace (page parity, product §3.3).
	 * A pinned `workspaceId` that disagrees with the company → 404 (no existence leak).
	 * Never uses last-used workspace alone — that caused false 404 for multi-WS members.
	 *
	 * @throws NotFoundException
	 */
	protected function requireCompanyInResolvedWorkspace(int $companyId, ?int $requestedWorkspaceId = null): Company
	{
		$company = $this->companies->get($companyId);
		$companyWsId = (int)($company->getWorkspaceId() ?? 0);
		if ($companyWsId <= 0) {
			throw new NotFoundException('Company not found.');
		}
		if ($requestedWorkspaceId !== null && $requestedWorkspaceId > 0 && $requestedWorkspaceId !== $companyWsId) {
			throw new NotFoundException('Company not found.');
		}
		// Membership / capability against the company's own workspace.
		$this->assertLoadedCompanyInWorkspace($company, 'read');

		return $company;
	}

	/**
	 * Mutating gate: capability must hold on the **company's** workspace, never last-used.
	 *
	 * A multi-workspace member who is viewer in book A and manager in book B must not
	 * mutate A's people/deals via last-used=B (product §3.3 / §21.1).
	 *
	 * @return array{uid: string, workspaceId: int, role: string, isAppAdmin: bool, ctx: array, company: Company}
	 * @throws NotFoundException
	 */
	protected function requireCompanyCapability(int $companyId, string $capability, ?int $requestedWorkspaceId = null): array
	{
		$company = $this->companies->get($companyId);
		$companyWsId = (int)($company->getWorkspaceId() ?? 0);
		if ($companyWsId <= 0) {
			throw new NotFoundException('Company not found.');
		}
		if ($requestedWorkspaceId !== null && $requestedWorkspaceId > 0 && $requestedWorkspaceId !== $companyWsId) {
			throw new NotFoundException('Company not found.');
		}
		$ctx = $this->assertLoadedCompanyInWorkspace($company, $capability);
		$ctx['company'] = $company;

		return $ctx;
	}

	/**
	 * Assert an already-loaded company is in the caller's workspace.
	 *
	 * @return array{uid: string, workspaceId: int, role: string, isAppAdmin: bool, ctx: array}
	 */
	protected function assertLoadedCompanyInWorkspace(Company $company, string $capability = 'read'): array
	{
		$wsId = (int)($company->getWorkspaceId() ?? 0);
		if ($wsId <= 0) {
			throw new NotFoundException('Company not found.');
		}
		$access = $this->accessControl ?? $this->access;
		$workspaces = $this->workspaces ?? $this->workspaceAccess;
		$uid = $access->requireCanUseApp();
		$workspaces->assertCapability($uid, $wsId, $capability);
		$ctx = $workspaces->resolveForUser($uid, $wsId);
		$workspaces->assertCompanyInWorkspace($wsId, (int)$ctx['workspace']->getId());

		return [
			'uid' => $uid,
			'workspaceId' => (int)$ctx['workspace']->getId(),
			'role' => (string)$ctx['role'],
			'isAppAdmin' => (bool)$ctx['isAppAdmin'],
			'ctx' => $ctx,
		];
	}
}
