<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\PersonalMailService;
use PHPUnit\Framework\TestCase;

/**
 * Contract + ACL helpers for personal mail (CUC-PERSONAL-MAIL-1).
 */
final class PersonalMailServiceContractTest extends TestCase
{
	public function testDefaultFolderSpecsArePrivate(): void
	{
		$ref = new \ReflectionClass(PersonalMailService::class);
		$m = $ref->getMethod('defaultFolderSpecs');
		$m->setAccessible(true);
		/** @var PersonalMailService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		$specs = $m->invoke($svc, ['INBOX', 'Sent', 'Archive']);
		$this->assertNotEmpty($specs);
		foreach ($specs as $spec) {
			$this->assertFalse($spec['shareWithWorkspace'], 'default must be private');
			$this->assertArrayHasKey('syncEnabled', $spec);
		}
		$inbox = array_values(array_filter($specs, static fn (array $s): bool => strtoupper($s['path']) === 'INBOX'));
		$this->assertCount(1, $inbox);
		$this->assertTrue($inbox[0]['syncEnabled']);
	}

	public function testVisibilityForPersonalShareMatrix(): void
	{
		$this->assertSame(MailAclLogic::VISIBILITY_PRIVATE, MailAclLogic::visibilityForIngest('personal', false));
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, MailAclLogic::visibilityForIngest('personal', true));
		$this->assertSame(MailAclLogic::VISIBILITY_SHARED, MailAclLogic::visibilityForIngest('team', false));
	}

	public function testErrorConstantsStable(): void
	{
		$this->assertSame('PERSONAL_MAIL_DISABLED', PersonalMailService::ERR_PERSONAL_MAIL_DISABLED);
		$this->assertSame('MAIL_SHARE_ACK_REQUIRED', PersonalMailService::ERR_MAIL_SHARE_ACK);
		$this->assertSame('MAIL_ACCOUNT_CONFLICT', PersonalMailService::ERR_MAIL_ACCOUNT_CONFLICT);
	}

	public function testMigrationAddsSignatureColumns(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Migration/Version1013Date20260815120000.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('signature_html', $src);
		$this->assertStringContainsString('signature_plain', $src);
	}

	public function testRoutesExposePersonalMailAndMobileInbox(): void
	{
		$routes = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertNotFalse($routes);
		$this->assertStringContainsString('personal-mailboxes', $routes);
		$this->assertStringContainsString('mail/inbox', $routes);
		$this->assertStringContainsString('mail/send-accounts', $routes);
		$this->assertStringContainsString('mobile_mail#inbox', $routes);
		$this->assertStringContainsString('salesInbox', $routes);
	}
}
