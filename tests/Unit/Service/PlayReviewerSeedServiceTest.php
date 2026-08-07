<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Config\VendorPublicKey;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Service\PlayReviewerSeedService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class PlayReviewerSeedServiceTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE');
		putenv('CUC_VENDOR_PUBLIC_KEY_B64');
		parent::tearDown();
	}

	public function testRefusesWithoutVendorKeyOverride(): void
	{
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE');
		putenv('CUC_VENDOR_PUBLIC_KEY_B64');
		$svc = new PlayReviewerSeedService(
			$this->createMock(LicenseService::class),
			$this->createMock(IUserManager::class),
		);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('CUC_ALLOW_VENDOR_KEY_OVERRIDE');
		$svc->seed('play-reviewer', 'Play Reviewer', 'x');
	}

	public function testRejectsWrongOverridePublicKey(): void
	{
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE=1');
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::DEFAULT_PUBLIC_KEY_B64);
		$svc = new PlayReviewerSeedService(
			$this->createMock(LicenseService::class),
			$this->createMock(IUserManager::class),
		);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('golden test public key');
		$svc->seed('play-reviewer', 'Play Reviewer', 'x');
	}

	public function testRejectsInvalidUid(): void
	{
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE=1');
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);
		$svc = new PlayReviewerSeedService(
			$this->createMock(LicenseService::class),
			$this->createMock(IUserManager::class),
		);
		$this->expectException(\InvalidArgumentException::class);
		$svc->seed('bad uid!', 'Play Reviewer', 'x');
	}

	public function testSeedsGoldenKeyAndAssignsSeatForExistingUser(): void
	{
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE=1');
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);

		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return ($uid === 'admin' || $uid === 'play-reviewer') ? $user : null;
			}
		);
		$users->expects($this->never())->method('createUser');

		$license = $this->createMock(LicenseService::class);
		$license->expects($this->once())
			->method('apply')
			->with('admin', $this->callback(
				static fn (string $wire): bool => str_starts_with($wire, 'CUC2.')
			))
			->willReturn([
				'state' => ['customerId' => 'golden-acme', 'mobileSeats' => 7],
			]);
		$license->expects($this->once())
			->method('assignSeat')
			->with('admin', 'play-reviewer')
			->willReturn(['created' => false, 'seat' => ['uid' => 'play-reviewer']]);

		$svc = new PlayReviewerSeedService($license, $users);
		$result = $svc->seed('play-reviewer', 'Play Reviewer', null);
		$this->assertSame('play-reviewer', $result['uid']);
		$this->assertSame('golden-acme', $result['customerId']);
		$this->assertSame(7, $result['mobileSeats']);
		$this->assertFalse($result['createdUser']);
		$this->assertNull($result['generatedPassword']);
	}

	public function testCreatesUserWhenMissing(): void
	{
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE=1');
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);

		$created = $this->createMock(IUser::class);
		$created->expects($this->once())->method('setDisplayName')->with('Play Reviewer');
		$admin = $this->createMock(IUser::class);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static function (string $uid) use ($admin): ?IUser {
				return $uid === 'admin' ? $admin : null;
			}
		);
		$users->expects($this->once())
			->method('createUser')
			->with('play-reviewer', 'secret-pass')
			->willReturn($created);

		$license = $this->createMock(LicenseService::class);
		$license->method('apply')->willReturn([
			'state' => ['customerId' => 'golden-acme', 'mobileSeats' => 7],
		]);
		$license->method('assignSeat')->willReturn([
			'created' => true,
			'seat' => ['uid' => 'play-reviewer'],
		]);

		$svc = new PlayReviewerSeedService($license, $users);
		$result = $svc->seed('play-reviewer', 'Play Reviewer', 'secret-pass');
		$this->assertTrue($result['createdUser']);
		$this->assertNull($result['generatedPassword']);
	}
}
