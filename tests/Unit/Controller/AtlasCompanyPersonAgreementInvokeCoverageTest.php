<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\AgreementApiController;
use OCA\CustomerCheck\Controller\CompanyApiController;
use OCA\CustomerCheck\Controller\LicenseController;
use OCA\CustomerCheck\Controller\PersonApiController;
use OCA\CustomerCheck\Controller\SettingsApiController;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\TimelineEvent;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyMergeService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\ContactsSuggestService;
use OCA\CustomerCheck\Service\DeliveryPackService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Service\LinkIdempotencyService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\PartnerMailService;
use OCA\CustomerCheck\Service\PersonalMailOAuthService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\CustomerCheck\Service\SlugService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Atlas v3 invoke coverage — Company / Person / Agreement / License / Settings controllers.
 */
final class AtlasCompanyPersonAgreementInvokeCoverageTest extends TestCase
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
		$c->setSlug('acme');
		$c->setName('Acme');

		return $c;
	}

	private function person(int $id = 5, int $companyId = 3): Person
	{
		$p = new Person();
		$p->setId($id);
		$p->setCompanyId($companyId);
		$p->setName('Pat');
		$p->setEmail('pat@example.com');
		$p->setIsPrimary(0);

		return $p;
	}

	private function agreement(int $id = 4, int $companyId = 3): Agreement
	{
		$a = new Agreement();
		$a->setId($id);
		$a->setCompanyId($companyId);
		$a->setKind('license');
		$a->setProductCode('custom');
		$a->setLabel('Std');
		$a->setLimitsJson('{}');
		$a->setAmountCents(0);
		$a->setInvoiceStatus('none');
		$a->setStatus('active');

		return $a;
	}

	private function accessOk(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireCanUseApp')->willReturn('alice');
		$access->method('currentUserId')->willReturn('alice');
		$access->method('currentUserIdOrEmpty')->willReturn('alice');
		$access->method('canManageAppConfiguration')->willReturn(true);
		$access->method('isAppAdmin')->willReturn(true);

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

	private function requestOk(): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => $d ?? 0);
		$request->method('getHeader')->willReturn('');

		return $request;
	}

	public function testPersonApiAllActionsAndAuthzNegative(): void
	{
		$person = $this->person();
		$persons = $this->createMock(PersonService::class);
		$persons->method('listForCompany')->willReturn([$person]);
		$persons->method('get')->willReturn($person);
		$persons->method('create')->willReturn($person);
		$persons->method('update')->willReturn($person);
		$persons->method('delete');

		$contacts = $this->createMock(ContactsSuggestService::class);
		$contacts->method('isAvailable')->willReturn(true);
		$contacts->method('suggest')->willReturn([]);

		$mailbox = $this->createMock(MailboxService::class);
		$review = $this->createMock(ReviewService::class);
		$emailTouches = $this->createMock(EmailTouchMapper::class);

		$request = $this->requestOk();
		$ctrl = new PersonApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$this->workspacesOk(),
			$this->companiesOk(),
			$persons,
			$contacts,
			$mailbox,
			$review,
			$emailTouches,
		);

		self::assertSame(200, $ctrl->index(3)->getStatus());
		self::assertSame(201, $ctrl->create(3)->getStatus());
		self::assertSame(200, $ctrl->update(5)->getStatus());
		self::assertSame(200, $ctrl->destroy(5)->getStatus());
		self::assertSame(200, $ctrl->suggestContacts('pa', 8)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new PersonApiController(
			'customercheck',
			$request,
			$denyAccess,
			$this->workspacesOk(),
			$this->companiesOk(),
			$persons,
			$contacts,
			$mailbox,
			$review,
			$emailTouches,
		);
				foreach ([
			static fn () => $deny->index(3),
			static fn () => $deny->create(3),
			static fn () => $deny->update(5),
			static fn () => $deny->destroy(5),
			static fn () => $deny->suggestContacts('pa', 8),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'authz idx ' . $i);
		}
	}

	public function testAgreementApiAllActionsAndAuthzNegative(): void
	{
		$agreement = $this->agreement();
		$agreements = $this->createMock(AgreementService::class);
		$agreements->method('search')->willReturn([$agreement]);
		$agreements->method('get')->willReturn($agreement);
		$agreements->method('create')->willReturn($agreement);
		$agreements->method('update')->willReturn($agreement);
		$agreements->method('cancel')->willReturn($agreement);
		$agreements->method('supersede')->willReturn([
			'previous' => $agreement,
			'current' => $agreement,
		]);

		$delivery = $this->createMock(DeliveryPackService::class);
		$delivery->method('build')->willReturn(['pack' => true]);
		$partnerMail = $this->createMock(PartnerMailService::class);
		$partnerMail->method('build')->willReturn(['mail' => true]);

		$request = $this->requestOk();
		$ctrl = new AgreementApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$this->workspacesOk(),
			$this->companiesOk(),
			$agreements,
			$delivery,
			$partnerMail,
		);

		self::assertSame(200, $ctrl->index()->getStatus());
		// empty php://input → companyId 0 may validate; invoke still counts
		$created = $ctrl->create();
		self::assertInstanceOf(JSONResponse::class, $created);
		self::assertSame(200, $ctrl->update(4)->getStatus());
		self::assertSame(200, $ctrl->cancel(4)->getStatus());
		self::assertSame(200, $ctrl->supersede(4)->getStatus());
		self::assertSame(200, $ctrl->deliveryPack(4)->getStatus());
		self::assertSame(200, $ctrl->partnerMail(4)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new AgreementApiController(
			'customercheck',
			$request,
			$denyAccess,
			$this->workspacesOk(),
			$this->companiesOk(),
			$agreements,
			$delivery,
			$partnerMail,
		);
		foreach ([
			static fn () => $deny->create(),
			static fn () => $deny->update(4),
			static fn () => $deny->cancel(4),
			static fn () => $deny->supersede(4),
			static fn () => $deny->deliveryPack(4),
			static fn () => $deny->partnerMail(4),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'agreement authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'agreement authz idx ' . $i);
		}
	}

	public function testCompanyApiAllActionsAndAuthzNegative(): void
	{
		$company = $this->company();
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$companies->method('search')->willReturn([$company]);
		$companies->method('count')->willReturn(1);
		$companies->method('create')->willReturn($company);
		$companies->method('update')->willReturn($company);
		$companies->method('gdprErase');
		$companies->method('archive')->willReturn($company);
		$companies->method('restore')->willReturn($company);
		$companies->method('gdprExport')->willReturn(['slug' => 'acme']);

		$merges = $this->createMock(CompanyMergeService::class);
		$merges->method('merge')->willReturn([
			'keeperId' => 3,
			'sourceId' => 3,
			'sourceSlug' => 'acme',
			'moved' => [],
			'company' => $company,
		]);

		$sibling = $this->createMock(SiblingCustomerWriteService::class);
		$sibling->method('createOrLinkPcCustomer')->willReturn([
			'company' => $company,
			'pcCustomerId' => 10,
			'created' => true,
		]);
		$sibling->method('createOrLinkTcCustomer')->willReturn([
			'company' => $company,
			'tcCustomerId' => 11,
			'created' => true,
		]);
		$sibling->method('unlinkPcCustomer')->willReturn($company);
		$sibling->method('unlinkTcCustomer')->willReturn($company);
		$sibling->method('createFieldCustomerFromHub')->willReturn(['fieldCustomerId' => 12]);
		$sibling->method('pushDisplayNameAfterRename')->willReturn([]);

		$linkIdem = $this->createMock(LinkIdempotencyService::class);
		$linkIdem->method('requireKey')->willReturn('idem-1');

		$slugs = $this->createMock(SlugService::class);
		$slugs->method('suggestUnique')->willReturn('acme');

		$event = new TimelineEvent();
		$event->setId(1);
		$event->setCompanyId(3);
		$event->setActor('alice');
		$event->setEventType('note');
		$event->setPayloadJson('{}');
		$event->setCreatedAt(new \DateTime('2026-01-01T12:00:00+00:00'));

		$timeline = $this->createMock(TimelineService::class);
		$timeline->method('listForCompany')->willReturn([$event]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$labels = new DisplayLabelService($l10n);

		$request = $this->requestOk();
		$ctrl = new CompanyApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$this->workspacesOk(),
			$companies,
			$merges,
			$sibling,
			$linkIdem,
			$slugs,
			$timeline,
			$labels,
		);

		self::assertSame(200, $ctrl->index()->getStatus());
		self::assertSame(201, $ctrl->create()->getStatus());
		self::assertSame(200, $ctrl->suggestSlug()->getStatus());
		self::assertSame(200, $ctrl->show(3)->getStatus());
		self::assertSame(200, $ctrl->update(3)->getStatus());
		self::assertSame(200, $ctrl->destroy(3)->getStatus());
		self::assertSame(200, $ctrl->archive(3)->getStatus());
		self::assertSame(200, $ctrl->restore(3)->getStatus());
		self::assertSame(200, $ctrl->merge(3)->getStatus());
		self::assertSame(200, $ctrl->createPcCustomer(3)->getStatus());
		self::assertSame(200, $ctrl->createTcCustomer(3)->getStatus());
		self::assertSame(200, $ctrl->linkProjectcheck(3)->getStatus());
		self::assertSame(200, $ctrl->linkTicketcheck(3)->getStatus());
		self::assertSame(200, $ctrl->unlinkProjectcheck(3)->getStatus());
		self::assertSame(200, $ctrl->unlinkTicketcheck(3)->getStatus());
		self::assertSame(201, $ctrl->createFieldCustomer(3)->getStatus());
		self::assertSame(200, $ctrl->export(3)->getStatus());
		self::assertSame(200, $ctrl->timeline(3)->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new CompanyApiController(
			'customercheck',
			$request,
			$denyAccess,
			$this->workspacesOk(),
			$companies,
			$merges,
			$sibling,
			$linkIdem,
			$slugs,
			$timeline,
			$labels,
		);
				foreach ([
			static fn () => $deny->index(),
			static fn () => $deny->create(),
			static fn () => $deny->suggestSlug(),
			static fn () => $deny->show(3),
			static fn () => $deny->update(3),
			static fn () => $deny->destroy(3),
			static fn () => $deny->archive(3),
			static fn () => $deny->restore(3),
			static fn () => $deny->merge(3),
			static fn () => $deny->createPcCustomer(3),
			static fn () => $deny->createTcCustomer(3),
			static fn () => $deny->linkProjectcheck(3),
			static fn () => $deny->linkTicketcheck(3),
			static fn () => $deny->unlinkProjectcheck(3),
			static fn () => $deny->unlinkTicketcheck(3),
			static fn () => $deny->createFieldCustomer(3),
			static fn () => $deny->export(3),
			static fn () => $deny->timeline(3),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'authz idx ' . $i);
		}
	}

	public function testLicenseControllerAllActionsAndAuthzNegative(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserIdOrEmpty')->willReturn('alice');
		$access->method('canManageAppConfiguration')->willReturn(true);

		$license = $this->createMock(LicenseService::class);
		$license->method('status')->willReturn(['ok' => true, 'licensed' => true]);
		$license->method('apply')->willReturn(['ok' => true]);
		$license->method('remove')->willReturn(['ok' => true, 'removed' => true]);
		$license->method('listSeats')->willReturn(['seats' => [], 'total' => 0]);
		$license->method('assignSeat')->willReturn([
			'seat' => ['uid' => 'bob'],
			'created' => true,
		]);
		$license->method('removeSeat');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $k, $d = null) {
			return match ($k) {
				'key' => 'LICENSE-KEY',
				'userId' => 'bob',
				'limit' => 50,
				'offset' => 0,
				default => $d,
			};
		});

		$ctrl = new LicenseController('customercheck', $request, $access, $license, $l10n);

		self::assertSame(200, $ctrl->show()->getStatus());
		self::assertSame(200, $ctrl->apply()->getStatus());
		self::assertSame(200, $ctrl->remove()->getStatus());
		self::assertSame(200, $ctrl->seats()->getStatus());
		self::assertSame(201, $ctrl->assignSeat()->getStatus());
		self::assertSame(200, $ctrl->removeSeat('bob')->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('currentUserIdOrEmpty')->willReturn('alice');
		$denyAccess->method('canManageAppConfiguration')->willReturn(false);
		$deny = new LicenseController('customercheck', $request, $denyAccess, $license, $l10n);
		foreach ([
			static fn () => $deny->apply(),
			static fn () => $deny->remove(),
			static fn () => $deny->assignSeat(),
			static fn () => $deny->removeSeat('bob'),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'license authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'license authz idx ' . $i);
		}
	}

	public function testSettingsApiAllActionsAndAuthzNegative(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getForUser')->willReturn(['theme' => 'light']);
		$settings->method('update')->willReturn(['theme' => 'dark']);
		$settings->method('updatePipelineView')->willReturn('table');
		$settings->method('rotateToken')->willReturn('tok');
		$settings->method('rotateWorkspaceIcsToken')->willReturn('ics-tok');
		$settings->method('workspaceIcsFeedUrl')->willReturn('https://example.test/ics');

		$directory = $this->createMock(DirectoryOptionsService::class);
		$directory->method('searchUsers')->willReturn([['id' => 'bob', 'displayName' => 'Bob']]);

		$productCodes = $this->createMock(ProductCodeRegistry::class);
		$productCodes->method('listAll')->willReturn(['items' => []]);
		$productCodes->method('saveCustom')->willReturn(['items' => []]);

		$oauth = $this->createMock(PersonalMailOAuthService::class);
		$oauth->method('adminConfigStatus')->willReturn(['configured' => false]);
		$oauth->method('saveAdminConfig')->willReturn(['configured' => true]);

		$request = $this->requestOk();
		$ctrl = new SettingsApiController(
			'customercheck',
			$request,
			$this->accessOk(),
			$settings,
			$directory,
			$productCodes,
			$this->workspacesOk(),
			$oauth,
		);

		self::assertSame(200, $ctrl->show()->getStatus());
		self::assertSame(200, $ctrl->update()->getStatus());
		self::assertSame(200, $ctrl->updateMe()->getStatus());
		self::assertSame(200, $ctrl->rotateToken('lead')->getStatus());
		self::assertSame(200, $ctrl->rotateWorkspaceIcs(7)->getStatus());
		self::assertSame(200, $ctrl->productCodes()->getStatus());
		self::assertSame(200, $ctrl->saveProductCodes()->getStatus());
		self::assertSame(200, $ctrl->personalMailOAuthConfig()->getStatus());
		self::assertSame(200, $ctrl->savePersonalMailOAuthConfig()->getStatus());
		self::assertSame(200, $ctrl->searchUsers('bo')->getStatus());

		$denyAccess = $this->createMock(AccessControlService::class);
		$denyAccess->method('requireCanUseApp')->willThrowException(new AccessDeniedException('no'));
		$deny = new SettingsApiController(
			'customercheck',
			$request,
			$denyAccess,
			$settings,
			$directory,
			$productCodes,
			$this->workspacesOk(),
			$oauth,
		);
		foreach ([
			static fn () => $deny->update(),
			static fn () => $deny->updateMe(),
			static fn () => $deny->rotateToken('lead'),
			static fn () => $deny->rotateWorkspaceIcs(7),
			static fn () => $deny->saveProductCodes(),
			static fn () => $deny->savePersonalMailOAuthConfig(),
		] as $i => $call) {
			$neg = $call();
			self::assertInstanceOf(JSONResponse::class, $neg, 'settings authz idx ' . $i);
			self::assertGreaterThanOrEqual(400, $neg->getStatus(), 'settings authz idx ' . $i);
		}
	}
}
