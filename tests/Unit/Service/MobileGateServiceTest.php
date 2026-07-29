<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\MobileGateException;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Service\MobileGateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Companion bootstrap contract (COMPANION-APP §9.3) + gate delegation.
 */
final class MobileGateServiceTest extends TestCase
{
	private LicenseService&MockObject $license;
	private FacadeProbeService&MockObject $facades;

	protected function setUp(): void
	{
		$this->license = $this->createMock(LicenseService::class);
		$this->facades = $this->createMock(FacadeProbeService::class);
	}

	private function service(): MobileGateService
	{
		return new MobileGateService($this->license, $this->facades);
	}

	public function testAssertGatePassedDelegates(): void
	{
		$this->license->expects($this->once())->method('assertMobileAccess')->with('alice');
		$this->service()->assertGatePassed('alice');
	}

	public function testAssertGatePassedPropagatesGateFailure(): void
	{
		$this->license->method('assertMobileAccess')
			->willThrowException(new MobileGateException('NO_MOBILE_SEAT', 'no seat'));

		$this->expectException(MobileGateException::class);
		$this->service()->assertGatePassed('alice');
	}

	public function testBootstrapWithoutLicenseHasNullLicensing(): void
	{
		$this->license->method('gateState')->willReturn([
			'hasLicense' => false,
			'licenseValid' => false,
			'seatAssigned' => false,
			'seatWithinLimit' => false,
			'payloadB64' => null,
			'signatureB64' => null,
		]);
		$this->license->method('status')->willReturn(['state' => null]);

		$payload = $this->service()->bootstrapPayload('alice', 'Alice A.', '1.0.7');

		$this->assertSame('customercheck', $payload['appId']);
		$this->assertSame('1.0.7', $payload['serverVersion']);
		$this->assertSame(1, $payload['companionApi']);
		$this->assertSame(MobileGateService::MIN_APP_VERSION, $payload['minAppVersion']);
		$this->assertSame(MobileGateService::MIN_APP_VERSION, $payload['minApp']);
		$this->assertNull($payload['licensing']);
		$this->assertFalse($payload['seatAssigned']);
		$this->assertFalse($payload['seatWithinLimit']);
		$this->assertSame('available', $payload['mobileAppStatus']);
		$this->assertSame(
			['userId' => 'alice', 'uid' => 'alice', 'displayName' => 'Alice A.'],
			$payload['user'],
		);
	}

	public function testBootstrapCapabilitiesReflectFacadeProbes(): void
	{
		$this->license->method('gateState')->willReturn([
			'hasLicense' => false,
			'licenseValid' => false,
			'seatAssigned' => false,
			'seatWithinLimit' => false,
			'payloadB64' => null,
			'signatureB64' => null,
		]);
		$this->license->method('status')->willReturn(['state' => null]);
		$this->facades->method('projectcheckAvailable')->willReturn(true);
		$this->facades->method('invoicecheckAvailable')->willReturn(false);
		$this->facades->method('ticketcheckAvailable')->willReturn(true);

		$caps = $this->service()->bootstrapPayload('alice', 'Alice', '1.0.7')['capabilities'];

		$this->assertTrue($caps['today']);
		$this->assertTrue($caps['companies']);
		$this->assertTrue($caps['activities']);
		$this->assertTrue($caps['deals']);
		$this->assertTrue($caps['agreements']);
		$this->assertTrue($caps['push'], 'P2 advertises push capability');
		$this->assertTrue($caps['projectcheck']);
		$this->assertFalse($caps['invoicecheck']);
		$this->assertTrue($caps['ticketcheck']);
	}

	public function testBootstrapWithValidLicenseAndSeatEmbedsEnvelope(): void
	{
		$this->license->method('gateState')->willReturn([
			'hasLicense' => true,
			'licenseValid' => true,
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'payloadB64' => 'PAYLOAD',
			'signatureB64' => 'SIG',
		]);
		$this->license->method('status')->willReturn([
			'state' => ['validUntil' => '2027-12-31'],
		]);

		$payload = $this->service()->bootstrapPayload('alice', 'Alice', '1.0.7');
		$licensing = $payload['licensing'];

		$this->assertNotNull($licensing);
		$this->assertSame('CUC2', $licensing['format']);
		$this->assertSame('PAYLOAD', $licensing['payloadB64']);
		$this->assertSame('SIG', $licensing['signatureB64']);
		$this->assertSame(
			['format' => 'CUC2', 'payloadB64' => 'PAYLOAD', 'signatureB64' => 'SIG'],
			$licensing['envelope'],
		);
		$this->assertNotSame('', $licensing['vendorPublicKeyB64']);
		$this->assertTrue($licensing['mobile']['enabledForUser']);
		$this->assertSame('2027-12-31', $licensing['mobile']['expiresAt']);
		$this->assertTrue($payload['seatAssigned']);
		$this->assertTrue($payload['seatWithinLimit']);
	}

	public function testBootstrapExpiredLicenseStillShipsEnvelopeButDisabled(): void
	{
		// The client verifies expiry itself — the server must hand over the
		// stored envelope with enabledForUser=false so the app can show why.
		$this->license->method('gateState')->willReturn([
			'hasLicense' => true,
			'licenseValid' => false,
			'seatAssigned' => true,
			'seatWithinLimit' => true,
			'payloadB64' => 'PAYLOAD',
			'signatureB64' => 'SIG',
		]);
		$this->license->method('status')->willReturn([
			'state' => ['validUntil' => '2020-12-31'],
		]);

		$licensing = $this->service()->bootstrapPayload('alice', 'Alice', '1.0.7')['licensing'];

		$this->assertNotNull($licensing);
		$this->assertFalse($licensing['mobile']['enabledForUser']);
		$this->assertSame('2020-12-31', $licensing['mobile']['expiresAt']);
	}

	public function testBootstrapSeatOverLimitDisablesUser(): void
	{
		$this->license->method('gateState')->willReturn([
			'hasLicense' => true,
			'licenseValid' => true,
			'seatAssigned' => true,
			'seatWithinLimit' => false,
			'payloadB64' => 'PAYLOAD',
			'signatureB64' => 'SIG',
		]);
		$this->license->method('status')->willReturn([
			'state' => ['validUntil' => '2027-12-31'],
		]);

		$licensing = $this->service()->bootstrapPayload('alice', 'Alice', '1.0.7')['licensing'];

		$this->assertNotNull($licensing);
		$this->assertFalse($licensing['mobile']['enabledForUser']);
	}
}
