<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\PulseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pulse waiting-ticket count must stay inside the active workspace (§9 / §21.1).
 */
final class PulseWaitingTicketsAclTest extends TestCase
{
	public function testWaitingTicketsCountOnlyLinkedTcCustomersInWorkspace(): void
	{
		$mine = new Company();
		$mine->setId(10);
		$mine->setName('Mine');
		$mine->setWorkspaceId(7);
		$mine->setStatus('active');
		$mine->setTcCustomerId(501);

		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->willReturn([$mine]);
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findRenewalCandidates')->with(2000, 0, 7)->willReturn([]);
		$deals = $this->createMock(DealMapper::class);
		$deals->method('search')->willReturn([]);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('projectcheckAvailable')->willReturn(false);
		$facades->method('ticketcheckAvailable')->willReturn(true);
		$facades->method('capabilityMatrix')->willReturn(['projectcheck' => false, 'ticketcheck' => true]);
		$facades->method('listWaitingTickets')->willReturn([
			['id' => 1, 'companyId' => null, 'customerId' => 501, 'subject' => 'Ours'],
			['id' => 2, 'companyId' => null, 'customerId' => 999, 'subject' => 'Foreign'],
		]);
		$money = $this->createMock(MoneyService::class);
		$money->method('overview')->willReturn(['groups' => ['overdue' => [], 'sent' => []]]);
		$status = $this->createMock(AgreementStatusService::class);
		$plans = $this->createMock(AccountPlanService::class);
		$plans->method('listForWorkspace')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-31'));

		$svc = new PulseService(
			$companies,
			$agreements,
			$deals,
			$facades,
			$money,
			$status,
			$plans,
			new DisplayLabelService($l10n),
			$time,
			$l10n,
		);
		$dto = $svc->build(7);
		$this->assertSame(1, $dto['metrics']['waitingTickets']);
	}
}
