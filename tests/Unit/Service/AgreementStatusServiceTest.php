<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * PSD §4.7-B / S2 — status derivation boundary vectors.
 */
class AgreementStatusServiceTest extends TestCase
{
	private AgreementStatusService $service;

	protected function setUp(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $c): string => $c === 1 ? $s : $p);
		$this->service = new AgreementStatusService($l10n);
	}

	/** @dataProvider expiryBoundaryProvider */
	public function testDeriveStatusBoundaries(int $daysUntil, string $expected): void
	{
		$today = new \DateTimeImmutable('2026-07-25');
		$validUntil = $today->modify(($daysUntil >= 0 ? '+' : '') . $daysUntil . ' days');
		$agreement = $this->activeLicense($validUntil);

		$this->assertSame($expected, $this->service->deriveStatus($agreement, $today));
	}

	/** @return array<string, array{0: int, 1: string}> */
	public function expiryBoundaryProvider(): array
	{
		return [
			'61 days stays active' => [61, 'active'],
			'60 days becomes expiring' => [60, 'expiring'],
			'59 days stays expiring' => [59, 'expiring'],
			'1 day stays expiring' => [1, 'expiring'],
			'expiry day still expiring' => [0, 'expiring'],
			'yesterday expired' => [-1, 'expired'],
			'plus 1 expired' => [-1, 'expired'],
		];
	}

	/** @dataProvider graceBoundaryProvider */
	public function testGraceBoundaries(int $daysUntil, bool $inGrace): void
	{
		$today = new \DateTimeImmutable('2026-07-25');
		$validUntil = $today->modify(($daysUntil >= 0 ? '+' : '') . $daysUntil . ' days');
		$this->assertSame($inGrace, $this->service->isInGrace($validUntil, $today));
	}

	/** @return array<string, array{0: int, 1: bool}> */
	public function graceBoundaryProvider(): array
	{
		return [
			'expiry day not grace' => [0, false],
			'day -1 in grace' => [-1, true],
			'day -7 in grace' => [-7, true],
			'day -8 out of grace' => [-8, false],
			'future not grace' => [5, false],
		];
	}

	public function testTerminalStatusesUnchanged(): void
	{
		$today = new \DateTimeImmutable('2026-07-25');
		foreach (Constants::AGREEMENT_TERMINAL_STATUSES as $status) {
			$a = $this->activeLicense($today->modify('+10 days'));
			$a->setStatus($status);
			$this->assertSame($status, $this->service->deriveStatus($a, $today));
		}
	}

	public function testDraftUnchanged(): void
	{
		$today = new \DateTimeImmutable('2026-07-25');
		$a = $this->activeLicense($today->modify('+10 days'));
		$a->setStatus('draft');
		$this->assertSame('draft', $this->service->deriveStatus($a, $today));
	}

	public function testIllegalTransitionCancelledToActive(): void
	{
		$this->assertFalse($this->service->isTransitionAllowed('cancelled', 'active'));
		$this->expectException(\OCA\CustomerCheck\Exception\ValidationException::class);
		$this->service->assertTransitionAllowed('cancelled', 'active');
	}

	public function testOnetimeHasNoExpiryStates(): void
	{
		$today = new \DateTimeImmutable('2026-07-25');
		$a = new Agreement();
		$a->setKind('onetime');
		$a->setStatus('active');
		$a->setValidUntil(null);
		$this->assertSame('active', $this->service->deriveStatus($a, $today));
	}

	private function activeLicense(\DateTimeInterface $validUntil): Agreement
	{
		$a = new Agreement();
		$a->setKind('license');
		$a->setStatus('active');
		$a->setValidUntil(new \DateTime($validUntil->format('Y-m-d')));

		return $a;
	}
}
