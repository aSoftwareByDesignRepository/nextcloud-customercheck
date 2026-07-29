<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\BillingIntegrityService;
use OCA\CustomerCheck\Service\CommunicationFeedService;
use OCA\CustomerCheck\Service\CompanyCommandService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailRelatedService;
use OCA\CustomerCheck\Service\NextActionPriority;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\VTodoService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * AC-CC-2 / AC-CC-13 — CompanyCommandService build() wiring.
 */
final class CompanyCommandServiceBuildTest extends TestCase
{
	private function makeService(
		Company $company,
		array $openActivities = [],
		?BillingIntegrityService $billing = null,
	): CompanyCommandService {
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$persons = $this->createMock(PersonService::class);
		$persons->method('listForCompany')->willReturn([]);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findByCompany')->willReturn([]);

		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenByCompany')->willReturn($openActivities);
		$activities->method('countSearch')->willReturn(count($openActivities));
		$activities->method('search')->willReturn([]);

		$touches = $this->createMock(EmailTouchMapper::class);
		$touches->method('findByCompany')->willReturn([]);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('capabilityMatrix')->willReturn([
			'projectcheck' => false,
			'invoicecheck' => false,
			'ticketcheck' => false,
			'mail' => false,
		]);
		$facades->method('projectcheckAvailable')->willReturn(false);
		$facades->method('invoicecheckAvailable')->willReturn(false);
		$facades->method('ticketcheckAvailable')->willReturn(false);

		$billingSvc = $billing ?? new BillingIntegrityService();
		$priority = new NextActionPriority();
		$status = $this->createMock(AgreementStatusService::class);
		$deals = $this->createMock(DealService::class);
		$deals->method('search')->willReturn([]);
		$vTodo = $this->createMock(VTodoService::class);
		$vTodo->method('isAvailable')->willReturn(false);
		$feed = new CommunicationFeedService();
		$mail = $this->createMock(MailRelatedService::class);
		$mail->method('forCompany')->willReturn(['related' => []]);
		$coverage = $this->createMock(CoverageService::class);
		$coverage->method('forCompany')->willReturn([
			'covered' => false,
			'gap' => false,
			'missing' => [],
			'flags' => [],
			'kamTier' => 'standard',
			'touchIntervalDays' => 30,
			'nextTouchOn' => null,
		]);
		$mailbox = $this->createMock(MailboxService::class);
		$mailbox->method('matchedForCompany')->willReturn([]);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-27T12:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $a === [] ? $s : vsprintf($s, $a));

		return new CompanyCommandService(
			$companies,
			$persons,
			$agreements,
			$activities,
			$touches,
			$facades,
			$billingSvc,
			$priority,
			$status,
			$deals,
			$vTodo,
			$feed,
			$mail,
			$coverage,
			$mailbox,
			$apps,
			$time,
			$l,
		);
	}

	public function testBuildReportsFilesLinkedAndNoUrgentAction(): void
	{
		$company = new Company();
		$company->setId(5);
		$company->setName('Acme');
		$company->setSlug('acme');
		$company->setStatus('active');
		$company->setFilesFolderId(99);
		$company->setUpdatedAt(new \DateTime('2026-07-27T10:00:00+00:00'));

		$dto = $this->makeService($company)->build(5, 'admin');
		self::assertSame(5, $dto['companyId']);
		self::assertTrue($dto['counts']['filesLinked']);
		self::assertSame('none', $dto['nextAction']['kind']);
		self::assertSame('unknown', $dto['billing']['state']);
		self::assertSame(0, $dto['counts']['openCallbacks']);
	}

	public function testBuildCountsOpenCallbacksViaFlag(): void
	{
		$company = new Company();
		$company->setId(5);
		$company->setName('Acme');
		$company->setSlug('acme');
		$company->setStatus('active');

		$cb = new Activity();
		$cb->setId(1);
		$cb->setIsCallback(1);
		$cb->setType('meeting');
		$cb->setTitle('Callback: Ada');
		$cb->setCompanyId(5);

		$note = new Activity();
		$note->setId(2);
		$note->setIsCallback(0);
		$note->setType('todo');
		$note->setTitle('Write proposal');
		$note->setCompanyId(5);

		$dto = $this->makeService($company, [$cb, $note])->build(5, 'admin');
		self::assertSame(1, $dto['counts']['openCallbacks']);
		self::assertFalse($dto['counts']['filesLinked']);
	}

	public function testBuildCountsWaitingTicketsNotAllOpen(): void
	{
		$company = new Company();
		$company->setId(5);
		$company->setName('Acme');
		$company->setSlug('acme');
		$company->setStatus('active');
		$company->setTcCustomerId(77);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$persons = $this->createMock(PersonService::class);
		$persons->method('listForCompany')->willReturn([]);
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findByCompany')->willReturn([]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenByCompany')->willReturn([]);
		$activities->method('countSearch')->willReturn(0);
		$activities->method('search')->willReturn([]);
		$touches = $this->createMock(EmailTouchMapper::class);
		$touches->method('findByCompany')->willReturn([]);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('capabilityMatrix')->willReturn([
			'projectcheck' => false,
			'invoicecheck' => false,
			'ticketcheck' => true,
			'mail' => false,
		]);
		$facades->method('projectcheckAvailable')->willReturn(false);
		$facades->method('invoicecheckAvailable')->willReturn(false);
		$facades->method('ticketcheckAvailable')->willReturn(true);
		$facades->method('listOpenTicketsForCustomer')->willReturn([
			['id' => 1, 'status' => 'open', 'subject' => 'A'],
			['id' => 2, 'status' => 'waiting_customer', 'subject' => 'B'],
			['id' => 3, 'status' => 'waiting_us', 'subject' => 'C'],
		]);

		$coverage = $this->createMock(CoverageService::class);
		$coverage->method('forCompany')->willReturn([
			'covered' => false,
			'gap' => false,
			'missing' => [],
			'flags' => [],
			'kamTier' => 'standard',
			'touchIntervalDays' => 30,
			'nextTouchOn' => null,
		]);
		$mailbox = $this->createMock(MailboxService::class);
		$mailbox->method('matchedForCompany')->willReturn([]);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-27T12:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $a === [] ? $s : vsprintf($s, $a));
		$deals = $this->createMock(DealService::class);
		$deals->method('search')->willReturn([]);
		$vTodo = $this->createMock(VTodoService::class);
		$vTodo->method('isAvailable')->willReturn(false);
		$mail = $this->createMock(MailRelatedService::class);
		$mail->method('forCompany')->willReturn(['related' => []]);

		$svc = new CompanyCommandService(
			$companies,
			$persons,
			$agreements,
			$activities,
			$touches,
			$facades,
			new BillingIntegrityService(),
			new NextActionPriority(),
			$this->createMock(AgreementStatusService::class),
			$deals,
			$vTodo,
			new CommunicationFeedService(),
			$mail,
			$coverage,
			$mailbox,
			$apps,
			$time,
			$l,
		);

		$dto = $svc->build(5, 'admin');
		self::assertSame(2, $dto['counts']['waitingTickets']);
		self::assertSame('ticket_waiting', $dto['nextAction']['kind']);
	}
}
