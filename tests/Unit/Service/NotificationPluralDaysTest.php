<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\PushTokenMapper;
use OCA\CustomerCheck\Notification\Notifier;
use OCA\CustomerCheck\Service\MobilePushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression pin for l10n_plural_hardcode: count-bearing notification copy
 * must go through IL10N::n() so "1 day" never renders as "1 days".
 */
final class NotificationPluralDaysTest extends TestCase
{
	private function englishL10n(): IL10N
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $s, array $a = []): string => $a === [] ? $s : vsprintf($s, $a),
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $sing, string $plur, int $count, array $a = []): string => vsprintf(
				$count === 1 ? $sing : $plur,
				$a,
			),
		);
		return $l10n;
	}

	public static function notifierCases(): array
	{
		return [
			'activity due 1 day' => [Constants::NOTIFY_ACTIVITY_DUE, ['title' => 'Call', 'daysLate' => 1], '(1 day)'],
			'activity due 5 days' => [Constants::NOTIFY_ACTIVITY_DUE, ['title' => 'Call', 'daysLate' => 5], '(5 days)'],
			'deal rotting 1 day' => [Constants::NOTIFY_DEAL_ROTTING, ['title' => 'Offer', 'stageIdleDays' => 1], '(1 day in stage)'],
			'deal rotting 5 days' => [Constants::NOTIFY_DEAL_ROTTING, ['title' => 'Offer', 'stageIdleDays' => 5], '(5 days in stage)'],
		];
	}

	/**
	 * @dataProvider notifierCases
	 */
	public function testNotifierSubjectPluralizesDayCount(string $subject, array $params, string $expected): void
	{
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($this->englishL10n());
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/x');

		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn(Application::APP_ID);
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($params + ['companyId' => 5, 'companyName' => 'Acme']);
		$captured = null;
		$n->expects($this->once())->method('setParsedSubject')
			->willReturnCallback(static function (string $s) use (&$captured, $n) {
				$captured = $s;
				return $n;
			});
		$n->method('setLink')->willReturnSelf();

		$notifier = new Notifier($factory, $url);
		$notifier->prepare($n, 'en');

		self::assertIsString($captured);
		self::assertStringContainsString($expected, $captured);
		self::assertStringNotContainsString('1 days', $captured);
	}

	public static function pushCases(): array
	{
		return [
			'activity due 1 day' => [Constants::NOTIFY_ACTIVITY_DUE, ['daysLate' => 1], '(1 day)'],
			'activity due 5 days' => [Constants::NOTIFY_ACTIVITY_DUE, ['daysLate' => 5], '(5 days)'],
			'deal rotting 1 day' => [Constants::NOTIFY_DEAL_ROTTING, ['stageIdleDays' => 1], '— 1 day in stage.'],
			'deal rotting 5 days' => [Constants::NOTIFY_DEAL_ROTTING, ['stageIdleDays' => 5], '— 5 days in stage.'],
		];
	}

	/**
	 * @dataProvider pushCases
	 */
	public function testPushBodyPluralizesDayCount(string $subject, array $params, string $expected): void
	{
		$tokens = $this->createMock(PushTokenMapper::class);
		$tokens->method('findTokensForUsers')->willReturn([
			['uid' => 'alice', 'token' => 'ExponentPushToken[a]', 'platform' => 'android'],
		]);

		$captured = null;
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with(
			MobilePushService::EXPO_PUSH_ENDPOINT,
			$this->callback(static function (array $opts) use (&$captured): bool {
				$captured = (string)($opts['json'][0]['body'] ?? '');
				return true;
			}),
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($this->englishL10n());
		$factory->method('getUserLanguage')->willReturn('en');

		$svc = new MobilePushService(
			$tokens,
			$clients,
			$userManager,
			$factory,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
		);
		$svc->dispatch(['alice'], $subject, $params + ['companyName' => 'Acme', 'title' => 'Call']);

		self::assertIsString($captured);
		self::assertStringContainsString($expected, $captured);
		self::assertStringNotContainsString('1 days', $captured);
	}
}
