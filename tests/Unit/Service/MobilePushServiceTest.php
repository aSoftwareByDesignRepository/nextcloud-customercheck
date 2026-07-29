<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\PushTokenMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MobilePushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MobilePushServiceTest extends TestCase
{
	private PushTokenMapper&MockObject $tokens;
	private IClientService&MockObject $clients;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void
	{
		$this->tokens = $this->createMock(PushTokenMapper::class);
		$this->clients = $this->createMock(IClientService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_700_000_000);
	}

	private function service(): MobilePushService
	{
		return new MobilePushService(
			$this->tokens,
			$this->clients,
			$this->createMock(IUserManager::class),
			$this->createMock(IFactory::class),
			$this->time,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testIsPushSubjectOnlyReminderDigestAndActivityDue(): void
	{
		self::assertTrue(MobilePushService::isPushSubject(Constants::NOTIFY_REMINDER));
		self::assertTrue(MobilePushService::isPushSubject(Constants::NOTIFY_DIGEST));
		self::assertTrue(MobilePushService::isPushSubject(Constants::NOTIFY_ACTIVITY_DUE));
		self::assertFalse(MobilePushService::isPushSubject(Constants::NOTIFY_LEAD));
		self::assertFalse(MobilePushService::isPushSubject(Constants::NOTIFY_QUOTA80));
	}

	public function testRegisterRejectsBadPlatform(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->register('alice', 'ExponentPushToken[x]', 'web');
	}

	public function testRegisterUpsertsHash(): void
	{
		$token = 'ExponentPushToken[abc]';
		$this->tokens->expects($this->once())
			->method('upsert')
			->with(
				'alice',
				hash('sha256', $token),
				$token,
				'android',
				1_700_000_000,
			);
		$this->service()->register('alice', $token, 'Android');
	}

	public function testUnregisterWithoutTokenClearsUser(): void
	{
		$this->tokens->expects($this->once())->method('deleteAllForUser')->with('alice');
		$this->service()->unregister('alice', null);
	}

	public function testDispatchNoopsForNonPushSubject(): void
	{
		$this->tokens->expects($this->never())->method('findTokensForUsers');
		$this->service()->dispatch(['alice'], Constants::NOTIFY_LEAD, []);
	}

	public function testDispatchPostsExpoBatch(): void
	{
		$this->tokens->method('findTokensForUsers')->willReturn([
			['uid' => 'alice', 'token' => 'ExponentPushToken[a]', 'platform' => 'android'],
		]);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with(
			MobilePushService::EXPO_PUSH_ENDPOINT,
			$this->callback(static function (array $opts): bool {
				return isset($opts['json'][0]['to']) && $opts['json'][0]['to'] === 'ExponentPushToken[a]';
			}),
		);
		$this->clients->method('newClient')->willReturn($client);

		$user = $this->createMock(\OCP\IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alice')->willReturn($user);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $args = []): string => $text,
		);
		$l10nFactory->method('get')->willReturn($l10n);
		$l10nFactory->method('getUserLanguage')->willReturn('en');

		$svc = new MobilePushService(
			$this->tokens,
			$this->clients,
			$userManager,
			$l10nFactory,
			$this->time,
			$this->createMock(LoggerInterface::class),
		);
		$svc->dispatch(['alice'], Constants::NOTIFY_REMINDER, ['companyId' => 5, 'companyName' => 'Acme']);
	}

	public function testPushDataCarriesCompanyId(): void
	{
		$data = MobilePushService::pushData(Constants::NOTIFY_REMINDER, ['companyId' => 12]);
		self::assertSame(Constants::NOTIFY_REMINDER, $data['type']);
		self::assertSame(12, $data['companyId']);
	}
}
