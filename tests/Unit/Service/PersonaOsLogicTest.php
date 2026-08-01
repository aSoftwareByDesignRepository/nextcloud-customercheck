<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\BriefLogic;
use OCA\CustomerCheck\Service\ChaseLogic;
use OCA\CustomerCheck\Service\CoverageLogic;
use OCA\CustomerCheck\Service\MailMatchLogic;
use OCA\CustomerCheck\Service\OooLogic;
use OCA\CustomerCheck\Service\PulseLogic;
use OCA\CustomerCheck\Service\StuckLogic;
use OCA\CustomerCheck\Service\WorkspaceCapability;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-critical pure logic — product bible §9–§14.
 */
final class PersonaOsLogicTest extends TestCase
{
	public function testChaseHealthyRequiresActivityOrSkip(): void
	{
		$today = new \DateTimeImmutable('2026-07-28');
		$this->assertFalse(ChaseLogic::isHealthy(false, null, $today));
		$this->assertTrue(ChaseLogic::isHealthy(true, null, $today));
		$this->assertTrue(ChaseLogic::isHealthy(false, new \DateTimeImmutable('2026-07-28'), $today));
		$this->assertFalse(ChaseLogic::isHealthy(false, new \DateTimeImmutable('2026-07-27'), $today));
	}

	public function testChaseSkipMaxSevenDays(): void
	{
		$today = new \DateTimeImmutable('2026-07-28');
		$this->assertTrue(ChaseLogic::isSkipAllowed(new \DateTimeImmutable('2026-08-04'), $today));
		$this->assertFalse(ChaseLogic::isSkipAllowed(new \DateTimeImmutable('2026-08-05'), $today));
	}

	public function testChaseSeverityOrderLeadSlaFirst(): void
	{
		$today = new \DateTimeImmutable('2026-07-28 12:00:00');
		$reasons = ChaseLogic::reasons([
			'stage' => 'lead',
			'ownerUserId' => '',
			'leadSlaDueAt' => new \DateTimeImmutable('2026-07-27 12:00:00'),
			'leadFirstTouchAt' => null,
			'healthy' => false,
			'expectedClose' => new \DateTimeImmutable('2026-07-20'),
		], $today);
		$this->assertSame('lead_sla', $reasons[0]);
		$this->assertContains('unowned', $reasons);
		$this->assertContains('no_next_action', $reasons);
		$this->assertContains('close_overdue', $reasons);
	}

	public function testTerminalDealsHaveNoChaseReasons(): void
	{
		$today = new \DateTimeImmutable('2026-07-28');
		$this->assertSame([], ChaseLogic::reasons([
			'stage' => 'won',
			'healthy' => false,
			'ownerUserId' => '',
		], $today));
	}

	public function testCoverageRiskFlagsWireThroughEvaluate(): void
	{
		$people = [
			['buying_role' => 'economic_buyer', 'status' => 'active'],
			['buying_role' => 'champion', 'status' => 'left'],
			['buying_role' => 'day_to_day', 'status' => 'active'],
		];
		$eval = CoverageLogic::evaluate('key', $people, true, true, true, true);
		$this->assertContains('champion_gap', $eval['flags']);
		$this->assertContains('champion_left', $eval['flags']);
		$this->assertContains('touch_overdue', $eval['flags']);
		$this->assertContains('promise_overdue', $eval['flags']);
		$this->assertContains('partner_hours_hot', $eval['flags']);
		$this->assertContains('renewal_hot', $eval['flags']);
		$this->assertTrue($eval['gap']);
	}

	public function testCoverageGapOnlyForKeyPartner(): void
	{
		$people = [
			['buying_role' => 'economic_buyer', 'status' => 'active'],
		];
		$std = CoverageLogic::evaluate('standard', $people);
		$this->assertFalse($std['gap']);
		$key = CoverageLogic::evaluate('key', $people);
		$this->assertTrue($key['gap']);
		$this->assertContains('champion', $key['missing']);
	}

	public function testCoverageRequiresOpsRole(): void
	{
		$people = [
			['buying_role' => 'economic_buyer', 'status' => 'active'],
			['buying_role' => 'champion', 'status' => 'active'],
			['buying_role' => 'day_to_day', 'status' => 'active'],
		];
		$eval = CoverageLogic::evaluate('partner', $people);
		$this->assertTrue($eval['covered']);
		$this->assertFalse($eval['gap']);
	}

