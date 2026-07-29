<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Resolve workspace for every request — product §3.3.
 *
 * Order: query → last-used → sole membership → picker needed.
 * Cross-workspace object access → HTTP 404 (no existence leak).
 */
class WorkspaceAccessService
{
	public function __construct(
		private readonly WorkspaceMapper $workspaces,
		private readonly WorkspaceMemberMapper $members,
		private readonly AccessControlService $access,
		private readonly IConfig $config,
		private readonly IRequest $request,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array{
	 *   workspace: Workspace,
	 *   role: string,
	 *   isAppAdmin: bool,
	 *   member: ?WorkspaceMember,
	 *   needsPicker: bool
	 * }
	 */
	public function resolveForUser(string $userId, ?int $requestedWorkspaceId = null): array
	{
		$isAppAdmin = $this->access->isAppAdmin($userId);
		$requested = $requestedWorkspaceId;
		if ($requested === null) {
			$q = $this->request->getParam('workspaceId');
			if ($q !== null && $q !== '') {
				$requested = (int)$q;
			}
		}

		if ($requested !== null && $requested > 0) {
			$ws = $this->requireWorkspace($requested);
			$member = $this->members->findMembershipOrNull((int)$ws->getId(), $userId);
			if (!$isAppAdmin && $member === null) {
				// Existence leak prevention: same as not found.
				throw new NotFoundException($this->l10n->t('Workspace not found.'));
			}
			$this->remember($userId, (int)$ws->getId());
			return [
				'workspace' => $ws,
				'role' => $isAppAdmin ? 'manager' : (string)$member->getRole(),
				'isAppAdmin' => $isAppAdmin,
				'member' => $member,
				'needsPicker' => false,
			];
		}

		$lastId = (int)$this->config->getUserValue($userId, 'customercheck', Constants::USER_CONFIG_LAST_WORKSPACE, '0');
		if ($lastId > 0) {
			try {
				$ws = $this->workspaces->findById($lastId);
				$member = $this->members->findMembershipOrNull($lastId, $userId);
				if ($isAppAdmin || $member !== null) {
					return [
						'workspace' => $ws,
						'role' => $isAppAdmin ? 'manager' : (string)$member->getRole(),
						'isAppAdmin' => $isAppAdmin,
						'member' => $member,
						'needsPicker' => false,
					];
				}
			} catch (DoesNotExistException) {
				// fall through
			}
		}

		if ($isAppAdmin) {
			$all = $this->workspaces->findAll();
			if ($all === []) {
				throw new NotFoundException($this->l10n->t('No workspace exists yet.'));
			}
			if (count($all) === 1) {
				$ws = $all[0];
				$this->remember($userId, (int)$ws->getId());
				return [
					'workspace' => $ws,
					'role' => 'manager',
					'isAppAdmin' => true,
					'member' => $this->members->findMembershipOrNull((int)$ws->getId(), $userId),
					'needsPicker' => false,
				];
			}
			return [
				'workspace' => $all[0],
				'role' => 'manager',
				'isAppAdmin' => true,
				'member' => null,
				'needsPicker' => true,
			];
		}

		$mine = $this->workspaces->findForUser($userId);
		if ($mine === []) {
			throw new NotFoundException($this->l10n->t('You are not a member of any workspace.'));
		}
		if (count($mine) === 1) {
			$ws = $mine[0];
			$member = $this->members->findMembership((int)$ws->getId(), $userId);
			$this->remember($userId, (int)$ws->getId());
			return [
				'workspace' => $ws,
				'role' => (string)$member->getRole(),
				'isAppAdmin' => false,
				'member' => $member,
				'needsPicker' => false,
			];
		}

		return [
			'workspace' => $mine[0],
			'role' => (string)($this->members->findMembershipOrNull((int)$mine[0]->getId(), $userId)?->getRole() ?? 'viewer'),
			'isAppAdmin' => false,
			'member' => $this->members->findMembershipOrNull((int)$mine[0]->getId(), $userId),
			'needsPicker' => true,
		];
	}

	public function assertCapability(string $userId, int $workspaceId, string $capability): void
	{
		$ctx = $this->resolveForUser($userId, $workspaceId);
		if ($ctx['workspace']->isArchived() && $capability !== 'read' && $capability !== 'mail.read') {
			throw ValidationException::field(
				Constants::ERR_WS_ARCHIVED,
				'workspaceId',
				$this->l10n->t('This workspace is archived and read-only.'),
			);
		}
		if (!WorkspaceCapability::allows($ctx['role'], $capability, $ctx['isAppAdmin'])) {
			throw ValidationException::field(
				Constants::ERR_WS_ROLE,
				'capability',
				$this->l10n->t('You do not have permission for this action in this workspace.'),
			);
		}
	}

	/**
	 * Object-level: company must belong to workspace or 404.
	 */
	public function assertCompanyInWorkspace(int $companyWorkspaceId, int $workspaceId): void
	{
		if ($companyWorkspaceId <= 0 || $workspaceId <= 0 || $companyWorkspaceId !== $workspaceId) {
			throw new NotFoundException($this->l10n->t('Company not found.'));
		}
	}

	/**
	 * Full object gate: user may read the workspace that owns this company, else 404.
	 *
	 * @return array{workspaceId: int, role: string, isAppAdmin: bool}
	 */
	public function assertUserCanAccessCompany(string $userId, int $companyWorkspaceId, string $capability = 'read'): array
	{
		if ($companyWorkspaceId <= 0) {
			throw new NotFoundException($this->l10n->t('Company not found.'));
		}
		$this->assertCapability($userId, $companyWorkspaceId, $capability);
		$ctx = $this->resolveForUser($userId, $companyWorkspaceId);
		$this->assertCompanyInWorkspace($companyWorkspaceId, (int)$ctx['workspace']->getId());

		return [
			'workspaceId' => (int)$ctx['workspace']->getId(),
			'role' => (string)$ctx['role'],
			'isAppAdmin' => (bool)$ctx['isAppAdmin'],
		];
	}

	public function requireWorkspace(int $id): Workspace
	{
		try {
			return $this->workspaces->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('Workspace not found.'));
		}
	}

	/**
	 * Workspaces the user may open in the sidebar switcher (app admins: all).
	 *
	 * @return list<array{id: int, name: string, slug: string, archived: bool, isDefault: bool}>
	 */
	public function listVisibleSummaries(string $userId): array
	{
		$rows = $this->access->isAppAdmin($userId)
			? $this->workspaces->findAll()
			: $this->workspaces->findForUser($userId);
		$out = [];
		foreach ($rows as $ws) {
			$out[] = [
				'id' => (int)$ws->getId(),
				'name' => (string)$ws->getName(),
				'slug' => (string)$ws->getSlug(),
				'archived' => $ws->isArchived(),
				'isDefault' => $ws->isDefaultWorkspace(),
			];
		}

		return $out;
	}

	private function remember(string $userId, int $workspaceId): void
	{
		$this->config->setUserValue(
			$userId,
			'customercheck',
			Constants::USER_CONFIG_LAST_WORKSPACE,
			(string)$workspaceId,
		);
	}
}
