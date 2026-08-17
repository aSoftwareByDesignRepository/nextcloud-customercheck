<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: inbox list never widens ACL via bad tab / missing cursor gates.
 */
final class MailInboxPagingContractTest extends TestCase
{
	public function testListInboxNormalizesTabAndUsesMultiPass(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PersonalMailService.php');
		$this->assertStringContainsString('MailInboxPageLogic::normalizeTab($tab)', $src);
		$this->assertStringContainsString('MailInboxPageLogic::MAX_FETCH_PASSES', $src);
		$this->assertStringContainsString('MailInboxPageLogic::decodeCursor($passCursor)', $src);
		$this->assertStringContainsString('normalizeSentAtForCursor', $src);
		// Must not leave an open else-branch that returns unscoped workspace mail.
		$this->assertStringNotContainsString("\$tab = strtolower(trim(\$tab));", $src);
	}

	public function testControllerPassesCursorOnlyAsString(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString("\$cursorRaw = \$this->request->getParam('cursor', null);", $src);
		$this->assertStringContainsString('is_string($cursorRaw) ? $cursorRaw : null', $src);
		$this->assertStringContainsString('listInbox($workspaceId, $uid, $limit, $tab, $mailboxId, $canTriage, $cursor)', $src);
	}

	public function testSalesInboxLoadsPageCoreAsset(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/inbox-page-core'", $src);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('CustomerCheckInboxPage', $js);
		$this->assertStringContainsString('applyPagingMeta', $js);
		$this->assertStringContainsString('itemsRaw = [];', $js);
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/sales-inbox.php');
		$this->assertStringContainsString('crm-sales-inbox-more-hint', $html);
		$this->assertStringContainsString('aria-describedby', $html);
	}
}
