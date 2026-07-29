<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AC-CC-4 / AC-CC-21…23 — People roster + Contacts link surfaces.
 */
final class ContactsPeopleRosterContractTest extends TestCase
{
	public function testPeopleTableHasLastTouchLinkedAndActions(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-persons-table"', $html);
		$this->assertStringContainsString("\$l->t('Last touch')", $html);
		$this->assertStringContainsString("\$l->t('Linked')", $html);
		$this->assertStringContainsString("\$l->t('Open in Contacts')", $html);
		$this->assertStringContainsString('data-crm-unlink-contacts', $html);
		$this->assertStringContainsString('contactsUid', $html);
	}

	public function testAddPersonJsPersistsContactsUid(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('contactsUid', $js);
		$this->assertStringContainsString('payload.contactsUid', $js);
		$this->assertStringContainsString('data-crm-unlink-contacts', $js);
		$this->assertStringContainsString("'/persons/'", $js);
	}

	public function testLogEmailBoundOnCockpitAndEmailsSection(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('data-crm-log-email', $js);
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('data-crm-log-email', $detail);
		$emails = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('data-crm-log-email', $emails);
	}

	public function testPageControllerHydratesPeopleRoster(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('peopleRoster', $src);
		$this->assertStringContainsString('contactsAvailable', $src);
		$this->assertStringContainsString('contactsHomeUrl', $src);
	}
}
