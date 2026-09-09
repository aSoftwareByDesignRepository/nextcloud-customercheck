<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\ActivityApiController;
use OCA\CustomerCheck\Controller\DealApiController;
use OCA\CustomerCheck\Controller\EmailApiController;
use OCA\CustomerCheck\Controller\FeedController;
use OCA\CustomerCheck\Controller\HoursApiController;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\EmailTouch;
use OCA\CustomerCheck\Db\HoursEntry;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\MailRelatedService;
use OCA\CustomerCheck\Service\MailTemplateService;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 invoke coverage — Deal / Activity / Email / Hours / Feed controllers.
 */
final class AtlasCrudControllersInvokeCoverageTest extends TestCase
{
	/** @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool} */
	private function wsCtx(int $id = 7): array
	{
		$ws = new Workspace();
		$ws->setId($id);

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

		return $c;
	}

	private function accessOk(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireCanUseApp')->willReturn('alice');
		$access->method('currentUserId')->willReturn('alice');

		return $access;
	}

	private function workspacesOk(): WorkspaceAccessService
	{
		$workspaces = $this->createMock(WorkspaceAccessService::class);
		$workspaces->method('resolveForUser')->willReturn($this->wsCtx());
		$workspaces->method('assertCapability');
		$workspaces->method('assertCompanyInWorkspace');

		return $workspaces;
	}

	private function companiesOk(): CompanyService
	{
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($this->company());

		return $companies;
	}

	private function dealEntity(int $id = 9, int $companyId = 3): Deal
	{
		$d = new Deal();
		$d->setId($id);
		$d->setCompanyId($companyId);
		$d->setTitle('T');
		$d->setStage('lead');

		return $d;
	}

	public function testDealApiAllActionsAndAuthzNegative(): void
	{
		$deal = $this->dealEntity();
		$deals = $this->createMock(DealService::class);
		$deals->method('search')->willReturn([$deal]);
		$deals->method('get')->willReturn($deal);
		$deals->method('create')->willReturn($deal);
		$deals->method('update')->willReturn($deal);
		$deals->method('claim')->willReturn($deal);
		$deals->method('changeStage')->willReturn(['deal' => $deal, 'sequencesFired' => []]);
		$deals->method('toArray')->willReturn(['id' => 9, 'title' => 'T']);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(0);
		$request->method('getHeader')->willReturn('');

		$ctrl = new DealApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$this->workspacesOk(),
			$this->companiesOk(),
			$deals,
		);

		self::assertSame(200, $ctrl->index()->getStatus());
		self::assertSame(201, $ctrl->create()->getStatus());
		self::assertSame(200, $ctrl->update(9)->getStatus());
		self::assertSame(200, $ctrl->claim(9)->getStatus());
		self::assertSame(200, $ctrl->stage(9)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new DealApiController(
			'customercheck',
			$request,
			$denyAccess,
			$this->workspacesOk(),
			$this->companiesOk(),
			$deals,
		);
				foreach ([
			static fn () => $deny->index(),
			static fn () => $deny->create(),
			static fn () => $deny->update(9),
			static fn () => $deny->claim(9),
			static fn () => $deny->stage(9),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'authz idx ' . $i);
		}
	}

	public function testActivityApiAllActionsAndAuthzNegative(): void
	{
		$activity = new Activity();
		$activity->setId(5);
		$activity->setCompanyId(3);
		$activity->setType('todo');
		$activity->setTitle('Do');
		$activity->setOrigin('manual');

		$activities = $this->createMock(ActivityService::class);
		$activities->method('search')->willReturn([$activity]);
		$activities->method('get')->willReturn($activity);
		$activities->method('create')->willReturn($activity);
		$activities->method('update')->willReturn($activity);
		$activities->method('setDone')->willReturn($activity);
		$activities->method('pushToTasks')->willReturn(['pushed' => true]);
		$activities->method('dismissRemainingFollowUps')->willReturn(['dismissed' => 0]);

		$deal = $this->dealEntity();
		$deals = $this->createMock(DealService::class);
		$deals->method('get')->willReturn($deal);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => $d ?? 0);
		$request->method('getHeader')->willReturn('');

