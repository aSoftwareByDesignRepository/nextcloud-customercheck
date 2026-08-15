<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ChaseLogic;
use OCA\CustomerCheck\Service\MailSendService;
use PHPUnit\Framework\TestCase;

/**
 * stuckChaseDays + MailSend behavioural contracts.
 */
final class StuckChaseAndMailSendContractTest extends TestCase
{
	public function testQuoteFollowupRespectsStuckChaseDays(): void
	{
		$today = new \DateTimeImmutable('2026-07-28');
		$base = [
			'stage' => 'quoted',
			'ownerUserId' => 'ae',
			'healthy' => true,
			'quoteSentOn' => new \DateTimeImmutable('2026-07-24'),
		];
		// Age 4 days: due at threshold 3, not yet at 5.
		$this->assertContains(
			'quote_followup_due',
			ChaseLogic::reasons($base + ['stuckChaseDays' => 3], $today),
		);
		$this->assertNotContains(
			'quote_followup_due',
			ChaseLogic::reasons($base + ['stuckChaseDays' => 5], $today),
		);
	}

	public function testMailSendEnforcesCapabilityAndIdempotencyLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('ERR_MAIL_SEND_DENIED', $src);
		$this->assertStringContainsString('idempotencyKey', $src);
		$this->assertStringContainsString('crm_mail_send/', $src);
		$this->assertStringContainsString('disclosure', $src);
		$this->assertStringContainsString('assertSendRateLimits', $src);
		$this->assertTrue(class_exists(MailSendService::class));
	}

	public function testDeliveryPackOffersSharedMailboxSend(): void
	{
		$common = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/shared-mail-send.js');
		$this->assertStringContainsString('sendViaSharedMailbox', $common);
		$this->assertStringContainsString('/mail/send', $common);
		$this->assertStringContainsString('idempotencyKey', $common);
		$this->assertStringContainsString('makeIdempotencyKey', $common);
		$this->assertStringContainsString('inflightKeys', $common);
		$this->assertStringContainsString('offerPreparedMail', $common);
		// Must not mint a fresh key on every post inside the confirmed path alone —
		// dialog path reuses one key; retry path keeps the fingerprint map entry.
		$this->assertStringContainsString('dialogKey', $common);

		$assets = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/FrontEndAssetService.php');
		$this->assertStringContainsString("common/shared-mail-send", $assets);

		foreach (['company-detail.js', 'agreements.js', 'money.js', 'persona.js'] as $page) {
			$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/' . $page);
			$this->assertStringContainsString('CustomerCheckSharedMail', $js, $page);
		}
		$this->assertStringContainsString('replyMode', $common);
		$this->assertStringContainsString('Send from connected account', $common);
		$this->assertStringContainsString('queryInDialog', $common);
		$this->assertStringContainsString('elevatingPrivate', $common);
	}

	public function testClaimEndpointWired(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertStringContainsString("deal_api#claim", $routes);
		$today = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/today.js');
		$this->assertStringContainsString("/deals/' + id + '/claim'", $today);
		$this->assertStringNotContainsString('ownerUserId: uid', $today);
	}
}