	public function testPartnerTierFromProductCode(): void
	{
		$this->assertSame(30, CoverageLogic::defaultTouchIntervalDays('partner', 'cp-prem'));
		$this->assertSame(90, CoverageLogic::defaultTouchIntervalDays('partner', 'cp-std'));
		$this->assertSame(14, CoverageLogic::defaultTouchIntervalDays('key', null));
	}

	public function testKamTierLockPreventsAutoPartner(): void
	{
		$this->assertSame('standard', CoverageLogic::resolveKamTier('standard', true, true));
		$this->assertSame('partner', CoverageLogic::resolveKamTier('standard', false, true));
	}

	public function testBriefWipRejectsAtLimit(): void
	{
		$this->assertTrue(BriefLogic::canConfirm(4, 5));
		$this->assertFalse(BriefLogic::canConfirm(5, 5));
		$this->assertSame(15, BriefLogic::clampWipLimit(99));
		$this->assertSame(3, BriefLogic::clampWipLimit(1));
	}

	public function testCashCalendarExcludesPipelineAndForecast(): void
	{
		$cash = BriefLogic::cashCalendar([
			'overdueArCents' => 100,
			'arDueThisMonthCents' => 200,
			'renewalsThisMonthCents' => 50,
			'pipelineCents' => 99999,
			'forecastCommitCents' => 88888,
		]);
		$this->assertSame(250, $cash['expectedInThisMonthCents']);
		$this->assertSame(99999, $cash['pipelineExcludedCents']);
		$this->assertSame(88888, $cash['forecastExcludedCents']);
		$this->assertNotEquals($cash['expectedInThisMonthCents'], $cash['pipelineExcludedCents']);
	}

	public function testCarryStreakMaxTwo(): void
	{
		$this->assertTrue(BriefLogic::canCarry(0));
		$this->assertTrue(BriefLogic::canCarry(1));
		$this->assertFalse(BriefLogic::canCarry(2));
	}

	public function testUnbilledHonestyBlockedNeverZeroAsTruth(): void
	{
		$blocked = PulseLogic::unbilled([
			'pcAvailable' => true,
			'stubOpenHours' => true,
			'openBillableMinutes' => 0,
			'billableKnown' => false,
		]);
		$this->assertSame('honesty_blocked', $blocked['state']);
		$this->assertNull($blocked['openBillableMinutes']);

		$ok = PulseLogic::unbilled([
			'pcAvailable' => true,
			'stubOpenHours' => false,
			'billableKnown' => true,
			'openBillableMinutes' => 0,
		]);
		$this->assertSame('ok', $ok['state']);
		$this->assertSame(0, $ok['openBillableMinutes']);
	}

	public function testWaitingCountIgnoresAllOpen(): void
	{
		$this->assertSame(2, PulseLogic::countWaiting([
			['status' => 'open'],
			['waiting' => true],
			['status' => 'waiting_on_us'],
		]));
	}

	public function testLinkDebtOnlyWhenCapable(): void
	{
		$missing = PulseLogic::linkDebt([
			'pcCapable' => true,
			'tcCapable' => false,
			'filesCapable' => true,
			'pcLinked' => false,
			'tcLinked' => false,
			'filesLinked' => false,
		]);
		$this->assertSame(['ProjectCheck', 'Files'], $missing);
	}

