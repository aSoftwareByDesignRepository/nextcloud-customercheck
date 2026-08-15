<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ChecklistTemplateItemMapper;
use OCA\CustomerCheck\Db\ChecklistTemplateMapper;
use OCA\CustomerCheck\Db\DealChecklistFile;
use OCA\CustomerCheck\Db\DealChecklistFileMapper;
use OCA\CustomerCheck\Db\DealChecklistItem;
use OCA\CustomerCheck\Db\DealChecklistItemMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DbTransaction;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Deal checklists — handoffs on the deal (CUC-DEAL-CHECKLIST-1).
 *
 * One item-set per deal; templates copy titles onto the deal (no BPM graph).
 */
class DealChecklistService
{
	public const APPLY_APPEND = 'append';
	public const APPLY_REPLACE = 'replace';

	/** Soft cap — Spec §6 Scalability; prevents unbounded Today/deal payload growth. */
	public const MAX_ITEMS_PER_DEAL = 100;

	/** Bound Done/cancelled history returned on deal list (open items always included). */
	public const MAX_CLOSED_HISTORY = 50;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly DealMapper $dealMapper,
		private readonly CompanyService $companies,
		private readonly DealChecklistItemMapper $items,
		private readonly DealChecklistFileMapper $files,
		private readonly ChecklistTemplateMapper $templates,
		private readonly ChecklistTemplateItemMapper $templateItems,
		private readonly WorkspaceMemberMapper $members,
		private readonly FilesAccessService $filesAccess,
		private readonly NotificationService $notifications,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ?TimelineService $timeline = null,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function listTemplates(int $workspaceId): array
	{
		if (!$this->db->tableExists('crm_cl_tmpl')) {
			return [];
		}
		$out = [];
		foreach ($this->templates->findAvailableForWorkspace($workspaceId, true) as $tmpl) {
			$out[] = $this->serTemplate($tmpl);
		}

		return $out;
	}

