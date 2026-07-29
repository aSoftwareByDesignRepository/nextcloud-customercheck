<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\MailTemplateService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\QuotaService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class MailTemplateServiceTest extends TestCase
{
	private function service(?string $primaryEmail = 'ada@acme.example'): MailTemplateService
	{
		$company = new Company();
		$company->setId(1);
		$company->setName('Acme GmbH');
		$company->setSlug('acme-gmbh');
		$company->setLang('de');

		$agreement = new Agreement();
		$agreement->setId(9);
		$agreement->setCompanyId(1);
		$agreement->setKind('license');
		$agreement->setLabel('AZC 25');
		$agreement->setAmountCents(120000);
		$agreement->setValidUntil(new \DateTime('2026-12-31'));
		$agreement->setLimitsJson('{"mobileSeats":25,"terminalDevices":2,"bundle":true}');
		$agreement->setKeyFingerprint('AZC2.TEST');

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$agreements = $this->createMock(AgreementService::class);
		$agreements->method('get')->willReturn($agreement);
		$persons = $this->createMock(PersonService::class);
		$primary = null;
		if ($primaryEmail !== null) {
			$primary = new Person();
			$primary->setId(3);
			$primary->setCompanyId(1);
			$primary->setName('Ada');
			$primary->setEmail($primaryEmail);
		}
		$persons->method('findPrimary')->willReturn($primary);
		$quota = $this->createMock(QuotaService::class);
		$emails = $this->createMock(EmailTouchService::class);
		$emails->expects($this->once())->method('logCompose');
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('mailComposeUrl')->willReturn('https://example.test/mail');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		return new MailTemplateService($companies, $agreements, $persons, $quota, $emails, $facades, $l10n);
	}

	public function testRenewal60RendersGermanSubject(): void
	{
		$pack = $this->service()->build('renewal_60', 1, 9, 'admin');
		$this->assertStringContainsString('Verlängerung', $pack['subject']);
		$this->assertSame('ada@acme.example', $pack['to']);
		$this->assertNotSame('', $pack['mailtoUrl']);
	}

	public function testMissingRecipientRejected(): void
	{
		$company = new Company();
		$company->setId(1);
		$company->setName('Acme GmbH');
		$company->setSlug('acme-gmbh');
		$company->setLang('de');
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$agreements = $this->createMock(AgreementService::class);
		$persons = $this->createMock(PersonService::class);
		$persons->method('findPrimary')->willReturn(null);
		$quota = $this->createMock(QuotaService::class);
		$emails = $this->createMock(EmailTouchService::class);
		$emails->expects($this->never())->method('logCompose');
		$facades = $this->createMock(FacadeProbeService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$service = new MailTemplateService($companies, $agreements, $persons, $quota, $emails, $facades, $l10n);

		$this->expectException(ValidationException::class);
		try {
			$service->build('payment_reminder', 1, null, 'admin');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_EMAIL_INVALID, $e->getErrorCode());
			throw $e;
		}
	}
}