	public function testMailMatchFreemailNeverDomain(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'a@acme.test'],
			['companyId' => 2, 'email' => 'b@gmail.com'],
		];
		$r = MailMatchLogic::match(null, 'stranger@gmail.com', $persons);
		$this->assertSame('unmatched', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testMailMatchExactPersonWins(): void
	{
		$persons = [['companyId' => 9, 'email' => 'ada@acme.test']];
		$r = MailMatchLogic::match(null, 'ada@acme.test', $persons);
		$this->assertSame('person_email', $r['how']);
		$this->assertSame(9, $r['companyId']);
	}

	public function testMailMatchAmbiguousDomainUnmatched(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'a@acme.test'],
			['companyId' => 2, 'email' => 'b@acme.test'],
		];
		$r = MailMatchLogic::match(null, 'x@acme.test', $persons);
		$this->assertSame('unmatched', $r['how']);
	}

	public function testAuthFailTimesThreeErrors(): void
	{
		$this->assertSame('ok', MailMatchLogic::nextAuthStatus(2));
		$this->assertSame('error', MailMatchLogic::nextAuthStatus(3));
	}

	public function testOooOverlapAndMaxDays(): void
	{
		$existing = [[
			'primaryUserId' => 'alex',
			'startsOn' => '2026-08-01',
			'endsOn' => '2026-08-10',
			'id' => 1,
		]];
		$err = OooLogic::validate(
			'alex',
			'sam',
			new \DateTimeImmutable('2026-08-05'),
			new \DateTimeImmutable('2026-08-12'),
			$existing,
		);
		$this->assertSame(Constants::ERR_OOO_OVERLAP, $err);

		$long = OooLogic::validate(
			'alex',
			'sam',
			new \DateTimeImmutable('2026-08-01'),
			new \DateTimeImmutable('2026-10-15'),
			[],
		);
		$this->assertSame(Constants::ERR_OOO_TOO_LONG, $long);
	}

	public function testOooEndedCoverDoesNotBlockReschedule(): void
	{
		$existing = [[
			'primaryUserId' => 'alex',
			'startsOn' => '2026-08-01',
			'endsOn' => '2026-08-10',
			'status' => 'ended',
			'id' => 1,
		]];
		$err = OooLogic::validate(
			'alex',
			'sam',
			new \DateTimeImmutable('2026-08-05'),
			new \DateTimeImmutable('2026-08-12'),
			$existing,
		);
		$this->assertNull($err);
	}

	public function testOooNeverSamePrimaryBackup(): void
	{
		$err = OooLogic::validate(
			'alex',
			'alex',
			new \DateTimeImmutable('2026-08-01'),
			new \DateTimeImmutable('2026-08-05'),
			[],
		);
		$this->assertSame(Constants::ERR_VALIDATION, $err);
	}

	public function testStuckRules(): void
	{
		$this->assertTrue(StuckLogic::isStuck(['chaseCount' => 1]));
		$this->assertFalse(StuckLogic::isStuck([]));
		$this->assertTrue(StuckLogic::shouldAutoResolve(false));
		$this->assertFalse(StuckLogic::shouldAutoResolve(true));
	}

	public function testWorkspaceCapabilityMatrix(): void
	{
		$this->assertFalse(WorkspaceCapability::allows('viewer', 'write'));
		$this->assertTrue(WorkspaceCapability::allows('member', 'mail.send'));
		$this->assertTrue(WorkspaceCapability::allows('member', 'brief.confirm'));
		$this->assertFalse(WorkspaceCapability::allows('member', 'bulk'));
		$this->assertTrue(WorkspaceCapability::allows('manager', 'ooo'));
		$this->assertTrue(WorkspaceCapability::allows('viewer', 'write', true));
		$this->assertFalse(WorkspaceCapability::canOpenTeamBoard('viewer'));
		$this->assertTrue(WorkspaceCapability::teamSeesAllRows('manager'));
		$this->assertFalse(WorkspaceCapability::teamSeesAllRows('member'));
	}

	public function testBriefDoneRequiresEvidence(): void
	{
		$this->assertSame(Constants::ERR_VALIDATION, BriefLogic::validateDoneEvidence(null, null));
		$this->assertSame(Constants::ERR_VALIDATION, BriefLogic::validateDoneEvidence('', null));
		$this->assertSame(Constants::ERR_VALIDATION, BriefLogic::validateDoneEvidence('hope', null));
		$this->assertNull(BriefLogic::validateDoneEvidence('invoice_paid', null));
		$this->assertNull(BriefLogic::validateDoneEvidence('activity_done', null));
		$this->assertNull(BriefLogic::validateDoneEvidence('links_fixed', null));
		$this->assertNull(BriefLogic::validateDoneEvidence('plan_healthy', null));
		$this->assertSame(Constants::ERR_VALIDATION, BriefLogic::validateDoneEvidence('manager_override', ''));
		$this->assertSame(Constants::ERR_VALIDATION, BriefLogic::validateDoneEvidence('manager_override', null));
		$this->assertNull(BriefLogic::validateDoneEvidence('manager_override', 'IC down — trust AE'));
		$this->assertContains('manager_override', BriefLogic::doneEvidenceCodes());
	}

	public function testUnbilledReviewFilteredWhenHonestyBlocked(): void
	{
		$kinds = BriefLogic::filterSuggestions(['ar_chase', 'unbilled_review', 'link_debt'], true);
		$this->assertSame(['ar_chase', 'link_debt'], $kinds);
	}

	public function testIsoWeekKey(): void
	{
		$key = BriefLogic::isoWeekKey(new \DateTimeImmutable('2026-07-28'));
		$this->assertMatchesRegularExpression('/^\d{4}-W\d{2}$/', $key);
	}
}
