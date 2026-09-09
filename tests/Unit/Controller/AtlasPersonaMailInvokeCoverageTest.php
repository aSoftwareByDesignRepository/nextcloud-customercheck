<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\PersonaApiController;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\BriefService;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\CoachingService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailSendService;
use OCA\CustomerCheck\Service\OneOnOneService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\PromisesRollCallService;
use OCA\CustomerCheck\Service\PulseService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 invoke coverage — PersonaApiController mail / mailbox / OAuth surface.
 */
final class AtlasPersonaMailInvokeCoverageTest extends TestCase
{
	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function wsCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);
		$ws->setSlug('book');
		$ws->setName('Book');
		$ws->setIsDefault(1);

		return [
			'workspace' => $ws,
			'role' => 'manager',
			'isAppAdmin' => true,
			'member' => null,
			'needsPicker' => false,
		];
	}

	private function company(int $id = 3, int $ws = 7): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setWorkspaceId($ws);
		$c->setSlug('acme');
		$c->setName('Acme');

		return $c;
	}

	private function accessOk(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$access->method('isAppAdmin')->willReturn(true);

		return $access;
	}

	private function workspacesOk(): WorkspaceAccessService
	{
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->wsCtx());
		$workspaces->method('assertCapability');
		$workspaces->method('assertUserCanAccessCompany');

		return $workspaces;
	}

	private function requestOk(array $params = []): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => array_key_exists($k, $params) ? $params[$k] : $d,
		);
		$request->method('getHeader')->willReturn('');

		return $request;
	}

	private function l10nOk(): IL10N
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => $s);
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $c) => $c === 1 ? $s : $p);
		$l10n->method('getLanguageCode')->willReturn('en');

		return $l10n;
	}

	/**
	 * @return array{0: PersonaApiController, personalMailOAuth: PersonalMailOAuthService}
	 */
	private function buildController(
		?AccessControlService $access = null,
		?IRequest $request = null,
		?PersonalMailOAuthService $oauth = null,
	): array {
		$mailbox = $this->createMock(MailboxService::class);
		$mailbox->method('listMailboxes')->willReturn([['id' => 1, 'label' => 'team@example.com']]);
		$mailbox->method('disclosure')->willReturn('disclosure');
		$mailbox->method('save')->willReturn(['id' => 1]);
		$mailbox->method('saveTeamSignature')->willReturn(['id' => 1]);
		$mailbox->method('resolveMailboxKind')->willReturn(MailAclLogic::KIND_TEAM);
		$mailbox->method('unmatched')->willReturn([]);
		$mailbox->method('triageAssign');

		$mailboxSync = $this->createMock(MailboxSyncService::class);
		$mailboxSync->method('deleteMailbox');
		$mailboxSync->method('testConnection')->willReturn(['ok' => true]);
		$mailboxSync->method('syncOne')->willReturn(['status' => 'ok', 'ingested' => 1]);
		$mailboxSync->method('syncWorkspace')->willReturn(['ingested' => 0, 'message' => '']);
		$mailboxSync->method('previewIndexedMessage')->willReturn(['body' => 'hi']);
		$mailboxSync->method('replyComposeIndexedMessage')->willReturn(['to' => 'a@b.c']);
		$mailboxSync->method('deleteIndexedMessageFromInbox')->willReturn(['deleted' => true]);
		$mailboxSync->method('markIndexedMessageSpamFromInbox')->willReturn(['spam' => true]);

		$mailSend = $this->createMock(MailSendService::class);
		$mailSend->method('listSendAccounts')->willReturn([]);
		$mailSend->method('send')->willReturn(['sent' => true]);

		$mailDrafts = $this->createMock(MailDraftService::class);
		$mailDrafts->method('listFailed')->willReturn(['items' => []]);
		$mailDrafts->method('getFailed')->willReturn(['id' => 2]);
		$mailDrafts->method('resendFailed')->willReturn(['sent' => true]);
		$mailDrafts->method('deleteFailed');

		$personalMail = $this->createMock(PersonalMailService::class);
		$personalMail->method('listMine')->willReturn([['id' => 5, 'label' => 'me']]);
		$personalMail->method('connectImap')->willReturn(['id' => 5]);
		$personalMail->method('saveFolders')->willReturn(['id' => 5]);
		$personalMail->method('refreshFolders')->willReturn(['folders' => []]);
		$personalMail->method('disconnect');
		$personalMail->method('assertPersonalMailEnabled');
		$personalMail->method('saveSignature')->willReturn(['id' => 5]);
		$personalMail->method('listInbox')->willReturn(['items' => []]);

		$personalMailOAuth = $oauth ?? $this->createMock(PersonalMailOAuthService::class);
		$personalMailOAuth->method('providerStatus')->willReturn([]);
		$personalMailOAuth->method('beginAuthorize')->willReturn(['url' => 'https://oauth.example']);
		$personalMailOAuth->method('isNativeOAuthState')->willReturn(true);
		$personalMailOAuth->method('completeAuthorize')->willReturn(['id' => 5]);

		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->method('allow')->willReturn(true);

		$workspaceService = $this->createMock(WorkspaceService::class);
		$workspaceService->method('getSettings')->willReturn(['personalMailEnabled' => true]);
		$workspaceService->method('getReviewSources')->willReturn(['shared_mail' => ['enabled' => false]]);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($this->company());

		$ctrl = new PersonaApiController(
			'customercheck',
			$request ?? $this->requestOk(['state' => 'native-state', 'code' => 'x']),
			$access ?? $this->accessOk(),
			$this->workspacesOk(),
			$workspaceService,
			$this->createMock(PulseService::class),
			$this->createMock(BriefService::class),
			$this->createMock(ChaseService::class),
			$this->createMock(CoverageService::class),
			$this->createMock(CoachingService::class),
			$this->createMock(PromiseService::class),
			$mailbox,
			$mailboxSync,
			$mailSend,
			$mailDrafts,
			$this->createMock(AccountPlanService::class),
			$this->createMock(EscalationService::class),
			$this->createMock(PromisesRollCallService::class),
			$companies,
			$this->createMock(OneOnOneService::class),
			$this->createMock(DirectoryOptionsService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ReviewService::class),
			$personalMail,
			$personalMailOAuth,
			$rateLimit,
			$this->l10nOk(),
		);

		return [$ctrl, 'personalMailOAuth' => $personalMailOAuth];
	}

	public function testPersonaMailSurfaceAndAuthzNegative(): void
	{
		[$ctrl] = $this->buildController();

		self::assertSame(200, $ctrl->mailboxes(7)->getStatus());
		self::assertSame(200, $ctrl->personalMailboxes(7)->getStatus());
		self::assertSame(200, $ctrl->connectPersonalMailbox(7)->getStatus());
		self::assertSame(200, $ctrl->savePersonalMailboxFolders(7, 5)->getStatus());
		self::assertSame(200, $ctrl->refreshPersonalMailboxFolders(7, 5)->getStatus());
		self::assertSame(200, $ctrl->disconnectPersonalMailbox(7, 5)->getStatus());
		self::assertSame(200, $ctrl->syncPersonalMailbox(7, 5)->getStatus());
		self::assertSame(200, $ctrl->savePersonalMailboxSignature(7, 5)->getStatus());
		self::assertSame(200, $ctrl->saveTeamMailboxSignature(7, 1)->getStatus());
		self::assertSame(200, $ctrl->mailInbox(7)->getStatus());
		self::assertSame(200, $ctrl->mailSendAccounts(7)->getStatus());
		self::assertSame(200, $ctrl->beginPersonalMailOAuth(7, 'google')->getStatus());
		$oauthCb = $ctrl->personalMailOAuthCallback();
		self::assertInstanceOf(DataDisplayResponse::class, $oauthCb);
		self::assertSame(200, $ctrl->saveMailbox(7)->getStatus());
		self::assertSame(200, $ctrl->deleteMailbox(7, 1)->getStatus());
		self::assertSame(200, $ctrl->testMailbox(7)->getStatus());
		self::assertSame(200, $ctrl->syncMailbox(7, 1)->getStatus());
		self::assertSame(200, $ctrl->syncAllMailboxes(7)->getStatus());
		self::assertSame(200, $ctrl->sendMail(7)->getStatus());
		self::assertSame(200, $ctrl->listMailDrafts(7)->getStatus());
		self::assertSame(200, $ctrl->getMailDraft(7, 2)->getStatus());
		self::assertSame(200, $ctrl->sendMailDraft(7, 2)->getStatus());
		self::assertSame(200, $ctrl->deleteMailDraft(7, 2)->getStatus());
		self::assertSame(200, $ctrl->unmatchedMail(7)->getStatus());
		self::assertSame(200, $ctrl->previewMail(7, 9)->getStatus());
		self::assertSame(200, $ctrl->replyMail(7, 9)->getStatus());
		self::assertSame(200, $ctrl->triageMail(7, 9)->getStatus());
		self::assertSame(200, $ctrl->deleteMail(7, 9)->getStatus());
		self::assertSame(200, $ctrl->markSpamMail(7, 9)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('currentUserId')->willThrowException(new AccessDeniedException('no'));
		[$deny] = $this->buildController($denyAccess);
		foreach ([
			static fn () => $deny->mailboxes(7),
			static fn () => $deny->personalMailboxes(7),
			static fn () => $deny->connectPersonalMailbox(7),
			static fn () => $deny->savePersonalMailboxFolders(7, 5),
			static fn () => $deny->refreshPersonalMailboxFolders(7, 5),
			static fn () => $deny->disconnectPersonalMailbox(7, 5),
			static fn () => $deny->syncPersonalMailbox(7, 5),
			static fn () => $deny->savePersonalMailboxSignature(7, 5),
			static fn () => $deny->saveTeamMailboxSignature(7, 1),
			static fn () => $deny->mailInbox(7),
			static fn () => $deny->mailSendAccounts(7),
			static fn () => $deny->beginPersonalMailOAuth(7, 'google'),
			static fn () => $deny->saveMailbox(7),
			static fn () => $deny->deleteMailbox(7, 1),
			static fn () => $deny->testMailbox(7),
			static fn () => $deny->syncMailbox(7, 1),
			static fn () => $deny->syncAllMailboxes(7),
			static fn () => $deny->sendMail(7),
			static fn () => $deny->listMailDrafts(7),
			static fn () => $deny->getMailDraft(7, 2),
			static fn () => $deny->sendMailDraft(7, 2),
			static fn () => $deny->deleteMailDraft(7, 2),
			static fn () => $deny->unmatchedMail(7),
			static fn () => $deny->previewMail(7, 9),
			static fn () => $deny->replyMail(7, 9),
			static fn () => $deny->triageMail(7, 9),
			static fn () => $deny->deleteMail(7, 9),
			static fn () => $deny->markSpamMail(7, 9),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'authz idx ' . $i);
		}

		$oauthDeny = $deny->personalMailOAuthCallback();
		self::assertInstanceOf(\OCP\AppFramework\Http\Response::class, $oauthDeny);
		self::assertGreaterThanOrEqual(300, $oauthDeny->getStatus());
	}
}
