<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\DigestLog;
use OCA\CustomerCheck\Db\DigestLogMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Weekly digest v2 — product §10.5.
 *
 * Per workspace, deep-link to Brief (not raw count spam), ≤1500 chars,
 * up to 3 workspaces per user notification, exactly-once per (week_key, workspace).
 *
 * Claim only after eligible recipients exist; release the claim if delivery
 * cannot run (mirrors ReminderScheduler — never silent “sent” with no notify).
 */
class WeeklyDigestService
{
	public function __construct(
		private readonly DigestLogMapper $digestLogMapper,
		private readonly TodayService $todayService,
		private readonly MoneyService $moneyService,
		private readonly QuotaService $quotaService,
		private readonly NotificationService $notificationService,
		private readonly WorkspaceService $workspaces,
		private readonly BriefService $brief,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IURLGenerator $url,
		private readonly IL10N $l10n,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_DIGEST_ENABLED,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_DIGEST_ENABLED],
		) === '1';
	}

	public function shouldRunNow(?\DateTimeInterface $now = null): bool
	{
		if (!$this->isEnabled()) {
			return false;
		}
		$now = $now ?? $this->timeFactory->getDateTime();
		$weekday = (int)$this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_DIGEST_WEEKDAY,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_DIGEST_WEEKDAY],
		);
		$hour = (int)$this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_DIGEST_HOUR,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_DIGEST_HOUR],
		);
		$isoDow = (int)$now->format('N'); // 1=Mon … 7=Sun
		return $isoDow === $weekday && (int)$now->format('G') === $hour;
	}

	/**
	 * @return array{sent: int, weekKey: string, workspaces: list<int>}
	 */
	public function run(?\DateTimeInterface $now = null): array
	{
		$now = $now ?? $this->timeFactory->getDateTime();
		$weekKey = DateMath::isoWeekKey($now);
		$sent = 0;
		$wsIds = [];

		/** @var array<string, list<array{workspaceId:int,name:string,url:string,snippet:string}>> $byUser */
		$byUser = [];
		/** @var list<DigestLog> $claimed */
		$claimed = [];

		foreach ($this->workspaces->listAll() as $ws) {
			if ($ws->isArchived()) {
				continue;
			}
			$wsId = (int)$ws->getId();
			$settings = $this->workspaces->getSettings($wsId);
			if (empty($settings['digestEnabled'])) {
				continue;
			}

			$recipients = [];
			foreach ($this->workspaces->listMembers($wsId) as $m) {
				$role = (string)$m->getRole();
				if (!in_array($role, ['manager', 'member'], true)) {
					continue;
				}
				$uid = trim((string)$m->getUserId());
				if ($uid !== '') {
					$recipients[] = $uid;
				}
			}
			// Never claim a unique week slot when nobody would be notified.
			if ($recipients === []) {
				continue;
			}

			$brief = $this->brief->build($wsId, $now);
			$snippet = $this->buildSnippet($ws->getName(), $brief, $weekKey);
			$briefUrl = $this->url->linkToRouteAbsolute('customercheck.page.brief', ['workspaceId' => $wsId]);

			$claim = $this->claimWeek($weekKey, $wsId, [
				'wipUsed' => $brief['wipUsed'] ?? 0,
				'wipLimit' => $brief['wipLimit'] ?? 5,
				'url' => $briefUrl,
			], $now);
			if ($claim === null) {
				continue;
			}
			$claimed[] = $claim;

			$sent++;
			$wsIds[] = $wsId;
			foreach ($recipients as $uid) {
				$byUser[$uid][] = [
					'workspaceId' => $wsId,
					'name' => (string)$ws->getName(),
					'url' => $briefUrl,
					'snippet' => $snippet,
				];
			}
		}

		if ($byUser === []) {
			foreach ($claimed as $row) {
				$this->releaseClaim($row);
			}

			return ['sent' => 0, 'weekKey' => $weekKey, 'workspaces' => []];
		}

		try {
			foreach ($byUser as $uid => $entries) {
				$slice = array_slice($entries, 0, 3);
				$body = '';
				foreach ($slice as $e) {
					$line = $e['name'] . ': ' . $e['url'] . "\n" . $e['snippet'] . "\n";
					if (strlen($body) + strlen($line) > 1500) {
						break;
					}
					$body .= $line;
				}
				$this->notificationService->notifyUser(
					$uid,
					Constants::NOTIFY_DIGEST,
					'digest_brief',
					$weekKey . ':' . $uid,
					[
						'weekKey' => $weekKey,
						'body' => $body,
						'workspaceCount' => count($slice),
						'openBrief' => true,
					],
				);
			}
		} catch (\Throwable $e) {
			// Catastrophic failure before/during fan-out — release so the week can retry.
			// Partial per-user notify failures are absorbed inside NotificationService.
			foreach ($claimed as $row) {
				$this->releaseClaim($row);
			}
			throw $e;
		}

		return ['sent' => $sent, 'weekKey' => $weekKey, 'workspaces' => $wsIds];
	}

	/**
	 * Legacy counts helper (tests / mutation). Prefer Brief deep-link path in run().
	 *
	 * @return array{renewals:int,overdue:int,unpaid:int,quota80:int}
	 */
	public function collectCounts(\DateTimeInterface $now, ?int $workspaceId = null): array
	{
		$wsFilter = ($workspaceId !== null && $workspaceId > 0) ? $workspaceId : null;
		$today = $this->todayService->build($now, $wsFilter);
		$renewals = 0;
		$overdue = 0;
		foreach ($today['items'] as $item) {
			if (($item['kind'] ?? '') === 'agreement_expiry' && (int)($item['daysUntil'] ?? 99) <= 30) {
				$renewals++;
			}
			if (($item['kind'] ?? '') === 'activity_overdue') {
				$overdue++;
			}
		}

		$money = $this->moneyService->overview(500, null, $workspaceId);
		$unpaid = (int)($money['meta']['total'] ?? count($money['items'] ?? []));

		$quota80 = 0;
		if ($workspaceId !== null && $workspaceId > 0) {
			foreach ($this->quotaService->partnerQuotaOverview($workspaceId, 500) as $row) {
				if (!empty($row['alert'])) {
					$quota80++;
				}
			}
		}

		return [
			'renewals' => $renewals,
			'overdue' => $overdue,
			'unpaid' => $unpaid,
			'quota80' => $quota80,
		];
	}

	/** @param array<string, mixed> $brief */
	private function buildSnippet(string $wsName, array $brief, string $weekKey): string
	{
		$wip = (int)($brief['wipUsed'] ?? 0);
		$limit = (int)($brief['wipLimit'] ?? 5);
		$cash = (int)(($brief['cash']['expectedInThisMonthCents'] ?? 0));
		$euros = number_format($cash / 100, 2, '.', '');
		return $this->l10n->t(
			'%1$s · %2$s · promises %3$d/%4$d · expected cash €%5$s (deals not counted as cash). Open This week.',
			[$wsName, $weekKey, $wip, $limit, $euros],
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function claimWeek(string $weekKey, int $workspaceId, array $payload, \DateTimeInterface $now): ?DigestLog
	{
		$row = new DigestLog();
		$row->setWeekKey($weekKey);
		$row->setWorkspaceId($workspaceId);
		$row->setSentAt($now instanceof \DateTime ? $now : new \DateTime($now->format('c')));
		$row->setPayloadJson(json_encode($payload, JSON_THROW_ON_ERROR));
		try {
			return $this->digestLogMapper->insert($row);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}
	}

	private function releaseClaim(DigestLog $row): void
	{
		try {
			$this->digestLogMapper->delete($row);
		} catch (\Throwable) {
			// Best-effort — next unique insert may still block; log not available here.
		}
	}
}
