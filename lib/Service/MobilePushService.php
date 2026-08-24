<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\PushTokenMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Companion P2 — Expo push registration and best-effort delivery.
 *
 * Tokens are write-only on the API (never echoed). Delivery failures must never
 * break the in-app NotificationService fan-out that triggered the push.
 */
class MobilePushService
{
	public const EXPO_PUSH_ENDPOINT = 'https://exp.host/--/api/v2/push/send';

	public const MAX_BATCH = 100;

	public const MAX_TOKEN_LENGTH = 4096;

	/** @var list<string> */
	private const PLATFORMS = ['android', 'ios'];

	/** Subjects worth waking a seated phone for (COMPANION-APP §10.3). */
	private const PUSH_SUBJECTS = [
		Constants::NOTIFY_REMINDER,
		Constants::NOTIFY_DIGEST,
		Constants::NOTIFY_ACTIVITY_DUE,
		Constants::NOTIFY_ACTIVITY_ASSIGNED,
		Constants::NOTIFY_DEAL_CHECKLIST,
		Constants::NOTIFY_DEAL_ROTTING,
	];

	private const REQUEST_TIMEOUT = 10;

	public function __construct(
		private readonly PushTokenMapper $tokens,
		private readonly IClientService $clientService,
		private readonly IUserManager $userManager,
		private readonly IFactory $l10nFactory,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function isPushSubject(string $subject): bool
	{
		return in_array($subject, self::PUSH_SUBJECTS, true);
	}

	/** @return 'android'|'ios'|null */
	public static function normalisePlatform(string $platform): ?string
	{
		$platform = strtolower(trim($platform));

		return in_array($platform, self::PLATFORMS, true) ? $platform : null;
	}

	public static function normaliseToken(string $token): ?string
	{
		$token = trim($token);
		if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
			return null;
		}

		return $token;
	}

	/**
	 * @throws ValidationException
	 */
	public function register(string $uid, string $token, string $platform): void
	{
		$normalisedPlatform = self::normalisePlatform($platform);
		if ($normalisedPlatform === null) {
			throw ValidationException::field('VALIDATION', 'platform', 'Invalid platform');
		}
		$normalisedToken = self::normaliseToken($token);
		if ($normalisedToken === null) {
			throw ValidationException::field('VALIDATION', 'token', 'Invalid push token');
		}

		$this->tokens->upsert(
			$uid,
			hash('sha256', $normalisedToken),
			$normalisedToken,
			$normalisedPlatform,
			$this->timeFactory->getTime(),
		);
	}

	/**
	 * Opt-out: delete one token (when provided) or every token for the user.
	 */
	public function unregister(string $uid, ?string $token = null): void
	{
		if ($token === null || trim($token) === '') {
			$this->tokens->deleteAllForUser($uid);

			return;
		}
		$normalised = self::normaliseToken($token);
		if ($normalised === null) {
			return;
		}
		$this->tokens->deleteByHash($uid, hash('sha256', $normalised));
	}

