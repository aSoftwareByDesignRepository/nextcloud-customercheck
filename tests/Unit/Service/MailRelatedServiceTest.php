<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\EmailTouch;
use OCA\CustomerCheck\Db\Person;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\MailRelatedService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class MailRelatedServiceTest extends TestCase
{
	/**
	 * @param list<Person> $people
	 * @param list<EmailTouch> $touches
	 * @param list<array<string,mixed>> $related
	 */
	private function service(
		bool $mailAvailable,
		bool $relatedEnabled,
		array $people,
		array $touches,
		array $related = [],
	): MailRelatedService {
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('mailAvailable')->willReturn($mailAvailable);
		$facades->method('searchMailMessages')->willReturn($related);

		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findByCompany')->willReturn($people);

		$emails = $this->createMock(EmailTouchService::class);
		$emails->method('listForCompany')->willReturn($touches);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($relatedEnabled): string {
				if ($app === Application::APP_ID && $key === Constants::CONFIG_MAIL_RELATED_ENABLED) {
					return $relatedEnabled ? '1' : '0';
				}
				return $default;
			}
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/apps/mail/');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $params = []): string {
			foreach ($params as $i => $p) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$p, $text);
				$text = str_replace('%s', (string)$p, $text);
			}
			return $text;
		});

		return new MailRelatedService($facades, $persons, $emails, $config, $url, $l10n);
	}

	private function person(string $name, string $email): Person
	{
		$p = new Person();
		$p->setName($name);
		$p->setEmail($email);
		return $p;
	}

	private function touch(string $direction, string $from, string $to, string $subject, string $source = 'manual'): EmailTouch
	{
		$t = new EmailTouch();
		$t->setId(7);
		$t->setDirection($direction);
		$t->setFromAddr($from);
		$t->setToAddr($to);
		$t->setSubject($subject);
		$t->setSource($source);
		$t->setOccurredAt(new \DateTime('2026-07-20 12:00:00'));
		$t->setSnippet('Agreed to renew');
		return $t;
	}

	public function testManualOnlyWhenMailUnavailable(): void
	{
		$pack = $this->service(false, true, [], [])->forCompany(1);
		$this->assertSame('manual_only', $pack['mode']);
		$this->assertSame('Email log', $pack['title']);
		$this->assertSame([], $pack['deepLinks']);
		$this->assertCount(3, $pack['steps']);
		$this->assertSame(0, $pack['summary']['touchCount']);
	}

	public function testDeepLinkModeWithPersonEmailsAndTouches(): void
	{
		$pack = $this->service(
			true,
			true,
			[$this->person('Ada', 'ada@acme.example')],
			[$this->touch('outbound', 'me@ex.com', 'ada@acme.example', 'Quote')],
		)->forCompany(1);

		$this->assertSame('deep_link', $pack['mode']);
		$this->assertSame('Who wrote what', $pack['title']);
		$this->assertCount(1, $pack['deepLinks']);
		$this->assertStringContainsString('ada@acme.example', $pack['deepLinks'][0]['label']);
		$this->assertStringContainsString('Ada', $pack['deepLinks'][0]['label']);
		$this->assertSame('Ada', $pack['touches'][0]['withLabel']);
		$this->assertSame('ada@acme.example', $pack['touches'][0]['withWhom']);
		$this->assertSame('You wrote', $pack['touches'][0]['directionLabel']);
		$this->assertSame('Logged', $pack['touches'][0]['sourceLabel']);
		$this->assertSame('2026-07-20', $pack['touches'][0]['whenLabel']);
		$this->assertSame('Ada', $pack['summary']['lastContactLabel']);
		$this->assertSame(1, $pack['summary']['contactCount']);
	}

	public function testListModeRendersRelatedRows(): void
	{
		$related = [[
			'direction' => 'inbound',
			'fromAddr' => 'ada@acme.example',
			'toAddr' => 'me@ex.com',
			'subject' => 'Re: Quote',
			'occurredAt' => '2026-07-21T10:00:00+00:00',
			'deepLink' => 'https://example.test/mail/1',
		]];
		$pack = $this->service(
			true,
			true,
			[$this->person('Ada', 'ada@acme.example')],
			[],
			$related,
		)->forCompany(1);

		$this->assertSame('list', $pack['mode']);
		$this->assertCount(1, $pack['related']);
		$this->assertSame('Ada', $pack['related'][0]['withLabel']);
		$this->assertSame('They wrote', $pack['related'][0]['directionLabel']);
		$this->assertSame([], $pack['deepLinks']);
	}

	public function testNoPersonEmailsStaysManualOnlyWithMailOn(): void
	{
		$pack = $this->service(true, true, [$this->person('No Mail', '')], [])->forCompany(1);
		$this->assertSame('manual_only', $pack['mode']);
		$this->assertStringContainsString('contact email', strtolower($pack['note']));
	}
}