		$ctrl = new ActivityApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$this->workspacesOk(),
			$this->companiesOk(),
			$deals,
			$activities,
		);

		self::assertSame(200, $ctrl->index()->getStatus());
		self::assertSame(201, $ctrl->create()->getStatus());
		self::assertSame(200, $ctrl->update(5)->getStatus());
		self::assertSame(200, $ctrl->done(5)->getStatus());
		self::assertSame(200, $ctrl->pushTodo(5)->getStatus());
		self::assertSame(200, $ctrl->dismissFollowUps(9)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new ActivityApiController(
			'customercheck',
			$request,
			$denyAccess,
			$this->workspacesOk(),
			$this->companiesOk(),
			$deals,
			$activities,
		);
		foreach ([
			static fn () => $deny->create(),
			static fn () => $deny->update(5),
			static fn () => $deny->done(5),
			static fn () => $deny->pushTodo(5),
			static fn () => $deny->dismissFollowUps(9),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'activity authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'activity authz idx ' . $i);
		}
	}

	public function testEmailApiAllActionsAndAuthzNegative(): void
	{
		$touch = new EmailTouch();
		$touch->setId(1);
		$touch->setDirection('out');
		$touch->setFromAddr('a@b.c');
		$touch->setToAddr('d@e.f');
		$touch->setSubject('Hi');
		$touch->setSource('manual');

		$emails = $this->createMock(EmailTouchService::class);
		$emails->method('createManual')->willReturn($touch);
		$related = $this->createMock(MailRelatedService::class);
		$related->method('forCompany')->willReturn(['items' => []]);
		$templates = $this->createMock(MailTemplateService::class);
		$templates->method('build')->willReturn(['subject' => 'S', 'body' => 'B']);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$ctrl = new EmailApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$this->workspacesOk(),
			$this->companiesOk(),
			$emails,
			$related,
			$templates,
		);

		self::assertSame(200, $ctrl->index(3)->getStatus());
		self::assertSame(201, $ctrl->create(3)->getStatus());
		self::assertSame(200, $ctrl->composeTemplate(3)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new EmailApiController(
			'customercheck',
			$request,
			$denyAccess,
			$this->workspacesOk(),
			$this->companiesOk(),
			$emails,
			$related,
			$templates,
		);
		foreach ([
			static fn () => $deny->index(3),
			static fn () => $deny->create(3),
			static fn () => $deny->composeTemplate(3),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'email authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'email authz idx ' . $i);
		}
	}

	public function testHoursApiAllActionsAndAuthzNegative(): void
	{
		$agreement = new Agreement();
		$agreement->setId(4);
		$agreement->setCompanyId(3);

		$agreements = $this->createMock(AgreementService::class);
		$agreements->method('get')->willReturn($agreement);

		$entry = new HoursEntry();
		$entry->setId(8);
		$entry->setAgreementId(4);
		$entry->setMinutes(30);
		$entry->setTopic('work');

		$quota = $this->createMock(QuotaService::class);
		$quota->method('listEntries')->willReturn([$entry]);
		$quota->method('snapshot')->willReturn(['used' => 30]);
		$quota->method('logHours')->willReturn($entry);
		$quota->method('getEntry')->willReturn($entry);
		$quota->method('updateEntry')->willReturn($entry);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$ctrl = new HoursApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$this->workspacesOk(),
			$this->companiesOk(),
			$agreements,
			$quota,
		);

		self::assertSame(200, $ctrl->index(4)->getStatus());
		// create needs valid entryDate — empty body → validation error still invokes method
		$created = $ctrl->create(4);
		self::assertInstanceOf(JSONResponse::class, $created);
		self::assertSame(200, $ctrl->update(8)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new HoursApiController(
			'customercheck',
			$request,
			$denyAccess,
			$this->workspacesOk(),
			$this->companiesOk(),
			$agreements,
			$quota,
		);
		foreach ([
			static fn () => $deny->index(4),
			static fn () => $deny->create(4),
			static fn () => $deny->update(8),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'hours authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'hours authz idx ' . $i);
		}
	}

	public function testFeedRenewalsHappyAndAuthzNegative(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('tokenMatches')->willReturnOnConsecutiveCalls(true, false);
		$settings->method('workspaceIcsTokenMatches')->willReturnOnConsecutiveCalls(true, false);

		$ws = new Workspace();
		$ws->setId(7);
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('findDefault')->willReturn($ws);
		$workspaces->method('findById')->willReturn($ws);

		$agreements = $this->createMock(AgreementService::class);
		$agreements->method('search')->willReturn([]);
		$companies = $this->createMock(CompanyService::class);
		$activities = $this->createMock(ActivityService::class);
		$activities->method('search')->willReturn([]);

		$ctrl = new FeedController(
			'customercheck',
			$this->createMock(IRequest::class),
			$settings,
			$agreements,
			$companies,
			$activities,
			$workspaces,
		);

		$ok = $ctrl->renewals('good');
		self::assertInstanceOf(DataDisplayResponse::class, $ok);
		$deny = $ctrl->renewals('bad');
		self::assertInstanceOf(JSONResponse::class, $deny);
		self::assertSame(410, $deny->getStatus());

		$okWs = $ctrl->workspaceRenewals(7, 'good');
		self::assertInstanceOf(DataDisplayResponse::class, $okWs);
		$denyWs = $ctrl->workspaceRenewals(7, 'bad');
		self::assertInstanceOf(JSONResponse::class, $denyWs);
		self::assertSame(410, $denyWs->getStatus());
	}
}
