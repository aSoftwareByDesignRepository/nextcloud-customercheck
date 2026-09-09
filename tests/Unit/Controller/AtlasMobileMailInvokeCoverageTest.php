<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Controller\MobileMailController;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 invoke coverage — MobileMailController (rememberLast=false on assertCapability).
 */
final class AtlasMobileMailInvokeCoverageTest extends TestCase
{
	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function wsCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);
		$ws->setSlug('book');
		$ws->setName('Book');

		return [
			'workspace' => $ws,
			'role' => 'manager',
			'isAppAdmin' => true,
			'member' => null,
			'needsPicker' => false,
		];
	}

	private function accessOk(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->willReturn(true);

		return $access;
	}

	private function workspacesOk(): WorkspaceAccessService
	{
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		// Mobile paths pass rememberLast=false — accept any arity.
		$workspaces->method('assertCapability');
		$workspaces->method('resolveForUser')->willReturn($this->wsCtx());

		return $workspaces;
	}

	/** @param array<string, mixed> $params */
	private function requestOk(array $params = []): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => array_key_exists($k, $params) ? $params[$k] : $d,
		);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, 'Authorization') === 0) {
					return 'Basic ' . base64_encode('alice:secret');
				}

				return '';
			},
		);
		$request->method('passesCSRFCheck')->willReturn(true);
		$request->method('getUploadedFile')->willReturn(null);

		return $request;
	}

	private function userSessionOk(): IUserSession
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	/**
	 * @return array{0: MobileMailController}
	 */
	private function buildController(
		?IUserSession $session = null,
		?AccessControlService $access = null,
	): array {
		$gate = $this->createMock(MobileGateService::class);
		$gate->method('assertGatePassed');

		$personalMail = $this->createMock(PersonalMailService::class);
		$personalMail->method('listInbox')->willReturn(['items' => []]);
		$personalMail->method('assertCanReadMessage');
		$personalMail->method('listMine')->willReturn([['id' => 5, 'label' => 'me']]);
		$personalMail->method('connectImap')->willReturn(['id' => 5]);
		$personalMail->method('saveFolders')->willReturn(['id' => 5]);
		$personalMail->method('assertPersonalMailEnabled');
		$personalMail->method('refreshFolders')->willReturn(['folders' => []]);
		$personalMail->method('disconnect');
		$personalMail->method('saveSignature')->willReturn(['id' => 5]);

		$personalMailOAuth = $this->createMock(PersonalMailOAuthService::class);
		$personalMailOAuth->method('beginAuthorize')->willReturn(['url' => 'https://oauth.example']);
		$personalMailOAuth->method('completeAuthorize')->willReturn(['id' => 5]);

		$mailSend = $this->createMock(MailSendService::class);
		$mailSend->method('listSendAccounts')->willReturn([]);
		$mailSend->method('send')->willReturn(['sent' => true]);

		$mailboxSync = $this->createMock(MailboxSyncService::class);
		$mailboxSync->method('previewIndexedMessage')->willReturn(['body' => 'hi']);
		$mailboxSync->method('syncOne')->willReturn(['status' => 'ok', 'ingested' => 0]);

		$workspaceService = $this->createMock(WorkspaceService::class);
		$workspaceService->method('getSettings')->willReturn(['personalMailEnabled' => true]);

		$filesAccess = $this->createMock(FilesAccessService::class);
		$filesAccess->method('stageOutboundMailAttachment')->willReturn(['token' => 't']);
		$filesAccess->method('listAttachableMailFiles')->willReturn(['items' => []]);

		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->method('allow')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$userManager = $this->createMock(IUserManager::class);
		$authed = $this->createMock(IUser::class);
		$authed->method('getUID')->willReturn('alice');
		$userManager->method('checkPassword')->willReturn($authed);

		$ctrl = new MobileMailController(
			Application::APP_ID,
			$this->requestOk(),
			$session ?? $this->userSessionOk(),
			$access ?? $this->accessOk(),
			$this->workspacesOk(),
			$gate,
			$personalMail,
			$personalMailOAuth,
			$mailSend,
			$mailboxSync,
			$workspaceService,
			$filesAccess,
			$rateLimit,
			$l10n,
			$userManager,
		);

		return [$ctrl];
	}

	public function testMobileMailAllActionsAndAuthzNegative(): void
	{
		[$ctrl] = $this->buildController();

		self::assertSame(200, $ctrl->inbox(7)->getStatus());
		self::assertSame(200, $ctrl->message(7, 9)->getStatus());
		self::assertSame(200, $ctrl->sendAccounts(7)->getStatus());
		self::assertSame(200, $ctrl->send(7)->getStatus());
		// No uploaded file → validation; still invoked.
		$staged = $ctrl->stageAttachment(7);
		self::assertInstanceOf(JSONResponse::class, $staged);
		self::assertGreaterThanOrEqual(400, $staged->getStatus());
		self::assertSame(200, $ctrl->personalMailboxes(7)->getStatus());
		self::assertSame(200, $ctrl->connectPersonalMailbox(7)->getStatus());
		self::assertSame(200, $ctrl->savePersonalMailboxFolders(7, 5)->getStatus());
		self::assertSame(200, $ctrl->refreshPersonalMailboxFolders(7, 5)->getStatus());
		self::assertSame(200, $ctrl->disconnectPersonalMailbox(7, 5)->getStatus());
		self::assertSame(200, $ctrl->syncPersonalMailbox(7, 5)->getStatus());
		self::assertSame(200, $ctrl->savePersonalMailboxSignature(7, 5)->getStatus());
		self::assertSame(200, $ctrl->beginPersonalMailOAuth(7, 'google')->getStatus());
		self::assertSame(200, $ctrl->completePersonalMailOAuth(7)->getStatus());
		self::assertSame(200, $ctrl->listMailAttachFiles(7)->getStatus());

		$denySession = $this->createMock(IUserSession::class);
		$denySession->method('getUser')->willReturn(null);
		[$deny] = $this->buildController($denySession);
		foreach ([
			static fn () => $deny->inbox(7),
			static fn () => $deny->message(7, 9),
			static fn () => $deny->sendAccounts(7),
			static fn () => $deny->send(7),
			static fn () => $deny->stageAttachment(7),
			static fn () => $deny->personalMailboxes(7),
			static fn () => $deny->connectPersonalMailbox(7),
			static fn () => $deny->savePersonalMailboxFolders(7, 5),
			static fn () => $deny->refreshPersonalMailboxFolders(7, 5),
			static fn () => $deny->disconnectPersonalMailbox(7, 5),
			static fn () => $deny->syncPersonalMailbox(7, 5),
			static fn () => $deny->savePersonalMailboxSignature(7, 5),
			static fn () => $deny->beginPersonalMailOAuth(7, 'google'),
			static fn () => $deny->completePersonalMailOAuth(7),
			static fn () => $deny->listMailAttachFiles(7),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'mobile mail authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'mobile mail authz idx ' . $i);
		}
	}
}
