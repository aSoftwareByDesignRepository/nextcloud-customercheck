<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MailMatchLogic;
use OCA\CustomerCheck\Support\SharedMailboxSmtpClient;
use PHPUnit\Framework\TestCase;

/**
 * Mail matching + SMTP client contract (Mailpit-ready shape) — DoD §21.2.
 *
 * Full IMAP fixture sync needs live credentials; matching + send validation
 * are the security-critical pure edges covered here without root-owned writes.
 */
final class SharedMailFixtureIntegrationTest extends TestCase
{
	public function testFreemailNeverAutoDomainMatches(): void
	{
		$persons = [
			['companyId' => 1, 'email' => 'ops@acme.example'],
			['companyId' => 2, 'email' => 'ada@gmail.com'],
		];
		$r = MailMatchLogic::match(null, 'unknown@gmail.com', $persons);
		$this->assertSame('unmatched', $r['how']);
		$this->assertNull($r['companyId']);
	}

	public function testExactPersonEmailMatches(): void
	{
		$persons = [['companyId' => 42, 'email' => 'ada@acme.example']];
		$r = MailMatchLogic::match(null, 'Ada@Acme.Example', $persons);
		$this->assertSame('person_email', $r['how']);
		$this->assertSame(42, $r['companyId']);
	}

	public function testUniqueCorporateDomainMatches(): void
	{
		$persons = [['companyId' => 7, 'email' => 'ops@partner.example']];
		$r = MailMatchLogic::match(null, 'ceo@partner.example', $persons);
		$this->assertSame('domain', $r['how']);
		$this->assertSame(7, $r['companyId']);
	}

	public function testSmtpClientRejectsEmptyPassword(): void
	{
		$this->expectException(ValidationException::class);
		SharedMailboxSmtpClient::assertConfig([
			'host' => 'mailhog',
			'port' => 1025,
			'username' => 'shared',
			'password' => '',
			'from' => 'shared@example.test',
			'to' => 'ada@example.test',
			'subject' => 'Hi',
			'body' => 'Hello',
		]);
	}

	public function testAuthFailTimesThreeErrorsMailbox(): void
	{
		$this->assertSame('ok', MailMatchLogic::nextAuthStatus(0));
		$this->assertSame('ok', MailMatchLogic::nextAuthStatus(2));
		$this->assertSame('error', MailMatchLogic::nextAuthStatus(3));
	}
}
