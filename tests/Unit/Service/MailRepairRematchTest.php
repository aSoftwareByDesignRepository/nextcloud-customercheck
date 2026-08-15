<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailForwardLogic;
use OCA\CustomerCheck\Service\MailMatchLogic;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\ReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Reopened team forwards must auto-file when preview exists but forward_from_hints were never stored.
 */
final class MailRepairRematchTest extends TestCase
{
	/** @var list<string> */
	private array $teamExclude = [
		'alex@software-by-design.de',
		'support@software-by-design.de',
	];

	public function testThunderbirdPreviewBackfillYieldsForwardFromMatch(): void
	{
		$preview = <<<'TEXT'
-------- Forwarded Message --------
From: Daniel Gattinger - Banss
To: Alexander Mäule

Mit freundlichen Grüßen
Daniel Gattinger
gattinger@banss.at

*Von:*Alexander Mäule
*Gesendet:* Dienstag, 11. August 2026 20:46
TEXT;
		$hints = MailForwardLogic::hintsFromBodyPrefix($preview, $this->teamExclude);
		$this->assertSame(['gattinger@banss.at'], $hints);

		$persons = [
			['companyId' => 18, 'email' => 'alex@software-by-design.de'],
			['companyId' => 26, 'email' => 'gattinger@banss.at'],
		];
		$r = MailMatchLogic::match(
			null,
			'alex@software-by-design.de',
			$persons,
			$this->teamExclude,
			['support@software-by-design.de'],
			$hints,
		);
		$this->assertSame('forward_from', $r['how']);
		$this->assertSame(26, $r['companyId']);
	}

	public function testContractPostMailboxSyncBackfillsHintsBeforeRematch(): void
	{
		$review = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('backfillForwardHintsFromPreview', $review);
		$this->assertStringContainsString('reconcileMisfiledTeamForwards', $review);
		$pos = strpos($review, 'function postMailboxSync');
		$this->assertNotFalse($pos);
		$chunk = substr($review, $pos, 900);
		$backfillPos = strpos($chunk, 'backfillForwardHintsFromPreview');
		$rematchPos = strpos($chunk, 'reconcileKnownSharedMail');
		$this->assertNotFalse($backfillPos);
		$this->assertNotFalse($rematchPos);
		$this->assertLessThan($rematchPos, $backfillPos, 'hint backfill must run before rematch');
		$this->assertStringContainsString('patchOpenMailForRematch', $review);
	}

	public function testContractListOpenMailForRepairIncludesEmptyHintsWithPreview(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('function backfillForwardHintsFromPreview', $src);
		$this->assertStringContainsString('function listOpenMailForRepair', $src);
		$pos = strpos($src, 'function listOpenMailForRepair');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 700);
		$this->assertStringContainsString('$emptyHints', $chunk);
		$this->assertStringNotContainsString('$emptyHints, $emptyPreview', $chunk);
	}

	public function testContractRepairDoesNotRequireEmptyPreviewForBodyProbe(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('function repairOpenMailMatchData', $src);
		$this->assertStringNotContainsString('$hintsEmpty && $previewEmpty && MailForwardLogic::needsBodyProbe', $src);
	}
}