	/**
	 * Manager Settings list — includes disabled workspace templates + system templates.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listTemplatesForManage(int $workspaceId): array
	{
		if (!$this->db->tableExists('crm_cl_tmpl')) {
			return [];
		}
		$out = [];
		foreach ($this->templates->findAvailableForWorkspace($workspaceId, false) as $tmpl) {
			$out[] = $this->serTemplate($tmpl, true);
		}

		return $out;
	}

	/**
	 * Create a reusable workspace checklist template (BQ-01 b).
	 *
	 * @param array{name?: string, steps?: list<string|array{title?: string}>} $data
	 * @return array<string, mixed>
	 */
	public function createTemplate(int $workspaceId, array $data, string $actor): array
	{
		if ($workspaceId <= 0) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'workspaceId', $this->l10n->t('Pick a workspace.'));
		}
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'name', $this->l10n->t('Enter a template name.'));
		}
		if (mb_strlen($name) > 120) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'name', $this->l10n->t('Name is too long.'));
		}
		$steps = $this->normaliseTemplateSteps($data['steps'] ?? []);
		$key = $this->allocateTemplateKey($workspaceId, $name);
		$now = $this->timeFactory->getDateTime();

		$ownsTxn = DbTransaction::begin($this->db);
		try {
			$tmpl = new \OCA\CustomerCheck\Db\ChecklistTemplate();
			$tmpl->setWorkspaceId($workspaceId);
			$tmpl->setTmplKey($key);
			$tmpl->setName($name);
			$tmpl->setNameDe(null);
			$tmpl->setIsSystem(0);
			$tmpl->setIsEnabled(1);
			$tmpl->setCreatedAt($now);
			$tmpl->setUpdatedAt($now);
			$tmpl = $this->templates->insert($tmpl);
			$this->replaceTemplateSteps((int)$tmpl->getId(), $steps);
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		return $this->serTemplate($tmpl, true);
	}

	/**
	 * Clone a system or workspace template into this workspace (editable copy).
	 *
	 * @return array<string, mixed>
	 */
	public function cloneTemplate(int $sourceId, int $workspaceId, string $actor, ?string $nameOverride = null): array
	{
		if ($workspaceId <= 0) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'workspaceId', $this->l10n->t('Pick a workspace.'));
		}
		$src = $this->requireTemplateForWorkspace($sourceId, $workspaceId, true);
		$baseName = trim((string)($nameOverride ?? ''));
		if ($baseName === '') {
			$baseName = trim((string)$src->getName()) . ' ' . $this->l10n->t('(copy)');
		}
		$steps = [];
		foreach ($this->templateItems->findByTemplate((int)$src->getId()) as $step) {
			$steps[] = (string)$step->getTitle();
		}

		return $this->createTemplate($workspaceId, ['name' => $baseName, 'steps' => $steps], $actor);
	}

	/**
	 * Update a workspace-owned template (not system).
	 *
	 * @param array{name?: string, steps?: list<string|array{title?: string}>, isEnabled?: bool|int} $data
	 * @return array<string, mixed>
	 */
	public function updateTemplate(int $templateId, int $workspaceId, array $data, string $actor): array
	{
		$tmpl = $this->requireTemplateForWorkspace($templateId, $workspaceId, true);
		if ((int)$tmpl->getIsSystem() === 1 || (int)$tmpl->getWorkspaceId() === 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'templateId',
				$this->l10n->t('Built-in templates cannot be edited. Make a copy first.'),
			);
		}
		if (array_key_exists('name', $data)) {
			$name = trim((string)$data['name']);
			if ($name === '') {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'name', $this->l10n->t('Enter a template name.'));
			}
			if (mb_strlen($name) > 120) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'name', $this->l10n->t('Name is too long.'));
			}
			$tmpl->setName($name);
		}
		if (array_key_exists('isEnabled', $data) || array_key_exists('enabled', $data)) {
			$on = $data['isEnabled'] ?? $data['enabled'];
			$tmpl->setIsEnabled(($on === true || $on === 1 || $on === '1') ? 1 : 0);
		}
		$replaceSteps = array_key_exists('steps', $data);
		$steps = $replaceSteps ? $this->normaliseTemplateSteps($data['steps'] ?? []) : [];

		$ownsTxn = DbTransaction::begin($this->db);
		try {
			$tmpl->setUpdatedAt($this->timeFactory->getDateTime());
			$tmpl = $this->templates->update($tmpl);
			if ($replaceSteps) {
				$this->replaceTemplateSteps((int)$tmpl->getId(), $steps);
			}
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		return $this->serTemplate($tmpl, true);
	}

	public function deleteTemplate(int $templateId, int $workspaceId, string $actor): void
	{
		$tmpl = $this->requireTemplateForWorkspace($templateId, $workspaceId, true);
		if ((int)$tmpl->getIsSystem() === 1 || (int)$tmpl->getWorkspaceId() === 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'templateId',
				$this->l10n->t('Built-in templates cannot be deleted. Make a copy first.'),
			);
		}
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			$this->templateItems->deleteByTemplate((int)$tmpl->getId());
			$this->templates->delete($tmpl);
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}
	}

	/** @return array{dealId: int, companyId: int, items: list<array<string, mixed>>} */
	public function listForDeal(int $dealId, ?string $viewerUid = null): array
	{
		$deal = $this->requireDeal($dealId);
		$companyId = (int)$deal->getCompanyId();
		$rows = $this->db->tableExists('crm_deal_cl_item') ? $this->items->findByDeal($dealId) : [];
		$rows = $this->boundClosedHistory($rows);
		$fileMap = $this->filesGrouped(
			array_map(static fn (DealChecklistItem $i): int => (int)$i->getId(), $rows),
			$viewerUid,
		);

		return [
			'dealId' => $dealId,
			'companyId' => $companyId,
			'items' => array_map(fn (DealChecklistItem $i): array => $this->serItem($i, $fileMap[(int)$i->getId()] ?? []), $rows),
		];
	}

	/**
	 * @param array<string, mixed> $data title, assigneeUserId?, dueOn?
	 * @return array<string, mixed>
	 */
	public function addItem(int $dealId, array $data, string $actor): array
	{
		$deal = $this->requireDeal($dealId);
		$company = $this->companies->get((int)$deal->getCompanyId());
		$ws = (int)($company->getWorkspaceId() ?? 0);
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a checklist title.'));
		}
		if (mb_strlen($title) > 160) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Title is too long.'));
		}
		$assignee = $this->normaliseAssignee($ws, $data['assigneeUserId'] ?? $data['assignee'] ?? null);
		$dueOn = $this->parseDueOn($data['dueOn'] ?? null);
		$companyName = $company->getName() ?? '';
		$dealTitle = (string)$deal->getTitle();

		$ownsTxn = DbTransaction::begin($this->db);
		try {
			// Serialize capacity vs concurrent add/apply on the same deal.
			if (!$this->dealMapper->lockRow($dealId)) {
				throw new NotFoundException($this->l10n->t('This deal no longer exists.'), Constants::ERR_NOT_FOUND);
			}
			$this->assertDealItemCapacity($dealId, 1);
			$now = $this->timeFactory->getDateTime();
			$item = new DealChecklistItem();
			$item->setWorkspaceId($ws);
			$item->setDealId($dealId);
			$item->setCompanyId((int)$deal->getCompanyId());
			$item->setSortOrder($this->items->maxSortOrder($dealId) + 1);
			$item->setTitle($title);
			$item->setStatus(DealChecklistItem::STATUS_OPEN);
			$item->setAssigneeUserId($assignee);
			$item->setDueOn($dueOn);
			$item->setCreatedBy($actor);
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);
			$item = $this->items->insert($item);
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}
		$this->notifyAssign($item, $companyName, $dealTitle, $actor);

		return $this->serItem($item, []);
	}

	/**
	 * @param array<string, mixed> $data mode=append|replace, templateId, assignees?: map stepKey→uid
	 * @return array{dealId: int, companyId: int, items: list<array<string, mixed>>, mode: string}
	 */
	public function applyTemplate(int $dealId, array $data, string $actor): array
	{
		$deal = $this->requireDeal($dealId);
		$company = $this->companies->get((int)$deal->getCompanyId());
		$ws = (int)($company->getWorkspaceId() ?? 0);
		$templateId = (int)($data['templateId'] ?? 0);
		if ($templateId <= 0) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'templateId', $this->l10n->t('Pick a checklist template.'));
		}
		try {
			$tmpl = $this->templates->findById($templateId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('Checklist template not found.'), Constants::ERR_NOT_FOUND);
		}
		$tmplWs = (int)$tmpl->getWorkspaceId();
		if ($tmplWs !== 0 && $tmplWs !== $ws) {
			throw new NotFoundException($this->l10n->t('Checklist template not found.'), Constants::ERR_NOT_FOUND);
		}
		if ((int)$tmpl->getIsEnabled() !== 1) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'templateId', $this->l10n->t('That template is turned off.'));
		}
		$mode = strtolower(trim((string)($data['mode'] ?? self::APPLY_APPEND)));
		if (!in_array($mode, [self::APPLY_APPEND, self::APPLY_REPLACE], true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'mode', $this->l10n->t('Choose append or replace.'));
		}
		$steps = $this->templateItems->findByTemplate($templateId);
		if ($steps === []) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'templateId', $this->l10n->t('That template has no steps.'));
		}
		if (count($steps) > 8) {
			$steps = array_slice($steps, 0, 8);
		}
		$defaultAssignee = $this->normaliseAssignee($ws, $data['assigneeUserId'] ?? $data['assignee'] ?? null);
		/** @var array<string, string> $overrides */
		$overrides = is_array($data['assignees'] ?? null) ? $data['assignees'] : [];
		$companyName = $company->getName() ?? '';
		$dealTitle = (string)$deal->getTitle();
		$lang = $this->l10n->getLanguageCode();
		$preferDe = str_starts_with(strtolower($lang), 'de');
		/** @var list<DealChecklistItem> $pendingNotify */
		$pendingNotify = [];

		$emptyAppend = false;
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			if (!$this->dealMapper->lockRow($dealId)) {
				throw new NotFoundException($this->l10n->t('This deal no longer exists.'), Constants::ERR_NOT_FOUND);
			}
			if ($mode === self::APPLY_REPLACE) {
				// BQ-03 / UI: replace *open* tasks only — keep done evidence history.
				// Lock each open row before unlinking files so a concurrent Done cannot
				// lose its attachments after evidence was already validated.
				$openIds = [];
				foreach ($this->items->findByDeal($dealId) as $existing) {
					if ($existing->getStatus() !== DealChecklistItem::STATUS_OPEN) {
						continue;
					}
					$openId = (int)$existing->getId();
					if (!$this->items->lockRow($openId)) {
						continue;
					}
					$fresh = $this->requireItem($openId);
					if ($fresh->getStatus() === DealChecklistItem::STATUS_OPEN) {
						$openIds[] = $openId;
					}
				}
				if ($openIds !== []) {
					$this->files->deleteByItemIds($openIds);
					$this->items->deleteOpenByDeal($dealId);
				}
			}
			$existingOpenTitles = [];
			if ($mode === self::APPLY_APPEND) {
				foreach ($this->items->findByDeal($dealId) as $existing) {
					if ($existing->getStatus() === DealChecklistItem::STATUS_OPEN) {
						$existingOpenTitles[mb_strtolower(trim((string)$existing->getTitle()))] = true;
					}
				}
			}
			$willInsert = 0;
			foreach ($steps as $step) {
				$titleProbe = $preferDe && trim((string)($step->getTitleDe() ?? '')) !== ''
					? (string)$step->getTitleDe()
					: (string)$step->getTitle();
				$titleKeyProbe = mb_strtolower(trim($titleProbe));
				if ($mode === self::APPLY_APPEND && $titleKeyProbe !== '' && isset($existingOpenTitles[$titleKeyProbe])) {
					continue;
				}
				$willInsert++;
			}
			// Append with every title already open — no silent "success" mutation.
			if ($willInsert < 1) {
				DbTransaction::commit($this->db, $ownsTxn);
				$emptyAppend = true;
			} else {
				$this->assertDealItemCapacity($dealId, $willInsert);
				$sort = $this->items->maxSortOrder($dealId);
				$now = $this->timeFactory->getDateTime();
				foreach ($steps as $step) {
					$title = $preferDe && trim((string)($step->getTitleDe() ?? '')) !== ''
						? (string)$step->getTitleDe()
						: (string)$step->getTitle();
					$titleKey = mb_strtolower(trim($title));
					if ($mode === self::APPLY_APPEND && $titleKey !== '' && isset($existingOpenTitles[$titleKey])) {
						continue;
					}
					$sort++;
					$overrideKey = (string)$step->getSortOrder();
					$assignee = $defaultAssignee;
					if (isset($overrides[$overrideKey]) || isset($overrides[(string)$step->getId()])) {
						$assignee = $this->normaliseAssignee(
							$ws,
							$overrides[$overrideKey] ?? $overrides[(string)$step->getId()] ?? null,
						);
					}
					$item = new DealChecklistItem();
					$item->setWorkspaceId($ws);
					$item->setDealId($dealId);
					$item->setCompanyId((int)$deal->getCompanyId());
					$item->setSortOrder($sort);
					$item->setTitle(mb_substr($title, 0, 160));
					$item->setStatus(DealChecklistItem::STATUS_OPEN);
					$item->setAssigneeUserId($assignee);
					$item->setCreatedBy($actor);
					$item->setCreatedAt($now);
					$item->setUpdatedAt($now);
					$item = $this->items->insert($item);
					$pendingNotify[] = $item;
					if ($titleKey !== '') {
						$existingOpenTitles[$titleKey] = true;
					}
				}
				DbTransaction::commit($this->db, $ownsTxn);
			}
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		// listForDeal / notify must run *after* the try — a post-commit throw must never rollBack.
		if ($emptyAppend) {
			$listed = $this->listForDeal($dealId);
			$listed['mode'] = $mode;
			$listed['inserted'] = 0;

			return $listed;
		}

		$listed = $this->listForDeal($dealId);
		$listed['mode'] = $mode;
		$listed['inserted'] = count($pendingNotify);

		// Notify only after a real commit. When nested under MobileIdempotencyService::once(),
		// ownsTxn is false and the outer ledger TX is still open — defer so push cannot fire
		// for rows that later roll back with the outer unit of work.
		if ($ownsTxn) {
			foreach ($pendingNotify as $item) {
				$this->notifyAssign($item, $companyName, $dealTitle, $actor);
			}
		} elseif ($pendingNotify !== []) {
			$listed['_pendingAssignItemIds'] = array_map(
				static fn (DealChecklistItem $item): int => (int)$item->getId(),
				$pendingNotify,
			);
			$listed['_pendingAssignMeta'] = [
				'companyName' => $companyName,
				'dealTitle' => $dealTitle,
				'actor' => $actor,
			];
		}

		return $listed;
	}

	/**
	 * Flush assign pushes deferred when applyTemplate ran inside an outer TX.
	 * Safe no-op when the result has no pending keys (owned TX already notified).
	 *
	 * @param array<string, mixed> $listed
	 */
	public function flushPendingAssignNotifies(array &$listed): void
	{
		$ids = $listed['_pendingAssignItemIds'] ?? null;
		$meta = $listed['_pendingAssignMeta'] ?? null;
		unset($listed['_pendingAssignItemIds'], $listed['_pendingAssignMeta']);
		if (!is_array($ids) || $ids === [] || !is_array($meta)) {
			return;
		}
		$companyName = (string)($meta['companyName'] ?? '');
		$dealTitle = (string)($meta['dealTitle'] ?? '');
		$actor = (string)($meta['actor'] ?? '');
		foreach ($ids as $itemId) {
			$id = (int)$itemId;
			if ($id < 1) {
				continue;
			}
			try {
				$item = $this->requireItem($id);
			} catch (NotFoundException) {
				continue;
			}
			$this->notifyAssign($item, $companyName, $dealTitle, $actor);
		}
	}

	/**
	 * @param array<string, mixed> $data title?, assigneeUserId?, dueOn?, updatedAt
	 * @return array<string, mixed>
	 */
	public function updateItem(int $itemId, array $data, string $actor, ?string $updatedAt): array
	{
		$companyName = '';
		$dealTitle = '';
		$prevAssignee = '';
		$notifyNeeded = false;
		$item = null;

		$ownsTxn = DbTransaction::begin($this->db);
		try {
			if (!$this->items->lockRow($itemId)) {
				throw new NotFoundException($this->l10n->t('Checklist item not found.'), Constants::ERR_NOT_FOUND);
			}
			$item = $this->requireItem($itemId);
			OptimisticLock::assertFresh($item->getUpdatedAt(), $updatedAt);
			if ($item->getStatus() !== DealChecklistItem::STATUS_OPEN) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'status',
					$this->l10n->t('This checklist item is already closed.'),
				);
			}
			$company = $this->companies->get((int)$item->getCompanyId());
			$companyName = $company->getName() ?? '';
			// Company workspace is authoritative (same as add/apply) — heal stale denormalised stamps.
			$ws = (int)($company->getWorkspaceId() ?? 0);
			if ((int)$item->getWorkspaceId() !== $ws) {
				$item->setWorkspaceId($ws);
			}
			try {
				$dealTitle = (string)$this->dealMapper->findById((int)$item->getDealId())->getTitle();
			} catch (DoesNotExistException) {
			}
			$prevAssignee = (string)($item->getAssigneeUserId() ?? '');
			if (array_key_exists('title', $data)) {
				$title = trim((string)$data['title']);
				if ($title === '') {
					throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a checklist title.'));
				}
				$item->setTitle(mb_substr($title, 0, 160));
			}
			if (array_key_exists('assigneeUserId', $data) || array_key_exists('assignee', $data)) {
				$item->setAssigneeUserId($this->normaliseAssignee($ws, $data['assigneeUserId'] ?? $data['assignee'] ?? null));
			}
			if (array_key_exists('dueOn', $data)) {
				$item->setDueOn($this->parseDueOn($data['dueOn']));
			}
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->items->update($item);
			$newAssignee = (string)($item->getAssigneeUserId() ?? '');
			$notifyNeeded = $newAssignee !== '' && $newAssignee !== $prevAssignee;
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		if ($item === null) {
			throw new NotFoundException($this->l10n->t('Checklist item not found.'), Constants::ERR_NOT_FOUND);
		}
		if ($notifyNeeded) {
			$this->notifyAssign($item, $companyName, $dealTitle, $actor);
		}

		return $this->serItem($item, $this->filesGrouped([(int)$item->getId()], $actor)[(int)$item->getId()] ?? []);
	}

	/**
	 * @param array<string, mixed> $data evidenceNote?, updatedAt
	 * @return array<string, mixed>
	 */
	public function completeItem(int $itemId, array $data, string $actor, ?string $updatedAt): array
	{
		$idempotentDone = false;
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			if (!$this->items->lockRow($itemId)) {
				throw new NotFoundException($this->l10n->t('Checklist item not found.'), Constants::ERR_NOT_FOUND);
			}
			$item = $this->requireItem($itemId);
			// Idempotent Done: successful mobile retries must not 422 after the first commit.
			if ($item->getStatus() === DealChecklistItem::STATUS_DONE) {
				DbTransaction::commit($this->db, $ownsTxn);
				$idempotentDone = true;
			} else {
				OptimisticLock::assertFresh($item->getUpdatedAt(), $updatedAt);
				if ($item->getStatus() !== DealChecklistItem::STATUS_OPEN) {
					throw ValidationException::field(Constants::ERR_VALIDATION, 'status', $this->l10n->t('This checklist item is already closed.'));
				}
				$company = $this->companies->get((int)$item->getCompanyId());
				$ws = (int)($company->getWorkspaceId() ?? 0);
				if ((int)$item->getWorkspaceId() !== $ws) {
					$item->setWorkspaceId($ws);
				}
				$note = trim((string)($data['evidenceNote'] ?? $data['evidence'] ?? $data['note'] ?? ''));
				$fileCount = count($this->files->findByItem($itemId));
				if ($note === '' && $fileCount < 1) {
					throw ValidationException::field(
						Constants::ERR_VALIDATION,
						'evidenceNote',
						$this->l10n->t('Add a short note or attach a file before marking done.'),
					);
				}
				if (mb_strlen($note) > 2000) {
					throw ValidationException::field(Constants::ERR_VALIDATION, 'evidenceNote', $this->l10n->t('Note is too long.'));
				}
				$now = $this->timeFactory->getDateTime();
				$item->setStatus(DealChecklistItem::STATUS_DONE);
				$item->setEvidenceNote($note !== '' ? $note : null);
				$item->setDoneBy($actor);
				$item->setDoneAt($now);
				$item->setUpdatedAt($now);
				$item = $this->items->update($item);
				DbTransaction::commit($this->db, $ownsTxn);
			}
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		// Serialize / notify outside the TX try — never rollBack after a successful commit.
		if ($idempotentDone) {
			return $this->serItem($item, $this->filesGrouped([$itemId], $actor)[$itemId] ?? []);
		}
		$this->notifications->withdraw('deal_checklist_item', (string)$itemId);
		$this->timeline?->append((int)$item->getCompanyId(), 'deal_checklist.done', $actor, [
			'dealId' => (int)$item->getDealId(),
			'checklistItemId' => (int)$item->getId(),
			'title' => (string)$item->getTitle(),
		]);

		return $this->serItem($item, $this->filesGrouped([$itemId], $actor)[$itemId] ?? []);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function linkFile(int $itemId, int $fileId, string $actor): array
	{
		if ($fileId < 1) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'fileId', $this->l10n->t('Pick a file to attach.'));
		}
		// Peek company folder before row lock — Files I/O must not hold DB locks.
		$peek = $this->requireItem($itemId);
		$company = $this->companies->get((int)$peek->getCompanyId());
		$folderId = (int)($company->getFilesFolderId() ?? 0);
		if ($folderId < 1) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'fileId',
				$this->l10n->t('Link a company folder in Files before attaching evidence.'),
			);
		}
		$file = $this->filesAccess->assertFileUnderFolder($actor, $fileId, $folderId);

		$ownsTxn = DbTransaction::begin($this->db);
		try {
			if (!$this->items->lockRow($itemId)) {
				throw new NotFoundException($this->l10n->t('Checklist item not found.'), Constants::ERR_NOT_FOUND);
			}
			$item = $this->requireItem($itemId);
			if ($item->getStatus() !== DealChecklistItem::STATUS_OPEN) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'status', $this->l10n->t('This checklist item is already closed.'));
			}
			$row = new DealChecklistFile();
			$row->setItemId($itemId);
			$row->setFileId($fileId);
			$row->setNameSnap(mb_substr($file->getName(), 0, 255));
			$row->setCreatedBy($actor);
			$row->setCreatedAt($this->timeFactory->getDateTime());
			try {
				$row = $this->files->insert($row);
			} catch (\Throwable $e) {
				// Unique (item_id, file_id) only — any other insert failure must not soft-succeed.
				$matched = null;
				foreach ($this->files->findByItem($itemId) as $existing) {
					if ((int)$existing->getFileId() === $fileId) {
						$matched = $existing;
						break;
					}
				}
				if ($matched === null) {
					throw $e;
				}
				$row = $matched;
			}
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$this->items->update($item);
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}

		return [
			'id' => (int)$row->getId(),
			'itemId' => $itemId,
			'fileId' => (int)$row->getFileId(),
			'name' => (string)$row->getNameSnap(),
			'available' => true,
		];
	}

	public function unlinkFile(int $fileRowId, string $actor): void
	{
		$ownsTxn = DbTransaction::begin($this->db);
		try {
			try {
				$row = $this->files->findById($fileRowId);
			} catch (DoesNotExistException) {
				throw new NotFoundException($this->l10n->t('File link not found.'), Constants::ERR_NOT_FOUND);
			}
			$itemId = (int)$row->getItemId();
			if (!$this->items->lockRow($itemId)) {
				throw new NotFoundException($this->l10n->t('Checklist item not found.'), Constants::ERR_NOT_FOUND);
			}
			$item = $this->requireItem($itemId);
			if ($item->getStatus() !== DealChecklistItem::STATUS_OPEN) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'status', $this->l10n->t('This checklist item is already closed.'));
			}
			$this->files->delete($row);
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$this->items->update($item);
			DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		}
	}

	public function cancelOpenForDeal(int $dealId): int
	{
		if (!$this->db->tableExists('crm_deal_cl_item')) {
			return 0;
		}

		return $this->items->cancelOpenForDeal($dealId);
	}

	public function eraseCompany(int $companyId): void
	{
		if (!$this->db->tableExists('crm_deal_cl_item')) {
			return;
		}
		$rows = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_deal_cl_item')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)));
		$r = $qb->executeQuery();
		while ($row = $r->fetch()) {
			$rows[] = (int)$row['id'];
		}
		$r->closeCursor();
		if ($rows !== []) {
			$this->files->deleteByItemIds($rows);
		}
		$this->items->deleteByCompany($companyId);
		// Withdraw after delete so a failed delete leaves notifications for retry/repair.
		foreach ($rows as $itemId) {
			$this->notifications->withdraw('deal_checklist_item', (string)$itemId);
		}
	}

	/**
	 * Open items assigned to $userIds (OOO cover aware) for Today / Command Board.
	 *
	 * @param list<string> $assigneeIds
	 * @return list<array<string, mixed>>
	 */
	public function openAssignedForToday(int $workspaceId, array $assigneeIds, int $limit = 50): array
	{
		if (!$this->db->tableExists('crm_deal_cl_item') || $assigneeIds === []) {
			return [];
		}
		$out = [];
		foreach ($this->items->findOpenAssigned($workspaceId, $assigneeIds, $limit) as $item) {
			$companyName = '';
			$dealTitle = '';
			try {
				$companyName = (string)$this->companies->get((int)$item->getCompanyId())->getName();
			} catch (\Throwable) {
			}
			try {
				$dealTitle = (string)$this->dealMapper->findById((int)$item->getDealId())->getTitle();
			} catch (\Throwable) {
			}
			$due = $item->getDueOn()?->format('Y-m-d');
			$out[] = [
				'kind' => 'deal_checklist',
				'kindLabel' => $this->l10n->t('Deal checklist'),
				'urgency' => $this->checklistUrgency($due),
				'companyId' => (int)$item->getCompanyId(),
				'companyName' => $companyName,
				'dealId' => (int)$item->getDealId(),
				'dealTitle' => $dealTitle,
				'checklistItemId' => (int)$item->getId(),
				'label' => (string)$item->getTitle(),
				'dueDate' => $due,
				'assigneeUserId' => $item->getAssigneeUserId(),
				'updatedAt' => OptimisticLock::format($item->getUpdatedAt()),
			];
		}

		return $out;
	}

	private function checklistUrgency(?string $dueOn): int
	{
		if ($dueOn === null || $dueOn === '') {
			return 42;
		}
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		if ($dueOn < $today) {
			return 28;
		}
		if ($dueOn === $today) {
			return 35;
		}

		return 42;
	}

	public function requireItemForGate(int $itemId): DealChecklistItem
	{
		return $this->requireItem($itemId);
	}

	public function requireItemForFileRow(int $fileRowId): DealChecklistItem
	{
		try {
			$row = $this->files->findById($fileRowId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('File link not found.'), Constants::ERR_NOT_FOUND);
		}

		return $this->requireItem((int)$row->getItemId());
	}

	private function requireDeal(int $dealId): \OCA\CustomerCheck\Db\Deal
	{
		try {
			return $this->dealMapper->findById($dealId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('Deal not found.'), Constants::ERR_NOT_FOUND);
		}
	}

	private function requireItem(int $itemId): DealChecklistItem
	{
		try {
			return $this->items->findById($itemId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('Checklist item not found.'), Constants::ERR_NOT_FOUND);
		}
	}

	private function normaliseAssignee(int $workspaceId, mixed $raw): ?string
	{
		$uid = trim((string)($raw ?? ''));
		if ($uid === '') {
			return null;
		}
		if ($this->members->findMembershipOrNull($workspaceId, $uid) === null) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'assigneeUserId',
				$this->l10n->t('Pick a colleague in this workspace.'),
			);
		}

		return $uid;
	}

	private function parseDueOn(mixed $raw): ?\DateTime
	{
		$due = trim((string)($raw ?? ''));
		if ($due === '') {
			return null;
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'dueOn', $this->l10n->t('Enter a valid due date.'));
		}
		$dt = \DateTime::createFromFormat('Y-m-d', $due);

		return $dt instanceof \DateTime ? $dt : null;
	}

	/**
	 * Keep all open items; truncate older Done/cancelled history for payload bounds.
	 *
	 * @param list<DealChecklistItem> $rows
	 * @return list<DealChecklistItem>
	 */
	private function boundClosedHistory(array $rows): array
	{
		$open = [];
		$closed = [];
		foreach ($rows as $row) {
			if ($row->getStatus() === DealChecklistItem::STATUS_OPEN) {
				$open[] = $row;
			} else {
				$closed[] = $row;
			}
		}
		if (count($closed) <= self::MAX_CLOSED_HISTORY) {
			return $rows;
		}
		usort($closed, static function (DealChecklistItem $a, DealChecklistItem $b): int {
			$at = $a->getUpdatedAt()?->getTimestamp() ?? 0;
			$bt = $b->getUpdatedAt()?->getTimestamp() ?? 0;
			if ($at === $bt) {
				return (int)$b->getId() <=> (int)$a->getId();
			}

			return $bt <=> $at;
		});
		$closed = array_slice($closed, 0, self::MAX_CLOSED_HISTORY);

		return array_merge($open, $closed);
	}

	private function assertDealItemCapacity(int $dealId, int $adding): void
	{
		if ($adding <= 0) {
			return;
		}
		// Cap *open* handoffs only — Done history must not brick long-lived deals.
		$current = $this->items->countOpenByDeal($dealId);
		if ($current + $adding > self::MAX_ITEMS_PER_DEAL) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'dealId',
				$this->l10n->t('This deal already has the maximum number of open checklist items (%s).', [(string)self::MAX_ITEMS_PER_DEAL]),
			);
		}
	}

	private function notifyAssign(DealChecklistItem $item, string $companyName, string $dealTitle, string $actor): void
	{
		$uid = (string)($item->getAssigneeUserId() ?? '');
		if ($uid === '' || $uid === $actor) {
			return;
		}
		$this->notifications->notifyUser(
			$uid,
			Constants::NOTIFY_DEAL_CHECKLIST,
			'deal_checklist_item',
			(string)$item->getId(),
			[
				'workspaceId' => (int)$item->getWorkspaceId(),
				'companyId' => (int)$item->getCompanyId(),
				'companyName' => $companyName,
				'dealId' => (int)$item->getDealId(),
				'dealTitle' => $dealTitle,
				'checklistItemId' => (int)$item->getId(),
				'title' => (string)$item->getTitle(),
				'dueOn' => $item->getDueOn()?->format('Y-m-d'),
			],
		);
		$this->timeline?->append((int)$item->getCompanyId(), 'deal_checklist.assigned', $actor, [
			'dealId' => (int)$item->getDealId(),
			'dealTitle' => $dealTitle,
			'checklistItemId' => (int)$item->getId(),
			'title' => (string)$item->getTitle(),
			'assigneeUserId' => $uid,
		]);
	}

	/**
	 * @param list<int> $itemIds
	 * @return array<int, list<array{id: int, fileId: int, name: string, available: bool}>>
	 */
	private function filesGrouped(array $itemIds, ?string $viewerUid = null): array
	{
		if ($itemIds === [] || !$this->db->tableExists('crm_deal_cl_file')) {
			return [];
		}
		$grouped = $this->files->findGroupedByItemIds($itemIds);
		$out = [];
		$check = $viewerUid !== null && trim($viewerUid) !== '';
		foreach ($grouped as $itemId => $rows) {
			$out[(int)$itemId] = array_map(function (DealChecklistFile $f) use ($check, $viewerUid): array {
				$fileId = (int)$f->getFileId();
				$name = (string)$f->getNameSnap();
				$available = true;
				if ($check) {
					$available = $this->filesAccess->isReadableFile((string)$viewerUid, $fileId);
					if (!$available) {
						$name = $this->l10n->t('File unavailable');
					}
				}

				return [
					'id' => (int)$f->getId(),
					'fileId' => $fileId,
					'name' => $name,
					'available' => $available,
				];
			}, $rows);
		}

		return $out;
	}

	/** @return array<string, mixed> */
	private function serTemplate(\OCA\CustomerCheck\Db\ChecklistTemplate $tmpl, bool $forManage = false): array
	{
		$lang = $this->l10n->getLanguageCode();
		$preferDe = str_starts_with(strtolower($lang), 'de');
		$name = $preferDe && trim((string)($tmpl->getNameDe() ?? '')) !== ''
			? (string)$tmpl->getNameDe()
			: (string)$tmpl->getName();
		$steps = [];
		foreach ($this->templateItems->findByTemplate((int)$tmpl->getId()) as $step) {
			$title = $preferDe && trim((string)($step->getTitleDe() ?? '')) !== ''
				? (string)$step->getTitleDe()
				: (string)$step->getTitle();
			$row = [
				'id' => (int)$step->getId(),
				'sortOrder' => (int)$step->getSortOrder(),
				'title' => $title,
			];
			if ($forManage) {
				$row['titleEn'] = (string)$step->getTitle();
			}
			$steps[] = $row;
		}
		$isSystem = (int)$tmpl->getIsSystem() === 1 || (int)$tmpl->getWorkspaceId() === 0;
		$out = [
			'id' => (int)$tmpl->getId(),
			'key' => (string)$tmpl->getTmplKey(),
			'name' => $name,
			'isSystem' => $isSystem,
			'isEnabled' => (int)$tmpl->getIsEnabled() === 1,
			'steps' => $steps,
			'canEdit' => !$isSystem,
			'canDelete' => !$isSystem,
			'canClone' => true,
		];
		if ($forManage) {
			$out['nameEn'] = (string)$tmpl->getName();
			$out['workspaceId'] = (int)$tmpl->getWorkspaceId();
		}

		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function normaliseTemplateSteps(mixed $raw): array
	{
		if (is_string($raw)) {
			$raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
		}
		if (!is_array($raw)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'steps', $this->l10n->t('Add at least one step.'));
		}
		$steps = [];
		foreach ($raw as $row) {
			$title = is_array($row) ? trim((string)($row['title'] ?? '')) : trim((string)$row);
			if ($title === '') {
				continue;
			}
			if (mb_strlen($title) > 160) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'steps', $this->l10n->t('A step title is too long.'));
			}
			$steps[] = $title;
			if (count($steps) > 8) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'steps',
					$this->l10n->t('Keep templates to 8 steps or fewer.'),
				);
			}
		}
		if ($steps === []) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'steps', $this->l10n->t('Add at least one step.'));
		}

		return $steps;
	}

	/** @param list<string> $steps */
	private function replaceTemplateSteps(int $templateId, array $steps): void
	{
		$this->templateItems->deleteByTemplate($templateId);
		$order = 0;
		foreach ($steps as $title) {
			$order++;
			$item = new \OCA\CustomerCheck\Db\ChecklistTemplateItem();
			$item->setTemplateId($templateId);
			$item->setSortOrder($order);
			$item->setTitle($title);
			$item->setTitleDe(null);
			$item->setInstructions(null);
			$this->templateItems->insert($item);
		}
	}

	private function allocateTemplateKey(int $workspaceId, string $name): string
	{
		$base = strtolower(trim($name));
		$base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? 'checklist';
		$base = trim($base, '_');
		if ($base === '') {
			$base = 'checklist';
		}
		$base = mb_substr($base, 0, 40);
		$key = $base;
		$n = 2;
		while ($this->templates->findByWorkspaceAndKey($workspaceId, $key) !== null) {
			$key = mb_substr($base, 0, 36) . '_' . $n;
			$n++;
			if ($n > 99) {
				$key = 'checklist_' . bin2hex(random_bytes(4));
				break;
			}
		}

		return $key;
	}

	private function requireTemplateForWorkspace(int $templateId, int $workspaceId, bool $allowSystem): \OCA\CustomerCheck\Db\ChecklistTemplate
	{
		try {
			$tmpl = $this->templates->findById($templateId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('Checklist template not found.'), Constants::ERR_NOT_FOUND);
		}
		$tmplWs = (int)$tmpl->getWorkspaceId();
		if ($tmplWs === 0) {
			if (!$allowSystem) {
				throw new NotFoundException($this->l10n->t('Checklist template not found.'), Constants::ERR_NOT_FOUND);
			}

			return $tmpl;
		}
		if ($tmplWs !== $workspaceId) {
			throw new NotFoundException($this->l10n->t('Checklist template not found.'), Constants::ERR_NOT_FOUND);
		}

		return $tmpl;
	}

	/**
	 * @param list<array{id: int, fileId: int, name: string}> $files
	 * @return array<string, mixed>
	 */
	private function serItem(DealChecklistItem $item, array $files): array
	{
		return [
			'id' => (int)$item->getId(),
			'dealId' => (int)$item->getDealId(),
			'companyId' => (int)$item->getCompanyId(),
			'sortOrder' => (int)$item->getSortOrder(),
			'title' => (string)$item->getTitle(),
			'status' => (string)$item->getStatus(),
			'statusLabel' => match ((string)$item->getStatus()) {
				DealChecklistItem::STATUS_DONE => $this->l10n->t('Done'),
				DealChecklistItem::STATUS_CANCELLED => $this->l10n->t('Cancelled'),
				default => $this->l10n->t('Open'),
			},
			'assigneeUserId' => $item->getAssigneeUserId(),
			'dueOn' => $item->getDueOn()?->format('Y-m-d'),
			'evidenceNote' => $item->getEvidenceNote(),
			'doneBy' => $item->getDoneBy(),
			'doneAt' => OptimisticLock::format($item->getDoneAt()),
			'files' => $files,
			'updatedAt' => OptimisticLock::format($item->getUpdatedAt()),
		];
	}
}
