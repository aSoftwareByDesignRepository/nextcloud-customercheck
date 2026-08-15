<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Google / Microsoft OAuth connect for personal mail (CUC-PERSONAL-MAIL-1 M2 / MH-02).
 *
 * Client id/secret live in app config (admin). Tokens encrypted at rest on crm_mailbox.
 */
class PersonalMailOAuthService
{
	public const PROVIDER_GOOGLE = 'google';
	public const PROVIDER_MICROSOFT = 'microsoft';

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $url,
		private readonly ICrypto $crypto,
		private readonly ISecureRandom $random,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly PersonalMailService $personalMail,
		private readonly WorkspaceService $workspaces,
		private readonly IDBConnection $db,
		private readonly ICacheFactory $cacheFactory,
		private readonly RateLimitService $rateLimit,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $data Must include dsgvoAck=true (member consent before redirect).
	 * @return array{authorizeUrl: string, state: string, provider: string, callbackUrl: string}
	 */
	public function beginAuthorize(int $workspaceId, string $userId, string $provider, array $data = []): array
	{
		$this->personalMail->assertPersonalMailEnabled($workspaceId);
		if (empty($data['dsgvoAck'])) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'dsgvoAck',
				$this->l10n->t('Confirm that CustomerCheck may sync mail from your personal mailbox into this workspace.'),
			);
		}
		if (!$this->rateLimit->allow(
			'pm_oauth:' . $userId,
			Constants::PERSONAL_MAIL_OAUTH_USER_LIMIT,
			Constants::PERSONAL_MAIL_OAUTH_USER_WINDOW,
			true,
		)) {
			throw ValidationException::field(
				Constants::ERR_RATE_LIMITED,
				'rate',
				$this->l10n->t('Too many connect attempts. Try again later.'),
			);
		}
		$provider = strtolower(trim($provider));
		if ($provider !== self::PROVIDER_GOOGLE && $provider !== self::PROVIDER_MICROSOFT) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'provider',
				$this->l10n->t('Choose Google or Microsoft to connect.'),
			);
		}
		$clientId = $this->clientId($provider);
		if ($clientId === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'provider',
				$this->l10n->t('OAuth is not configured on this server yet. Use IMAP/app password, or ask an admin to set Google/Microsoft client credentials.'),
			);
		}
		$state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$client = strtolower(trim((string)($data['client'] ?? 'web')));
		if ($client !== 'native') {
			$client = 'web';
		}
		$cache = $this->cacheFactory->createDistributed('customercheck_oauth');
		$cache->set('pm:' . $state, [
			'workspaceId' => $workspaceId,
			'userId' => $userId,
			'provider' => $provider,
			'dsgvoAck' => true,
			'client' => $client,
			'exp' => $this->timeFactory->getTime() + 600,
		], 600);

		$redirectUri = $this->callbackUrl();
		if ($provider === self::PROVIDER_GOOGLE) {
			$scope = rawurlencode('https://mail.google.com/ https://www.googleapis.com/auth/userinfo.email');
			$authorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth'
				. '?client_id=' . rawurlencode($clientId)
				. '&redirect_uri=' . rawurlencode($redirectUri)
				. '&response_type=code'
				. '&scope=' . $scope
				. '&access_type=offline'
				. '&prompt=consent'
				. '&state=' . rawurlencode($state);
		} else {
			$scope = rawurlencode('https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send offline_access openid email');
			$authorizeUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'
				. '?client_id=' . rawurlencode($clientId)
				. '&redirect_uri=' . rawurlencode($redirectUri)
				. '&response_type=code'
				. '&scope=' . $scope
				. '&state=' . rawurlencode($state);
		}

		return [
			'authorizeUrl' => $authorizeUrl,
			'state' => $state,
			'provider' => $provider,
			'callbackUrl' => $redirectUri,
			'client' => $client,
		];
	}

	/**
	 * Companion AuthSession shares the provider redirect URI with the web callback.
	 * When client=native, the browser hit must NOT exchange (state stays for POST /oauth/complete).
	 */
	public function isNativeOAuthState(string $state): bool
	{
		$state = trim($state);
		if ($state === '') {
			return false;
		}
		$cache = $this->cacheFactory->createDistributed('customercheck_oauth');
		$payload = $cache->get('pm:' . $state);
		if (!is_array($payload) || ($payload['exp'] ?? 0) < $this->timeFactory->getTime()) {
			return false;
		}

		return ($payload['client'] ?? '') === 'native';
	}

	/**
	 * Exchange code → store personal mailbox with OAuth tokens (IMAP XOAUTH2 hosts).
	 *
	 * @return array<string, mixed>
	 */
	public function completeAuthorize(string $state, string $code, string $sessionUserId, ?int $expectedWorkspaceId = null): array
	{
		$state = trim($state);
		$code = trim($code);
		$sessionUserId = trim($sessionUserId);
		if ($state === '' || $code === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'state', $this->l10n->t('OAuth state is missing. Start connect again.'));
		}
		$cache = $this->cacheFactory->createDistributed('customercheck_oauth');
		$payload = $cache->get('pm:' . $state);
		$cache->remove('pm:' . $state);
		if (!is_array($payload) || ($payload['exp'] ?? 0) < $this->timeFactory->getTime()) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'state', $this->l10n->t('OAuth session expired. Start connect again.'));
		}
		$workspaceId = (int)$payload['workspaceId'];
		$userId = (string)$payload['userId'];
		if ($sessionUserId === '' || !hash_equals($userId, $sessionUserId)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'state',
				$this->l10n->t('OAuth session does not match the signed-in user. Start connect again.'),
			);
		}
		if ($expectedWorkspaceId !== null && $expectedWorkspaceId > 0 && $expectedWorkspaceId !== $workspaceId) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'workspaceId',
				$this->l10n->t('OAuth session does not match this workspace. Start connect again.'),
			);
		}
		$provider = (string)$payload['provider'];
		$this->personalMail->assertPersonalMailEnabled($workspaceId);
		if (empty($payload['dsgvoAck'])) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'dsgvoAck',
				$this->l10n->t('Confirm that CustomerCheck may sync mail from your personal mailbox into this workspace.'),
			);
		}

		$token = $this->exchangeCode($provider, $code);
		$email = strtolower(trim((string)($token['email'] ?? '')));
		if ($email === '' || !str_contains($email, '@')) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'email', $this->l10n->t('Could not read your email address from the provider.'));
		}

		$hosts = $provider === self::PROVIDER_GOOGLE
			? ['imap' => 'imap.gmail.com', 'smtp' => 'smtp.gmail.com']
			: ['imap' => 'outlook.office365.com', 'smtp' => 'smtp.office365.com'];

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$accessEnc = $this->crypto->encrypt((string)$token['access_token']);
		$refreshEnc = isset($token['refresh_token']) && $token['refresh_token'] !== ''
			? $this->crypto->encrypt((string)$token['refresh_token'])
			: null;
		$expiresAt = isset($token['expires_in'])
			? $this->timeFactory->getDateTime()->modify('+' . (int)$token['expires_in'] . ' seconds')->format('Y-m-d H:i:s')
			: null;

		// Reuse connect path constraints (one personal / user / WS) under the email lock.
		return $this->personalMail->withEmailConnectLock($workspaceId, $email, function () use (
			$workspaceId,
			$userId,
			$provider,
			$email,
			$hosts,
			$now,
			$accessEnc,
			$refreshEnc,
			$expiresAt,
		) {
			$existing = $this->personalMail->listMine($workspaceId, $userId);
			$healId = 0;
			foreach ($existing as $box) {
				$boxEmail = strtolower(trim((string)($box['emailAddress'] ?? '')));
				if ($boxEmail === $email) {
					$healId = (int)($box['id'] ?? 0);
					break;
				}
			}
			// US-002 ok⇄error: re-consent with the same address heals tokens in place (no Disconnect wipe).
			if ($healId > 0) {
				return $this->healExistingOAuthMailbox(
					$workspaceId,
					$userId,
					$healId,
					$provider,
					$email,
					$hosts,
					$now,
					$accessEnc,
					$refreshEnc,
					$expiresAt,
				);
			}
			if (count($existing) >= Constants::PERSONAL_MAIL_MAX_PER_USER) {
				throw ValidationException::field(
					Constants::ERR_MAILBOX_LIMIT,
					'mailbox',
					$this->l10n->t('You already connected the maximum number of personal mailboxes for this workspace.'),
				);
			}
			$this->personalMail->assertEmailAddressAvailable($workspaceId, $email, null);

			try {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('crm_mailbox')->values([
					'workspace_id' => $ins->createNamedParameter($workspaceId),
					'kind' => $ins->createNamedParameter(MailAclLogic::KIND_PERSONAL),
					'owner_user_id' => $ins->createNamedParameter($userId),
					'auth_kind' => $ins->createNamedParameter($provider),
					'label' => $ins->createNamedParameter(mb_substr($email, 0, 128)),
					'email_address' => $ins->createNamedParameter($email),
					'imap_host' => $ins->createNamedParameter($hosts['imap']),
					'imap_port' => $ins->createNamedParameter(993),
					'imap_enc' => $ins->createNamedParameter('ssl'),
					'smtp_host' => $ins->createNamedParameter($hosts['smtp']),
					'smtp_port' => $ins->createNamedParameter(465),
					'smtp_enc' => $ins->createNamedParameter('ssl'),
					'username' => $ins->createNamedParameter($email),
					'secret_enc' => $ins->createNamedParameter(''), // OAuth uses token_enc
					'token_enc' => $ins->createNamedParameter($accessEnc),
					'refresh_enc' => $ins->createNamedParameter($refreshEnc),
					'token_expires_at' => $ins->createNamedParameter($expiresAt),
					'dsgvo_ack_at' => $ins->createNamedParameter($now),
					'dsgvo_ack_by' => $ins->createNamedParameter($userId),
					'status' => $ins->createNamedParameter('ok'),
					'auth_fail_count' => $ins->createNamedParameter(0),
					'created_by' => $ins->createNamedParameter($userId),
					'created_at' => $ins->createNamedParameter($now),
					'updated_at' => $ins->createNamedParameter($now),
				])->executeStatement();
				$id = (int)$this->db->lastInsertId('crm_mailbox');
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw ValidationException::field(
						PersonalMailService::ERR_MAIL_ACCOUNT_CONFLICT,
						'emailAddress',
						$this->l10n->t('This email address is already connected in this workspace as a personal or team mailbox.'),
					);
				}
				throw $e;
			}

			// Default: sync INBOX private.
			$fld = $this->db->getQueryBuilder();
			$fld->insert('crm_mail_fld')->values([
				'mailbox_id' => $fld->createNamedParameter($id),
				'folder_path' => $fld->createNamedParameter('INBOX'),
				'sync_enabled' => $fld->createNamedParameter(1),
				'share_with_workspace' => $fld->createNamedParameter(0),
				'created_at' => $fld->createNamedParameter($now),
			])->executeStatement();

			return $this->accountPayloadAfterConnect($workspaceId, $userId, $id, $provider, $email, $hosts, $now, 'personal_mail.connected');
		});
	}

	/**
	 * Re-consent OAuth for an owned personal mailbox (same email) — clears quarantine without erase.
	 *
	 * @param array{imap: string, smtp: string} $hosts
	 * @return array<string, mixed>
	 */
	private function healExistingOAuthMailbox(
		int $workspaceId,
		string $userId,
		int $mailboxId,
		string $provider,
		string $email,
		array $hosts,
		string $now,
		string $accessEnc,
		?string $refreshEnc,
		?string $expiresAt,
	): array {
		$this->personalMail->assertEmailAddressAvailable($workspaceId, $email, $mailboxId);
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('auth_kind', $qb->createNamedParameter($provider))
			->set('label', $qb->createNamedParameter(mb_substr($email, 0, 128)))
			->set('email_address', $qb->createNamedParameter($email))
			->set('imap_host', $qb->createNamedParameter($hosts['imap']))
			->set('imap_port', $qb->createNamedParameter(993))
			->set('imap_enc', $qb->createNamedParameter('ssl'))
			->set('smtp_host', $qb->createNamedParameter($hosts['smtp']))
			->set('smtp_port', $qb->createNamedParameter(465))
			->set('smtp_enc', $qb->createNamedParameter('ssl'))
			->set('username', $qb->createNamedParameter($email))
			->set('secret_enc', $qb->createNamedParameter(''))
			->set('token_enc', $qb->createNamedParameter($accessEnc))
			->set('token_expires_at', $qb->createNamedParameter($expiresAt))
			->set('dsgvo_ack_at', $qb->createNamedParameter($now))
			->set('dsgvo_ack_by', $qb->createNamedParameter($userId))
			->set('status', $qb->createNamedParameter('ok'))
			->set('auth_fail_count', $qb->createNamedParameter(0))
			->set('last_error', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now));
		if ($refreshEnc !== null && $refreshEnc !== '') {
			$qb->set('refresh_enc', $qb->createNamedParameter($refreshEnc));
		}
		$qb->set('sync_fail_streak', $qb->createNamedParameter(0))
			->set('sync_cooldown_until', $qb->createNamedParameter(null));
		try {
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL)))
				->executeStatement();
		} catch (\Throwable) {
			// Mid-upgrade without dial-backoff columns — retry without them.
			$qb2 = $this->db->getQueryBuilder();
			$qb2->update('crm_mailbox')
				->set('auth_kind', $qb2->createNamedParameter($provider))
				->set('label', $qb2->createNamedParameter(mb_substr($email, 0, 128)))
				->set('email_address', $qb2->createNamedParameter($email))
				->set('imap_host', $qb2->createNamedParameter($hosts['imap']))
				->set('imap_port', $qb2->createNamedParameter(993))
				->set('imap_enc', $qb2->createNamedParameter('ssl'))
				->set('smtp_host', $qb2->createNamedParameter($hosts['smtp']))
				->set('smtp_port', $qb2->createNamedParameter(465))
				->set('smtp_enc', $qb2->createNamedParameter('ssl'))
				->set('username', $qb2->createNamedParameter($email))
				->set('secret_enc', $qb2->createNamedParameter(''))
				->set('token_enc', $qb2->createNamedParameter($accessEnc))
				->set('token_expires_at', $qb2->createNamedParameter($expiresAt))
				->set('dsgvo_ack_at', $qb2->createNamedParameter($now))
				->set('dsgvo_ack_by', $qb2->createNamedParameter($userId))
				->set('status', $qb2->createNamedParameter('ok'))
				->set('auth_fail_count', $qb2->createNamedParameter(0))
				->set('last_error', $qb2->createNamedParameter(null))
				->set('updated_at', $qb2->createNamedParameter($now));
			if ($refreshEnc !== null && $refreshEnc !== '') {
				$qb2->set('refresh_enc', $qb2->createNamedParameter($refreshEnc));
			}
			$qb2->where($qb2->expr()->eq('id', $qb2->createNamedParameter($mailboxId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb2->expr()->eq('workspace_id', $qb2->createNamedParameter($workspaceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb2->expr()->eq('owner_user_id', $qb2->createNamedParameter($userId)))
				->andWhere($qb2->expr()->eq('kind', $qb2->createNamedParameter(MailAclLogic::KIND_PERSONAL)))
				->executeStatement();
		}

		return $this->accountPayloadAfterConnect($workspaceId, $userId, $mailboxId, $provider, $email, $hosts, $now, 'personal_mail.reconnected');
	}

	/**
	 * @param array{imap: string, smtp: string} $hosts
	 * @return array<string, mixed>
	 */
	private function accountPayloadAfterConnect(
		int $workspaceId,
		string $userId,
		int $id,
		string $provider,
		string $email,
		array $hosts,
		string $now,
		string $event,
	): array {
		$mine = $this->personalMail->listMine($workspaceId, $userId);
		foreach ($mine as $box) {
			if ((int)$box['id'] === $id) {
				$this->logger->info($event, [
					'event' => $event,
					'workspaceId' => $workspaceId,
					'mailboxId' => $id,
					'authKind' => $provider,
					'actor' => $userId,
				]);
				return $box;
			}
		}

		$this->logger->info($event, [
			'event' => $event,
			'workspaceId' => $workspaceId,
			'mailboxId' => $id,
			'authKind' => $provider,
			'actor' => $userId,
		]);
		return [
			'id' => $id,
			'kind' => MailAclLogic::KIND_PERSONAL,
			'ownerUserId' => $userId,
			'authKind' => $provider,
			'label' => mb_substr($email, 0, 128),
			'emailAddress' => $email,
			'imapHost' => $hosts['imap'],
			'imapPort' => 993,
			'imapEnc' => 'ssl',
			'smtpHost' => $hosts['smtp'],
			'smtpPort' => 465,
			'smtpEnc' => 'ssl',
			'username' => $email,
			'status' => 'ok',
			'authFailCount' => 0,
			'lastSyncAt' => null,
			'lastError' => null,
			'dsgvoAckAt' => $now,
			'hasSecret' => false,
			'signatureHtml' => '',
			'signaturePlain' => '',
			'hasSignature' => false,
			'folders' => [
				[
					'path' => 'INBOX',
					'syncEnabled' => true,
					'shareWithWorkspace' => false,
				],
			],
			'sharedFolderCount' => 0,
		];
	}

	public function isConfigured(string $provider): bool
	{
		return $this->clientId($provider) !== '' && $this->clientSecret($provider) !== '';
	}

	/**
	 * @return array{
	 *   google: bool,
	 *   microsoft: bool,
	 *   providers: list<array{id: string, label: string, configured: bool}>,
	 *   redirectUri: string
	 * }
	 */
	public function providerStatus(): array
	{
		$google = $this->isConfigured(self::PROVIDER_GOOGLE);
		$microsoft = $this->isConfigured(self::PROVIDER_MICROSOFT);

		return [
			'google' => $google,
			'microsoft' => $microsoft,
			'redirectUri' => $this->callbackUrl(),
			'providers' => [
				[
					'id' => self::PROVIDER_GOOGLE,
					'label' => $this->l10n->t('Connect with Google'),
					'configured' => $google,
				],
				[
					'id' => self::PROVIDER_MICROSOFT,
					'label' => $this->l10n->t('Connect with Microsoft'),
					'configured' => $microsoft,
				],
			],
		];
	}

	/**
	 * App-admin view — never returns secret plaintext.
	 *
	 * @return array<string, mixed>
	 */
	public function adminConfigStatus(): array
	{
		return [
			'redirectUri' => $this->callbackUrl(),
			'googleClientId' => $this->clientId(self::PROVIDER_GOOGLE),
			'googleHasSecret' => $this->clientSecret(self::PROVIDER_GOOGLE) !== '',
			'microsoftClientId' => $this->clientId(self::PROVIDER_MICROSOFT),
			'microsoftHasSecret' => $this->clientSecret(self::PROVIDER_MICROSOFT) !== '',
			'providers' => $this->providerStatus()['providers'],
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function saveAdminConfig(array $data): array
	{
		$googleId = trim((string)($data['googleClientId'] ?? ''));
		$msId = trim((string)($data['microsoftClientId'] ?? ''));
		$this->appConfig->setValueString(Application::APP_ID, 'oauth_google_client_id', $googleId);
		$this->appConfig->setValueString(Application::APP_ID, 'oauth_microsoft_client_id', $msId);
		$googleSecret = (string)($data['googleClientSecret'] ?? '');
		if (trim($googleSecret) !== '') {
			$this->storeClientSecret('oauth_google_client_secret', trim($googleSecret));
		}
		$msSecret = (string)($data['microsoftClientSecret'] ?? '');
		if (trim($msSecret) !== '') {
			$this->storeClientSecret('oauth_microsoft_client_secret', trim($msSecret));
		}
		if (array_key_exists('clearGoogleSecret', $data) && !empty($data['clearGoogleSecret'])) {
			$this->storeClientSecret('oauth_google_client_secret', '');
		}
		if (array_key_exists('clearMicrosoftSecret', $data) && !empty($data['clearMicrosoftSecret'])) {
			$this->storeClientSecret('oauth_microsoft_client_secret', '');
		}

		return $this->adminConfigStatus();
	}

	private function callbackUrl(): string
	{
		return $this->url->linkToRouteAbsolute('customercheck.persona_api.personalMailOAuthCallback');
	}

	private function clientId(string $provider): string
	{
		$key = $provider === self::PROVIDER_GOOGLE ? 'oauth_google_client_id' : 'oauth_microsoft_client_id';

		return trim((string)$this->appConfig->getValueString(Application::APP_ID, $key, ''));
	}

	private function clientSecret(string $provider): string
	{
		$key = $provider === self::PROVIDER_GOOGLE ? 'oauth_google_client_secret' : 'oauth_microsoft_client_secret';
		$raw = trim((string)$this->appConfig->getValueString(Application::APP_ID, $key, ''));
		if ($raw === '') {
			return '';
		}
		try {
			return trim($this->crypto->decrypt($raw));
		} catch (\Throwable) {
			// Legacy plaintext appconfig values until next admin save.
			return $raw;
		}
	}

	private function storeClientSecret(string $key, string $plain): void
	{
		$plain = trim($plain);
		if ($plain === '') {
			$this->appConfig->setValueString(Application::APP_ID, $key, '');

			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, $key, $this->crypto->encrypt($plain));
	}

	/**
	 * @return array{access_token: string, refresh_token?: string, expires_in?: int, email?: string}
	 */
	private function exchangeCode(string $provider, string $code): array
	{
		$clientId = $this->clientId($provider);
		$clientSecret = $this->clientSecret($provider);
		if ($clientId === '' || $clientSecret === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'provider',
				$this->l10n->t('OAuth is not configured on this server yet.'),
			);
		}
		$tokenUrl = $provider === self::PROVIDER_GOOGLE
			? 'https://oauth2.googleapis.com/token'
			: 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
		$body = http_build_query([
			'code' => $code,
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'redirect_uri' => $this->callbackUrl(),
			'grant_type' => 'authorization_code',
		]);
		$ctx = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
				'content' => $body,
				'timeout' => 20,
				'ignore_errors' => true,
			],
		]);
		$raw = @file_get_contents($tokenUrl, false, $ctx);
		if ($raw === false) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'oauth', $this->l10n->t('Could not reach the mail provider. Try again.'));
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['access_token'])) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'oauth', $this->l10n->t('Mail provider rejected the sign-in. Try again or use IMAP.'));
		}
		$email = $this->fetchEmail($provider, (string)$data['access_token']);
		$data['email'] = $email;

		return $data;
	}

	private function fetchEmail(string $provider, string $accessToken): string
	{
		$url = $provider === self::PROVIDER_GOOGLE
			? 'https://www.googleapis.com/oauth2/v2/userinfo'
			: 'https://graph.microsoft.com/v1.0/me';
		$ctx = stream_context_create([
			'http' => [
				'method' => 'GET',
				'header' => "Authorization: Bearer {$accessToken}\r\n",
				'timeout' => 15,
				'ignore_errors' => true,
			],
		]);
		$raw = @file_get_contents($url, false, $ctx);
		if ($raw === false) {
			return '';
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return '';
		}
		if ($provider === self::PROVIDER_GOOGLE) {
			return strtolower(trim((string)($data['email'] ?? '')));
		}

		return strtolower(trim((string)($data['mail'] ?? $data['userPrincipalName'] ?? '')));
	}
}