	/**
	 * Best-effort fan-out. Never throws.
	 *
	 * @param list<string> $uids
	 * @param array<string, string|int> $subjectParameters
	 */
	public function dispatch(array $uids, string $subject, array $subjectParameters = []): void
	{
		if (!self::isPushSubject($subject) || $uids === []) {
			return;
		}
		try {
			$rows = $this->tokens->findTokensForUsers($uids);
			if ($rows === []) {
				return;
			}
			$messages = [];
			foreach ($rows as $row) {
				$l = $this->l10nFor($row['uid']);
				$messages[] = [
					'to' => $row['token'],
					'title' => $this->title($l, $subject),
					'body' => $this->body($l, $subject, $subjectParameters),
					'data' => $this->pushData($subject, $subjectParameters),
					'sound' => 'default',
					'priority' => 'high',
				];
			}
			$client = $this->clientService->newClient();
			foreach (array_chunk($messages, self::MAX_BATCH) as $batch) {
				$client->post(self::EXPO_PUSH_ENDPOINT, [
					'json' => $batch,
					'headers' => [
						'Accept' => 'application/json',
						'Content-Type' => 'application/json',
					],
					'timeout' => self::REQUEST_TIMEOUT,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck companion push failed', [
				'app' => Application::APP_ID,
				'subject' => $subject,
				'message' => $e->getMessage(),
			]);
		}
	}

	/**
	 * @param array<string, string|int> $params
	 * @return array<string, mixed>
	 */
	public static function pushData(string $subject, array $params): array
	{
		$data = ['type' => $subject];
		$companyId = (int)($params['companyId'] ?? 0);
		if ($companyId > 0) {
			$data['companyId'] = $companyId;
		}
		$activityId = (int)($params['activityId'] ?? 0);
		if ($activityId > 0) {
			$data['activityId'] = $activityId;
		}
		$workspaceId = (int)($params['workspaceId'] ?? 0);
		if ($workspaceId > 0) {
			$data['workspaceId'] = $workspaceId;
		}
		$dealId = (int)($params['dealId'] ?? 0);
		if ($dealId > 0) {
			$data['dealId'] = $dealId;
		}
		$checklistItemId = (int)($params['checklistItemId'] ?? 0);
		if ($checklistItemId > 0) {
			$data['checklistItemId'] = $checklistItemId;
		}

		return $data;
	}

	private function activityDueBody(IL10N $l, string $company, string $title, int $daysLate): string
	{
		$what = $title !== '' ? $title : $l->t('Activity');
		$where = $company !== '' ? $company : $l->t('a customer');
		if ($daysLate > 0) {
			return $l->t('%1$s for %2$s is overdue (%3$d days) — open Today.', [$what, $where, $daysLate]);
		}

		return $l->t('%1$s for %2$s is due today — open Today.', [$what, $where]);
	}

	private function l10nFor(string $userId): IL10N
	{
		$user = $this->userManager->get($userId);

		return $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));
	}

	private function title(IL10N $l, string $subject): string
	{
		return match ($subject) {
			Constants::NOTIFY_REMINDER => $l->t('Renewal reminder'),
			Constants::NOTIFY_DIGEST => $l->t('CustomerCheck weekly digest'),
			Constants::NOTIFY_ACTIVITY_DUE => $l->t('Activity due'),
			Constants::NOTIFY_ACTIVITY_ASSIGNED => $l->t('Assigned to you'),
			Constants::NOTIFY_DEAL_CHECKLIST => $l->t('Deal checklist'),
			Constants::NOTIFY_DEAL_ROTTING => $l->t('Deal needs follow-up'),
			default => $l->t('CustomerCheck'),
		};
	}

	/**
	 * @param array<string, string|int> $p
	 */
	private function body(IL10N $l, string $subject, array $p): string
	{
		$company = trim((string)($p['companyName'] ?? $p['name'] ?? ''));
		$label = trim((string)($p['label'] ?? ''));

		$title = trim((string)($p['title'] ?? ''));
		$dealTitle = trim((string)($p['dealTitle'] ?? ''));

		return match ($subject) {
			Constants::NOTIFY_REMINDER => $company !== ''
				? $l->t('%1$s — open CustomerCheck for details.', [$company . ($label !== '' ? ': ' . $label : '')])
				: $l->t('Open CustomerCheck for renewal details.'),
			Constants::NOTIFY_DIGEST => $l->t(
				'%1$d renewals, %2$d overdue activities — open Today.',
				[(int)($p['renewals'] ?? 0), (int)($p['overdue'] ?? 0)],
			),
			Constants::NOTIFY_ACTIVITY_DUE => $this->activityDueBody($l, $company, $title, (int)($p['daysLate'] ?? 0)),
			Constants::NOTIFY_ACTIVITY_ASSIGNED => $company !== ''
				? $l->t('%1$s — %2$s', [$title !== '' ? $title : $l->t('To-do'), $company])
				: ($title !== '' ? $title : $l->t('Open CustomerCheck.')),
			Constants::NOTIFY_DEAL_CHECKLIST => $this->dealChecklistBody($l, $company, $dealTitle, $title),
			Constants::NOTIFY_DEAL_ROTTING => $this->dealRottingBody($l, $company, $title, (int)($p['stageIdleDays'] ?? 0)),
			default => $l->t('Open CustomerCheck.'),
		};
	}

	private function dealRottingBody(IL10N $l, string $company, string $dealTitle, int $stageIdleDays): string
	{
		$what = $dealTitle !== '' ? $dealTitle : $l->t('Deal');
		$where = $company !== '' ? $company : $l->t('a customer');
		if ($stageIdleDays > 0) {
			return $l->t('%1$s (%2$s) — %3$d days in stage. Open Pipeline.', [$what, $where, $stageIdleDays]);
		}

		return $l->t('%1$s (%2$s) needs follow-up. Open Pipeline.', [$what, $where]);
	}

	private function dealChecklistBody(IL10N $l, string $company, string $dealTitle, string $task): string
	{
		$what = $task !== '' ? $task : $l->t('Checklist task');
		$where = $dealTitle !== '' ? $dealTitle : ($company !== '' ? $company : $l->t('a deal'));

		return $l->t('%1$s on %2$s — open Today.', [$what, $where]);
	}
}
