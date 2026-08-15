<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\ImapConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;

/**
 * Resolve IMAP/SMTP credentials for a mailbox row — password or OAuth bearer (XOAUTH2).
 */
class MailboxAuthService
{
	public const MODE_PASSWORD = 'password';
	public const MODE_XOAUTH2 = 'xoauth2';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ICrypto $crypto,
		private readonly ITimeFactory $timeFactory,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly MailboxService $mailboxes,
	) {
	}

	/**
	 * @param array<string, mixed> $row crm_mailbox row
	 * @return array{mode: string, user: string, secret: string, email: string}
	 */
	public function resolve(array $row): array
	{
		$user = ImapConnection::normalizeUsername((string)($row['username'] ?? ''));
		$email = strtolower(trim((string)($row['email_address'] ?? $user)));
		$authKind = strtolower(trim((string)($row['auth_kind'] ?? 'imap')));
		if ($authKind === 'google' || $authKind === 'microsoft') {
			$token = $this->accessTokenForRow($row);
			if ($token === '') {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'oauth',
					$this->l10n->t('Mail provider sign-in expired. Reconnect your mailbox under My email.'),
				);
			}

			return [
				'mode' => self::MODE_XOAUTH2,
				'user' => $email !== '' ? $email : $user,
				'secret' => $token,
				'email' => $email !== '' ? $email : $user,
			];
		}

		$secret = '';
		$enc = trim((string)($row['secret_enc'] ?? ''));
		if ($enc !== '') {
			try {
				$secret = $this->crypto->decrypt($enc);
			} catch (\Throwable) {
				$secret = '';
			}
		}
		if ($secret === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'password',
				$this->l10n->t('Mailbox password could not be unlocked. Re-save credentials under Team & mailbox or My email.'),
			);
		}

		return [
			'mode' => self::MODE_PASSWORD,
			'user' => $user !== '' ? $user : $email,
			'secret' => $secret,
			'email' => $email,
		];
	}

	/**
	 * SASL initial response for AUTHENTICATE XOAUTH2 (IMAP/SMTP).
	 */
	public static function xoauth2Sasl(string $userEmail, string $accessToken): string
	{
		$raw = 'user=' . $userEmail . "\x01auth=Bearer " . $accessToken . "\x01\x01";

		return base64_encode($raw);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function accessTokenForRow(array $row): string
	{
		$id = (int)($row['id'] ?? 0);
		$expiresAt = trim((string)($row['token_expires_at'] ?? ''));
		$access = $this->decryptField((string)($row['token_enc'] ?? ''));
		$skew = $this->timeFactory->getTime() + 90;
		$expired = false;
		if ($expiresAt !== '') {
			$ts = strtotime($expiresAt);
			$expired = $ts !== false && $ts <= $skew;
		}
		if ($access !== '' && !$expired) {
			return $access;
		}
		$refresh = $this->decryptField((string)($row['refresh_enc'] ?? ''));
		if ($refresh === '') {
			if ($expired || $access === '') {
				$this->bumpOAuthAuthFailure($id, (int)($row['auth_fail_count'] ?? 0));
			}

			return '';
		}
		$provider = strtolower(trim((string)($row['auth_kind'] ?? '')));
		$fresh = $this->refreshAccessToken($provider, $refresh);
		if ($fresh['status'] === 'ok') {
			$data = $fresh['data'];
			$this->persistTokens($id, (string)$data['access_token'], (string)($data['refresh_token'] ?? $refresh), (int)($data['expires_in'] ?? 3600));
			$this->clearOAuthAuthFailure($id);

			return (string)$data['access_token'];
		}
		// Network / missing app config: do not quarantine — dial cooldown handles retries.
		if ($fresh['status'] === 'auth') {
			$this->bumpOAuthAuthFailure($id, (int)($row['auth_fail_count'] ?? 0));
		}

		return '';
	}

	private function bumpOAuthAuthFailure(int $mailboxId, int $currentFails): void
	{
		if ($mailboxId <= 0) {
			return;
		}
		$this->mailboxes->recordAuthFailure($mailboxId, $currentFails);
	}

	private function clearOAuthAuthFailure(int $mailboxId): void
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('auth_fail_count', $qb->createNamedParameter(0))
			->set('status', $qb->createNamedParameter('ok'))
			->set('last_error', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function decryptField(string $enc): string
	{
		$enc = trim($enc);
		if ($enc === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($enc);
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * @return array{status: 'ok', data: array{access_token: string, refresh_token?: string, expires_in?: int}}|array{status: 'auth'|'network'|'config'}
	 */
	private function refreshAccessToken(string $provider, string $refreshToken): array
	{
		$clientId = $this->clientId($provider);
		$clientSecret = $this->clientSecret($provider);
		if ($clientId === '' || $clientSecret === '') {
			return ['status' => 'config'];
		}
		$tokenUrl = $provider === 'google'
			? 'https://oauth2.googleapis.com/token'
			: 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
		$body = http_build_query([
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'refresh_token' => $refreshToken,
			'grant_type' => 'refresh_token',
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
			return ['status' => 'network'];
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['access_token'])) {
			return ['status' => 'auth'];
		}

		return ['status' => 'ok', 'data' => $data];
	}

	private function persistTokens(int $mailboxId, string $access, string $refresh, int $expiresIn): void
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return;
		}
		$now = $this->timeFactory->getDateTime();
		$expiresAt = (clone $now)->modify('+' . max(60, $expiresIn) . ' seconds')->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('token_enc', $qb->createNamedParameter($this->crypto->encrypt($access)))
			->set('refresh_enc', $qb->createNamedParameter($this->crypto->encrypt($refresh)))
			->set('token_expires_at', $qb->createNamedParameter($expiresAt))
			->set('updated_at', $qb->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function clientId(string $provider): string
	{
		$key = $provider === 'google' ? 'oauth_google_client_id' : 'oauth_microsoft_client_id';

		return trim((string)$this->appConfig->getValueString(Application::APP_ID, $key, ''));
	}

	private function clientSecret(string $provider): string
	{
		$key = $provider === 'google' ? 'oauth_google_client_secret' : 'oauth_microsoft_client_secret';

		return trim((string)$this->appConfig->getValueString(Application::APP_ID, $key, ''));
	}
}
