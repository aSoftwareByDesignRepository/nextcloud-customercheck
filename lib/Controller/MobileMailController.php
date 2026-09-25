<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Exception\MobileGateException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Constants;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Companion personal/team inbox APIs — CUC-PERSONAL-MAIL-1 M3 / MH-13.
 * Same ACL as web; cookie-only mutations rejected via assertSafeMutationChannel.
 */
class MobileMailController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaceAccess,
		private readonly MobileGateService $gate,
		private readonly PersonalMailService $personalMail,
		private readonly PersonalMailOAuthService $personalMailOAuth,
		private readonly MailSendService $mailSend,
		private readonly MailboxSyncService $mailboxSync,
		private readonly WorkspaceService $workspaceService,
		private readonly FilesAccessService $filesAccess,
		private readonly RateLimitService $rateLimit,
		private readonly \OCP\IL10N $l10n,
		private readonly IUserManager $userManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inbox(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'mail.read', false);
			$tab = (string)$this->request->getParam('tab', 'mine');
			$limit = (int)$this->request->getParam('limit', 50);
			$mailboxId = (int)$this->request->getParam('mailboxId', 0);
			$canTriage = false;
			try {
				$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);
				$canTriage = true;
			} catch (\Throwable) {
				$canTriage = false;
			}

			return $this->ok($this->personalMail->listInbox($workspaceId, $uid, $limit, $tab, $mailboxId, $canTriage));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function message(int $workspaceId, int $id): JSONResponse
	{
		try {
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'mail.read', false);
			$canReadCompany = false;
			try {
				$this->workspaceAccess->assertCapability($uid, $workspaceId, 'read', false);
				$canReadCompany = true;
			} catch (\Throwable) {
				$canReadCompany = false;
			}
			$canTriage = false;
			try {
				$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);
				$canTriage = true;
			} catch (\Throwable) {
				$canTriage = false;
			}
			$this->personalMail->assertCanReadMessage($workspaceId, $id, $uid, $canReadCompany, $canTriage);
			$preview = $this->mailboxSync->previewIndexedMessage(
				$workspaceId,
				$id,
				$uid,
				$canReadCompany,
				$canTriage,
			);

			return $this->ok($preview);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sendAccounts(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'mail.read', false);
			$ctx = $this->workspaceAccess->resolveForUser($uid, $workspaceId, false);
			$canSend = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'mail.send', false);
			if ($ctx['member'] !== null && method_exists($ctx['member'], 'canMailSend')) {
				$canSend = $canSend && ($ctx['isAppAdmin'] || $ctx['member']->canMailSend());
			}

			return $this->ok(['items' => $this->mailSend->listSendAccounts($workspaceId, $uid, $canSend)]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function send(int $workspaceId): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'mail.read', false);
			$ctx = $this->workspaceAccess->resolveForUser($uid, $workspaceId, false);
			$canSend = $ctx['isAppAdmin']
				|| WorkspaceCapability::allows($ctx['role'], 'mail.send', false);
			if ($ctx['member'] !== null && method_exists($ctx['member'], 'canMailSend')) {
				$canSend = $canSend && ($ctx['isAppAdmin'] || $ctx['member']->canMailSend());
			}
			$body = $this->jsonBody();

			return $this->ok($this->mailSend->send($workspaceId, $body, $uid, $canSend));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stageAttachment(int $workspaceId): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'mail.send', false);
			$uploaded = $this->request->getUploadedFile('file');
			if (!is_array($uploaded) || empty($uploaded['tmp_name']) || !is_uploaded_file((string)$uploaded['tmp_name'])) {
				throw new ValidationException(
					\OCA\CustomerCheck\Constants::ERR_ATTACHMENT_DENIED,
					'Pick a file to attach.',
				);
			}
			$binary = file_get_contents((string)$uploaded['tmp_name']);
			if ($binary === false) {
				throw new ValidationException(
					\OCA\CustomerCheck\Constants::ERR_ATTACHMENT_DENIED,
					'Could not read the uploaded file.',
				);
			}
			$name = (string)($uploaded['name'] ?? 'attachment.bin');
			$mime = (string)($uploaded['type'] ?? '');

			return $this->ok($this->filesAccess->stageOutboundMailAttachment($uid, $name, $mime, $binary));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function personalMailboxes(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'mail.read', false);
			$settings = $this->workspaceService->getSettings($workspaceId);

			return $this->ok([
				'personalMailEnabled' => !empty($settings['personalMailEnabled']),
				'items' => $this->personalMail->listMine($workspaceId, $uid),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function connectPersonalMailbox(int $workspaceId): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);

			return $this->ok($this->personalMail->connectImap($workspaceId, $uid, $this->jsonBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function savePersonalMailboxFolders(int $workspaceId, int $id): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);
			$body = $this->jsonBody();
			$folders = isset($body['folders']) && is_array($body['folders']) ? $body['folders'] : [];

			return $this->ok($this->personalMail->saveFolders($workspaceId, $id, $uid, $folders));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function refreshPersonalMailboxFolders(int $workspaceId, int $id): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);
			$this->personalMail->assertPersonalMailEnabled($workspaceId);
			if (!$this->rateLimit->allow(
				'pm_sync:' . $uid,
				Constants::PERSONAL_MAIL_SYNC_USER_LIMIT,
				Constants::PERSONAL_MAIL_SYNC_USER_WINDOW,
				true,
			)) {
				throw ValidationException::field(
					Constants::ERR_RATE_LIMITED,
					'rate',
					$this->l10n->t('Folder refresh rate limit reached. Try again later.'),
				);
			}

			return $this->ok($this->personalMail->refreshFolders($workspaceId, $id, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function disconnectPersonalMailbox(int $workspaceId, int $id): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);
			$this->personalMail->disconnect($workspaceId, $id, $uid);

			return $this->ok(['deleted' => true, 'id' => $id]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function syncPersonalMailbox(int $workspaceId, int $id): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);
			$this->personalMail->assertPersonalMailEnabled($workspaceId);
			if (!$this->rateLimit->allow(
				'pm_sync:' . $uid,
				Constants::PERSONAL_MAIL_SYNC_USER_LIMIT,
				Constants::PERSONAL_MAIL_SYNC_USER_WINDOW,
				true,
			)) {
				throw ValidationException::field(
					Constants::ERR_RATE_LIMITED,
					'rate',
					$this->l10n->t('Sync rate limit reached. Try again later.'),
				);
			}
			$mine = $this->personalMail->listMine($workspaceId, $uid);
			$owned = false;
			foreach ($mine as $box) {
				if ((int)$box['id'] === $id) {
					$owned = true;
					break;
				}
			}
			if (!$owned) {
				throw new NotFoundException('Personal mailbox not found');
			}
			$result = $this->mailboxSync->syncOne($id, $workspaceId);

			return $this->ok(is_array($result) ? $result : ['ok' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function savePersonalMailboxSignature(int $workspaceId, int $id): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);

			return $this->ok($this->personalMail->saveSignature($workspaceId, $id, $uid, $this->jsonBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function beginPersonalMailOAuth(int $workspaceId, string $provider): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);

			return $this->ok($this->personalMailOAuth->beginAuthorize($workspaceId, $uid, $provider, $this->jsonBody()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Companion OAuth finish — exchange code with Basic-auth actor (no browser NC session required).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function completePersonalMailOAuth(int $workspaceId): JSONResponse
	{
		try {
			$this->assertSafeMutationChannel();
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'write', false);
			$body = $this->jsonBody();
			$state = trim((string)($body['state'] ?? ''));
			$code = trim((string)($body['code'] ?? ''));
			$box = $this->personalMailOAuth->completeAuthorize($state, $code, $uid, $workspaceId);

			return $this->ok($box);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listMailAttachFiles(int $workspaceId): JSONResponse
	{
		try {
			$uid = $this->requireUser();
			$this->gate->assertGatePassed($uid);
			$this->workspaceAccess->assertCapability($uid, $workspaceId, 'mail.read', false);
			$path = (string)$this->request->getParam('path', '');
			$limit = (int)$this->request->getParam('limit', 80);

			return $this->ok($this->filesAccess->listAttachableMailFiles($uid, $path, $limit));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	private function requireUser(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new AccessDeniedException('AUTH_REQUIRED');
		}
		$uid = $user->getUID();
		if (!$this->accessControl->canUseApp($uid)) {
			throw new AppAccessDeniedException('ACCESS_DENIED');
		}

		return $uid;
	}

	/**
	 * Mirror MobileController: CSRF requesttoken OR Basic credentials that
	 * authenticate as the already-bound session user (companion app password).
	 * Junk `Authorization: Basic x` MUST NOT bypass CSRF.
	 *
	 * @throws AppAccessDeniedException
	 */
	private function assertSafeMutationChannel(): void
	{
		if ($this->request->passesCSRFCheck()) {
			return;
		}
		if ($this->authorizationBasicAuthenticatesCurrentUser()) {
			return;
		}

		throw new AppAccessDeniedException('CSRF or app password required');
	}

	/**
	 * True only when Authorization Basic credentials checkPassword as the
	 * current session UID (blocks presence-only Bearer/Basic CSRF escapes).
	 */
	private function authorizationBasicAuthenticatesCurrentUser(): bool
	{
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if (preg_match('/^Basic\s+(\S+)$/i', $auth, $m) !== 1) {
			return false;
		}
		$decoded = base64_decode($m[1], true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return false;
		}
		[$login, $secret] = explode(':', $decoded, 2);
		if ($login === '' || $secret === '') {
			return false;
		}
		$current = $this->userSession->getUser();
		if ($current === null) {
			return false;
		}
		$authed = $this->userManager->checkPassword($login, $secret);
		if ($authed === false) {
			return false;
		}

		return hash_equals($current->getUID(), $authed->getUID());
	}

	/** @return array<string, mixed> */
	private function jsonBody(): array
	{
		$raw = file_get_contents('php://input');
		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}
		$data = json_decode($raw, true);

		return is_array($data) ? $data : [];
	}

	/** @param array<string, mixed> $data */
	private function ok(array $data): JSONResponse
	{
		return new JSONResponse(['ok' => true, 'data' => $data], Http::STATUS_OK);
	}

	private function fromThrowable(\Throwable $e): JSONResponse
	{
		if ($e instanceof MobileGateException) {
			return new JSONResponse(['ok' => false, 'error' => $e->getErrorCode(), 'message' => $e->getMessage()], 402);
		}
		if ($e instanceof AccessDeniedException || $e instanceof AppAccessDeniedException) {
			$code = $e instanceof AppAccessDeniedException ? Http::STATUS_FORBIDDEN : Http::STATUS_UNAUTHORIZED;
			return new JSONResponse(['ok' => false, 'error' => 'ACCESS_DENIED', 'message' => $e->getMessage()], $code);
		}
		if ($e instanceof NotFoundException) {
			// Uniform-404: identical payload for missing and foreign ids —
			// never echo entity-specific messages (existence oracle).
			return new JSONResponse(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Not found.'], Http::STATUS_NOT_FOUND);
		}
		if ($e instanceof ValidationException) {
			if ($e->getErrorCode() === \OCA\CustomerCheck\Constants::ERR_NOT_FOUND) {
				return new JSONResponse(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Not found.'], Http::STATUS_NOT_FOUND);
			}
			$status = match ($e->getErrorCode()) {
				\OCA\CustomerCheck\Service\PersonalMailService::ERR_PERSONAL_MAIL_DISABLED => Http::STATUS_FORBIDDEN,
				\OCA\CustomerCheck\Service\PersonalMailService::ERR_MAIL_ACCOUNT_CONFLICT => Http::STATUS_CONFLICT,
				default => Http::STATUS_BAD_REQUEST,
			};

			return new JSONResponse([
				'ok' => false,
				'error' => $e->getErrorCode(),
				'message' => $e->getMessage(),
				'fields' => $e->getFields(),
			], $status);
		}

		return new JSONResponse(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => 'Unexpected error'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
