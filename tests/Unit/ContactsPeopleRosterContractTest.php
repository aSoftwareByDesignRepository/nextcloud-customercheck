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
		$this->assertStringContainsString('data-crm-person-last-touch', $html);
		$this->assertStringContainsString("\$l->t('Open in Contacts')", $html);
		$this->assertStringContainsString('data-crm-unlink-contacts', $html);
		$this->assertStringContainsString('data-crm-remove-person', $html);
		$this->assertStringContainsString("\$l->t('Remove')", $html);
		$this->assertStringContainsString('contactsUid', $html);
		$this->assertStringContainsString('data-crm-buying-role', $html);
		$this->assertStringContainsString("\$l->t('Last touch')", $html);
		$this->assertStringContainsString("\$l->t('Address book')", $html);
		$this->assertStringContainsString("\$l->t('Role in buying')", $html);
		$this->assertStringContainsString("\$l->t('Log talk')", $html);
		$this->assertStringContainsString('data-crm-edit-person', $html);
		$this->assertStringContainsString('crm-person-more" open', $html);
		// Granny: never bury person controls in a More *menu* scroll-trap (disclosure for extras is OK).
		$personsStart = strpos($html, 'id="crm-persons-table"');
		$personsEnd = strpos($html, 'id="crm-deals"', (int)$personsStart);
		$this->assertNotFalse($personsStart);
		$this->assertNotFalse($personsEnd);
		$personsChunk = substr($html, (int)$personsStart, (int)$personsEnd - (int)$personsStart);
		$this->assertStringNotContainsString('crm-more-menu', $personsChunk);
		$this->assertStringContainsString('crm-person-more', $personsChunk);
		$this->assertStringNotContainsString("\$l->t('More'));", $personsChunk);
	}

	public function testAddPersonJsPersistsContactsUid(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('contactsUid', $js);
		$this->assertStringContainsString('payload.contactsUid', $js);
		$this->assertStringContainsString('data-crm-unlink-contacts', $js);
		$this->assertStringContainsString('data-crm-remove-person', $js);
		$this->assertStringContainsString("Api.del('/persons/'", $js);
		$this->assertStringContainsString('returnMailToAssign', $js);
		$this->assertStringContainsString('optionalCheckbox', $js);
		$this->assertStringContainsString("t('Remove this person?')", $js);
		$this->assertStringContainsString("'/persons/'", $js);
	}

	public function testLogEmailBoundOnceOnTalkedSection(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('data-crm-log-email', $js);
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-log-email"', $detail);
		$this->assertStringContainsString('data-crm-log-email', $detail);
		$emails = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringNotContainsString('id="crm-log-email"', $emails);
		$this->assertStringContainsString('Communication above', $emails);
	}

	public function testPageControllerHydratesPeopleRoster(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('peopleRoster', $src);
		$this->assertStringContainsString('contactsAvailable', $src);
		$this->assertStringContainsString('contactsHomeUrl', $src);
	}
}
