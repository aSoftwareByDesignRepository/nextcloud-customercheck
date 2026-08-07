<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Universal Review Inbox — GNQ §5.
 */
class ReviewService
{
	public const STATUSES = ['open', 'follow_up', 'assigned', 'no_relation', 'snoozed'];
	public const SOURCE_KINDS = ['shared_mail', 'contact_suggest', 'calendar_event', 'file', 'task', 'import_anomaly'];
	public const TERMINAL = ['assigned', 'no_relation'];
	public const NO_RELATION_SUPPRESS_DAYS = 180;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly MailboxService $mailbox,
		private readonly CompanyService $companies,
		private readonly CompanyMapper $companyMapper,
		private readonly PersonMapper $personMapper,
		private readonly WorkspaceService $workspaces,
		private readonly ?TimelineService $timeline = null,
		private readonly ?PersonService $persons = null,
	) {
	}

	/**
	 * Ensure open shared-mail messages have review rows (idempotent).
	 */
	public function syncSharedMail(int $workspaceId): int
	{
		if (!$this->db->tableExists('crm_mail_msg') || !$this->db->tableExists('crm_review_item')) {
			return 0;
		}
		$added = 0;
		foreach ($this->mailbox->unmatched($workspaceId, 200) as $msg) {
			$msgId = (int)$msg['id'];
			$fp = $this->fingerprint('shared_mail', $workspaceId, (string)$msgId);
			if ($this->isFingerprintSuppressed($workspaceId, 'shared_mail', $fp)) {
				continue;
			}
			if ($this->findByFingerprint($workspaceId, 'shared_mail', $fp) !== null) {
				continue;
			}
			$email = (string)($msg['from'] ?? '');
			$suggestions = ReviewMatchLogic::suggest($email, null, $this->suggestionCatalog($workspaceId));
			$this->insertItem([
				'workspace_id' => $workspaceId,
				'source_kind' => 'shared_mail',
				'source_fingerprint' => $fp,
				'source_ref' => ['mailMsgId' => $msgId],
				'subject' => (string)($msg['subject'] ?? ''),
				'snippet' => $email,
				'status' => 'open',
				'suggestion_json' => $suggestions,
			]);
			$added++;
		}

		return $added;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listItems(int $workspaceId, ?string $status = null, ?string $kind = null, string $q = '', int $limit = 100, bool $includeArchived = false): array
	{
		if (!$this->db->tableExists('crm_review_item')) {
			return [];
		}
		$this->wakeSnoozed($workspaceId);
		$sources = $this->workspaces->getReviewSources($workspaceId);
		if (!empty($sources['shared_mail']['enabled'])) {
			$this->syncSharedMail($workspaceId);
		}

		$limit = max(1, min(100, $limit));
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_review_item')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		} else {
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				['open', 'follow_up', 'snoozed'],
				IQueryBuilder::PARAM_STR_ARRAY,
			)));
		}
		if ($kind !== null && $kind !== '' && in_array($kind, self::SOURCE_KINDS, true)) {
			$qb->andWhere($qb->expr()->eq('source_kind', $qb->createNamedParameter($kind)));
		}
		$q = trim($q);
		if ($q !== '') {
			$like = '%' . $this->db->escapeLikeParameter($q) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('subject', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('snippet', $qb->createNamedParameter($like)),
			));
		}
		$qb->orderBy('updated_at', 'DESC')->setMaxResults($limit);
		$r = $qb->executeQuery();
		$catalog = $this->suggestionCatalog($workspaceId, $includeArchived);
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->serializeWithFreshSuggestions($row, $catalog, $includeArchived);
		}
		$r->closeCursor();

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function assign(int $workspaceId, int $id, int $companyId, ?int $dealId, string $actor): array
	{
		$row = $this->requireOpenable($workspaceId, $id);
		if ($companyId <= 0) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'companyId', $this->l10n->t('Pick a company in this workspace.'));
		}
		try {
			$company = $this->companies->get($companyId);
		} catch (\Throwable) {
			throw new NotFoundException($this->l10n->t('This company is not in the current workspace.'));
		}
		if ((int)$company->getWorkspaceId() !== $workspaceId) {
			throw new NotFoundException($this->l10n->t('This company is not in the current workspace.'));
		}
		$overhead = 1;
		if ($dealId !== null && $dealId > 0) {
			$dq = $this->db->getQueryBuilder();
			$dq->select('id')->from('crm_deal')
				->where($dq->expr()->eq('id', $dq->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
				->andWhere($dq->expr()->eq('company_id', $dq->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);
			$dr = $dq->executeQuery();
			$ok = $dr->fetch() !== false;
			$dr->closeCursor();
			if (!$ok) {
				throw ValidationException::field(
					Constants::ERR_DEAL_NOT_IN_COMPANY,
					'dealId',
					$this->l10n->t('Pick a deal that belongs to this company.'),
				);
			}
			$overhead = 0;
		} else {
			$dealId = null;
		}

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_review_item')
			->set('status', $qb->createNamedParameter('assigned'))
			->set('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT))
			->set('deal_id', $dealId !== null
				? $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('overhead', $qb->createNamedParameter($overhead, IQueryBuilder::PARAM_INT))
			->set('assigned_by', $qb->createNamedParameter($actor))
			->set('assigned_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(self::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		if ($affected < 1) {
			throw ValidationException::field(Constants::ERR_ALREADY_TERMINAL, 'status', $this->l10n->t('This review item is already closed.'));
		}

		if ((string)$row['source_kind'] === 'shared_mail') {
			$ref = json_decode((string)$row['source_ref'], true);
			$mailId = (int)($ref['mailMsgId'] ?? 0);
			if ($mailId > 0) {
				$this->mailbox->triageAssign($mailId, $workspaceId, $companyId);
			}
		}

		// GNQ §5.4 — timeline metadata when assigned without a deal (overhead taxonomy, not finance).
		if ($this->timeline !== null) {
			try {
				$this->timeline->append($companyId, 'review.assigned', $actor, [
					'reviewItemId' => $id,
					'dealId' => $dealId,
					'overhead' => $overhead === 1,
					'sourceKind' => (string)$row['source_kind'],
				]);
			} catch (\Throwable) {
				// Assign already committed; never fail the Review decision on audit write.
			}
		}

		// GNQ §8.1 — contact / calendar / mail assign creates an idempotent person stub.
		$this->ensurePersonStubFromReview($row, $companyId, $actor);

		$fresh = $this->requireRow($workspaceId, $id);

		return $this->serialize($fresh);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function snooze(int $workspaceId, int $id, \DateTimeInterface $until): array
	{
		$this->requireOpenable($workspaceId, $id);
		$max = $this->timeFactory->getDateTime()->modify('+30 days');
		if ($until > $max) {
			throw ValidationException::field(Constants::ERR_SNOOZE_TOO_LONG, 'until', $this->l10n->t('Snooze at most 30 days.'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_review_item')
			->set('status', $qb->createNamedParameter('snoozed'))
			->set('snooze_until', $qb->createNamedParameter($until->format('Y-m-d H:i:s')))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->serialize($this->requireRow($workspaceId, $id));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function noRelation(int $workspaceId, int $id, string $note, string $actor): array
	{
		$this->requireOpenable($workspaceId, $id);
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_review_item')
			->set('status', $qb->createNamedParameter('no_relation'))
			->set('resolution_note', $qb->createNamedParameter(mb_substr(trim($note), 0, 500)))
			->set('assigned_by', $qb->createNamedParameter($actor))
			->set('assigned_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->serialize($this->requireRow($workspaceId, $id));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function followUp(int $workspaceId, int $id, string $note): array
	{
		$this->requireOpenable($workspaceId, $id);
		$note = trim($note);
		if (mb_strlen($note) < 3) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'note', $this->l10n->t('Add a short follow-up note (at least 3 characters).'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_review_item')
			->set('status', $qb->createNamedParameter('follow_up'))
			->set('resolution_note', $qb->createNamedParameter(mb_substr($note, 0, 500)))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return $this->serialize($this->requireRow($workspaceId, $id));
	}

	/**
	 * Manager force-reopen of a terminal Review item (GNQ §5.3).
	 *
	 * @return array<string, mixed>
	 */
	public function reopen(int $workspaceId, int $id, string $actor): array
	{
		$row = $this->requireRow($workspaceId, $id);
		if (!in_array((string)$row['status'], self::TERMINAL, true)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'status',
				$this->l10n->t('Only closed review items can be reopened.'),
			);
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_review_item')
			->set('status', $qb->createNamedParameter('open'))
			->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('deal_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('overhead', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('assigned_by', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('assigned_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('resolution_note', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('snooze_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(self::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		if ($affected < 1) {
			throw new ConflictException(
				$this->l10n->t('Someone else changed this review item. Reload and try again.'),
			);
		}

		$fresh = $this->requireRow($workspaceId, $id);
		$fresh['_reopenedBy'] = $actor;

		return $this->serialize($fresh);
	}

	/**
	 * Idempotent enqueue for non-mail Review sources (GNQ §5.2).
	 *
	 * @param array<string, mixed> $sourceRef
	 * @return array{added: bool, item: ?array<string, mixed>}
	 */
	public function enqueueSourceItem(
		int $workspaceId,
		string $kind,
		string $refKey,
		array $sourceRef,
		string $subject,
		string $snippet,
		?string $email = null,
		?string $org = null,
	): array {
		if (!in_array($kind, self::SOURCE_KINDS, true) || $kind === 'shared_mail') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'sourceKind',
				$this->l10n->t('Unknown review source.'),
			);
		}
		$sources = $this->workspaces->getReviewSources($workspaceId);
		if (empty($sources[$kind]['enabled'])) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'sourceKind',
				$this->l10n->t('This review source is turned off for the workspace.'),
			);
		}
		$refKey = trim($refKey);
		if ($refKey === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'ref', $this->l10n->t('Missing source reference.'));
		}
		$fp = $this->fingerprint($kind, $workspaceId, $refKey);
		if ($this->isFingerprintSuppressed($workspaceId, $kind, $fp)) {
			return ['added' => false, 'item' => null];
		}
		if ($this->findByFingerprint($workspaceId, $kind, $fp) !== null) {
			return ['added' => false, 'item' => null];
		}
		$suggestions = ReviewMatchLogic::suggest($email ?? '', $org, $this->suggestionCatalog($workspaceId));
		$this->insertItem([
			'workspace_id' => $workspaceId,
			'source_kind' => $kind,
			'source_fingerprint' => $fp,
			'source_ref' => $sourceRef,
			'subject' => $subject,
			'snippet' => $snippet,
			'status' => 'open',
			'suggestion_json' => $suggestions,
		]);

		$row = $this->findByFingerprint($workspaceId, $kind, $fp);
		if ($row === null) {
			return ['added' => true, 'item' => null];
		}
		$full = $this->requireRow($workspaceId, (int)$row['id']);

		return ['added' => true, 'item' => $this->serializeWithFreshSuggestions($full, $this->suggestionCatalog($workspaceId))];
	}

	/**
	 * Import/value-map normalisation failure → Review queue (GNQ §5.2 import_anomaly).
	 *
	 * @return array{added: bool, item: ?array<string, mixed>}
	 */
	public function enqueueImportAnomaly(
		int $workspaceId,
		string $fieldKey,
		string $raw,
		array $sourceRef = [],
	): array {
		$sources = $this->workspaces->getReviewSources($workspaceId);
		if (empty($sources['import_anomaly']['enabled'])) {
			return ['added' => false, 'item' => null];
		}
		// Non-mail sources require DSGVO ack (GNQ §5.2) — import anomalies included.
		if (empty($sources['import_anomaly']['dsgvoAckAt'])) {
			return ['added' => false, 'item' => null];
		}
		$raw = trim($raw);
		$fieldKey = trim($fieldKey);
		if ($raw === '' || $fieldKey === '') {
			return ['added' => false, 'item' => null];
		}
		$refKey = $fieldKey . '|' . ValueMapLogic::normalizeRaw($raw);
		$subject = $this->l10n->t('Unmapped %s: %s', [$fieldKey, mb_substr($raw, 0, 80)]);
		$ref = array_merge(['fieldKey' => $fieldKey, 'raw' => $raw], $sourceRef);
		// Feed org-name suggestions for ambiguous company rows (GNQ §5.5).
		if ($fieldKey === 'company_name') {
			$ref['org'] = $raw;
		}

		return $this->enqueueSourceItem(
			$workspaceId,
			'import_anomaly',
			$refKey,
			$ref,
			$subject,
			$raw,
			null,
			$fieldKey === 'company_name' ? $raw : null,
		);
	}

	public function countOpen(int $workspaceId): int
	{
		if (!$this->db->tableExists('crm_review_item')) {
			return 0;
		}
		$this->wakeSnoozed($workspaceId);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('crm_review_item')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'follow_up'], IQueryBuilder::PARAM_STR_ARRAY)));
		$r = $qb->executeQuery();
		$n = (int)$r->fetchOne();
		$r->closeCursor();

		return $n;
	}

	private function wakeSnoozed(int $workspaceId): void
	{
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_review_item')
			->set('status', $qb->createNamedParameter('open'))
			->set('snooze_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('snoozed')))
			->andWhere($qb->expr()->lte('snooze_until', $qb->createNamedParameter($now)))
			->executeStatement();
	}

	/** @return array<string, mixed> */
	private function requireOpenable(int $workspaceId, int $id): array
	{
		$row = $this->requireRow($workspaceId, $id);
		if (in_array((string)$row['status'], self::TERMINAL, true)) {
			throw ValidationException::field(Constants::ERR_ALREADY_TERMINAL, 'status', $this->l10n->t('This review item is already closed.'));
		}

		return $row;
	}

	/** @return array<string, mixed> */
	private function requireRow(int $workspaceId, int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_review_item')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('This review item was not found.'));
		}

		return $row;
	}

	/** @return ?array<string, mixed> */
	private function findByFingerprint(int $workspaceId, string $kind, string $fp): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_review_item')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('source_fingerprint', $qb->createNamedParameter($fp)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();

		return $row === false ? null : $row;
	}

	private function isFingerprintSuppressed(int $workspaceId, string $kind, string $fp): bool
	{
		$since = $this->timeFactory->getDateTime()
			->modify('-' . self::NO_RELATION_SUPPRESS_DAYS . ' days')
			->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_review_item')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('source_fingerprint', $qb->createNamedParameter($fp)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('no_relation')))
			->andWhere($qb->expr()->gte('assigned_at', $qb->createNamedParameter($since)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();

		return $row !== false;
	}

	/** @param array<string, mixed> $data */
	private function insertItem(array $data): void
	{
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$suggestions = $data['suggestion_json'] ?? [];
		$ins = $this->db->getQueryBuilder();
		$sugParam = ($suggestions === [] || $suggestions === null)
			? $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
			: $ins->createNamedParameter(json_encode($suggestions, JSON_THROW_ON_ERROR));
		$ins->insert('crm_review_item')->values([
			'workspace_id' => $ins->createNamedParameter((int)$data['workspace_id'], IQueryBuilder::PARAM_INT),
			'source_kind' => $ins->createNamedParameter((string)$data['source_kind']),
			'source_fingerprint' => $ins->createNamedParameter((string)$data['source_fingerprint']),
			'source_ref' => $ins->createNamedParameter(json_encode($data['source_ref'], JSON_THROW_ON_ERROR)),
			'subject' => $ins->createNamedParameter(mb_substr((string)($data['subject'] ?? ''), 0, 512)),
			'snippet' => $ins->createNamedParameter(mb_substr((string)($data['snippet'] ?? ''), 0, 2000)),
			'status' => $ins->createNamedParameter((string)($data['status'] ?? 'open')),
			'overhead' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'suggestion_json' => $sugParam,
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
	}

	private function fingerprint(string $kind, int $workspaceId, string $ref): string
	{
		return hash('sha256', $kind . '|' . $workspaceId . '|' . $ref);
	}

	/**
	 * @return list<array{id: int, name: string, emails: list<string>, domains: list<string>, archived?: bool}>
	 */
	private function suggestionCatalog(int $workspaceId, bool $includeArchived = false): array
	{
		$filters = ['workspaceId' => $workspaceId];
		if ($includeArchived) {
			$filters['includeArchived'] = true;
		}
		$companies = $this->companyMapper->search($filters, 500, 0);
		$ids = [];
		foreach ($companies as $c) {
			$ids[] = (int)$c->getId();
		}
		$grouped = $ids === [] ? [] : $this->personMapper->findGroupedByCompanyIds($ids);
		$out = [];
		foreach ($companies as $c) {
			$id = (int)$c->getId();
			$emails = [];
			$domains = [];
			foreach ($grouped[$id] ?? [] as $p) {
				$email = strtolower(trim((string)($p->getEmail() ?? '')));
				if ($email === '') {
					continue;
				}
				$emails[] = $email;
				$d = MailMatchLogic::domainOf($email);
				if ($d !== null) {
					$domains[] = $d;
				}
			}
			$website = trim((string)($c->getWebsite() ?? ''));
			if ($website !== '') {
				$host = parse_url($website, PHP_URL_HOST);
				if (is_string($host) && $host !== '') {
					$domains[] = strtolower($host);
				}
			}
			$out[] = [
				'id' => $id,
				'name' => (string)$c->getName(),
				'emails' => array_values(array_unique($emails)),
				'domains' => array_values(array_unique($domains)),
				'archived' => $c->isArchived() || (string)$c->getStatus() === 'churned',
			];
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<array{id: int, name: string, emails: list<string>, domains: list<string>, archived?: bool}> $catalog
	 * @return array<string, mixed>
	 */
	private function serializeWithFreshSuggestions(array $row, array $catalog, bool $includeArchived = false): array
	{
		$email = null;
		$org = null;
		$kind = (string)$row['source_kind'];
		$ref = json_decode((string)$row['source_ref'], true);
		if (!is_array($ref)) {
			$ref = [];
		}
		if ($kind === 'shared_mail') {
			$email = (string)($row['snippet'] ?? '');
		} elseif ($kind === 'import_anomaly' && ($ref['fieldKey'] ?? '') === 'company_name') {
			// Prefer raw import name — subject is "Unmapped company_name: …" and breaks scoring.
			$org = trim((string)($ref['raw'] ?? $ref['org'] ?? $row['snippet'] ?? ''));
		} else {
			$org = (string)($row['subject'] ?? '');
			if (!empty($ref['email'])) {
				$email = (string)$ref['email'];
			}
			if (!empty($ref['org'])) {
				$org = (string)$ref['org'];
			}
		}
		$suggestions = ReviewMatchLogic::suggest($email, $org, $catalog, $includeArchived);
		$row['suggestion_json'] = json_encode($suggestions, JSON_THROW_ON_ERROR);

		return $this->serialize($row);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function serialize(array $row): array
	{
		$suggestions = [];
		if (!empty($row['suggestion_json'])) {
			$decoded = json_decode((string)$row['suggestion_json'], true);
			if (is_array($decoded)) {
				$suggestions = $decoded;
			}
		}
		$ref = json_decode((string)$row['source_ref'], true);
		if (!is_array($ref)) {
			$ref = [];
		}

		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'sourceKind' => (string)$row['source_kind'],
			'subject' => (string)($row['subject'] ?? ''),
			'snippet' => (string)($row['snippet'] ?? ''),
			'status' => (string)$row['status'],
			'snoozeUntil' => $row['snooze_until'] ?? null,
			'companyId' => isset($row['company_id']) && $row['company_id'] !== null ? (int)$row['company_id'] : null,
			'dealId' => isset($row['deal_id']) && $row['deal_id'] !== null ? (int)$row['deal_id'] : null,
			'overhead' => (int)($row['overhead'] ?? 0) === 1,
			'suggestions' => $suggestions,
			'sourceRef' => $ref,
			'assignedBy' => $row['assigned_by'] ?? null,
			'assignedAt' => $row['assigned_at'] ?? null,
			'resolutionNote' => $row['resolution_note'] ?? null,
			'updatedAt' => $row['updated_at'] ?? null,
		];
	}

	/**
	 * Apply ISO country for an import_anomaly Review item (GNQ §7).
	 * Optionally learns the value-map and always updates the linked company when present.
	 *
	 * @return array<string, mixed>
	 */
	public function applyCountryMapping(
		int $workspaceId,
		int $id,
		string $canonical,
		bool $learn,
		string $actor,
		?ValueMapService $valueMaps,
	): array {
		$row = $this->requireOpenable($workspaceId, $id);
		if ((string)$row['source_kind'] !== 'import_anomaly') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'sourceKind',
				$this->l10n->t('Country mapping applies only to import anomalies.'),
			);
		}
		$ref = json_decode((string)$row['source_ref'], true);
		if (!is_array($ref)) {
			$ref = [];
		}
		$fieldKey = trim((string)($ref['fieldKey'] ?? 'country'));
		if ($fieldKey !== 'country') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'fieldKey',
				$this->l10n->t('Only country mapping is supported here.'),
			);
		}
		$raw = trim((string)($ref['raw'] ?? $row['snippet'] ?? ''));
		if ($valueMaps === null) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'canonical',
				$this->l10n->t('Value maps are not available.'),
			);
		}
		$mapped = $valueMaps->apply($workspaceId, 'country', $raw, $canonical, $learn, $actor);
		$companyId = (int)($ref['companyId'] ?? 0);
		$companyUpdated = false;
		if ($companyId > 0) {
			$company = $this->companies->get($companyId);
			if ((int)$company->getWorkspaceId() !== $workspaceId) {
				throw new NotFoundException($this->l10n->t('This company is not in the current workspace.'));
			}
			$stamp = OptimisticLock::format($company->getUpdatedAt());
			$this->companies->update(
				$companyId,
				['country' => (string)$mapped['canonical']],
				$actor,
				$stamp,
			);
			$companyUpdated = true;
		}
		$note = $learn
			? $this->l10n->t('Mapped via Apply & learn')
			: $this->l10n->t('Applied once without learning');
		$closed = $this->noRelation($workspaceId, $id, $note, $actor);

		return [
			'item' => $closed,
			'canonical' => (string)$mapped['canonical'],
			'learned' => !empty($mapped['learned']),
			'companyId' => $companyId > 0 ? $companyId : null,
			'companyUpdated' => $companyUpdated,
		];
	}

	/**
	 * Idempotent person stub when assigning contact/calendar/mail Review items (GNQ §8.1).
	 *
	 * @param array<string, mixed> $row
	 */
	private function ensurePersonStubFromReview(array $row, int $companyId, string $actor): void
	{
		if ($this->persons === null || $companyId <= 0) {
			return;
		}
		$kind = (string)($row['source_kind'] ?? '');
		if (!in_array($kind, ['contact_suggest', 'calendar_event', 'shared_mail'], true)) {
			return;
		}
		$ref = json_decode((string)($row['source_ref'] ?? ''), true);
		if (!is_array($ref)) {
			$ref = [];
		}
		$email = mb_strtolower(trim((string)($ref['email'] ?? '')));
		if ($email === '' && $kind === 'shared_mail') {
			$email = mb_strtolower(trim((string)($row['snippet'] ?? '')));
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				$email = '';
			}
		}
		$name = trim((string)($ref['name'] ?? $ref['displayName'] ?? ''));
		if ($name === '') {
			$name = trim((string)($row['subject'] ?? ''));
		}
		if ($email === '' && $name === '') {
			return;
		}
		$workspaceId = (int)($row['workspace_id'] ?? 0);
		$ownsTxn = \OCA\CustomerCheck\Support\DbTransaction::begin($this->db);
		try {
			$this->companies->lockRow($companyId);
			if ($email !== '' && $workspaceId > 0) {
				foreach ($this->personMapper->findByEmail($email, $workspaceId) as $existing) {
					if ((int)$existing->getCompanyId() === $companyId) {
						\OCA\CustomerCheck\Support\DbTransaction::commit($this->db, $ownsTxn);

						return;
					}
				}
			}
			$this->persons->create($companyId, [
				'name' => $name !== '' ? mb_substr($name, 0, 255) : $email,
				'email' => $email !== '' ? $email : null,
			], $actor);
			\OCA\CustomerCheck\Support\DbTransaction::commit($this->db, $ownsTxn);
		} catch (\Throwable) {
			\OCA\CustomerCheck\Support\DbTransaction::rollBack($this->db, $ownsTxn);
			// Assign already succeeded — never fail the Review decision on stub create.
		}
	}
}
