<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\SharedMailboxSmtpClient;
use PHPUnit\Framework\TestCase;

final class SharedMailboxSmtpClientTest extends TestCase
{
	public function testRejectsIncompleteConfig(): void
	{
		try {
			SharedMailboxSmtpClient::assertConfig([
				'host' => '',
				'port' => 587,
				'enc' => 'tls',
				'username' => 'u',
				'password' => 'p',
				'from' => 'a@b.c',
				'fromName' => 'A',
				'to' => 'x@y.z',
				'subject' => 'Hi',
				'body' => 'Body',
			]);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('incomplete', $e->getMessage());
			$this->assertArrayHasKey('smtp', $e->getFields());
		}
	}

	public function testRejectsMissingPasswordWithoutNetwork(): void
	{
		try {
			SharedMailboxSmtpClient::assertConfig([
				'host' => 'smtp.example.test',
				'port' => 587,
				'enc' => 'tls',
				'username' => 'u',
				'password' => '',
				'from' => 'a@b.c',
				'fromName' => 'A',
				'to' => 'x@y.z',
				'subject' => 'Hi',
				'body' => 'Body',
			]);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('incomplete', $e->getMessage());
		}
	}

	public function testRejectsMissingUsernameWithoutNetwork(): void
	{
		try {
			SharedMailboxSmtpClient::assertConfig([
				'host' => 'smtp.example.test',
				'username' => '',
				'password' => 'secret',
				'from' => 'a@b.c',
				'to' => 'x@y.z',
			]);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('incomplete', $e->getMessage());
		}
	}

	public function testSendDelegatesToAssertConfig(): void
	{
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('incomplete');
		SharedMailboxSmtpClient::send([
			'host' => 'smtp.example.test',
			'username' => 'u',
			'password' => '',
			'from' => 'a@b.c',
			'to' => 'x@y.z',
			'subject' => 'Hi',
			'body' => 'Body',
		]);
	}

	public function testBuildDataPayloadPlainText(): void
	{
		$payload = SharedMailboxSmtpClient::buildDataPayload([
			'from' => 'a@b.c',
			'fromName' => 'Ada',
			'to' => 'x@y.z',
			'subject' => 'Hi',
			'body' => "Line1\n.hidden",
		]);
		$this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $payload);
		$this->assertStringNotContainsString('multipart/mixed', $payload);
		$this->assertStringContainsString("\n..hidden", $payload);
		$this->assertStringEndsWith("\r\n.", $payload);
	}

	public function testBuildDataPayloadMultipartWithAttachment(): void
	{
		$payload = SharedMailboxSmtpClient::buildDataPayload([
			'from' => 'a@b.c',
			'fromName' => 'Ada',
			'to' => 'x@y.z',
			'subject' => 'Quote',
			'body' => 'See attached',
			'attachments' => [[
				'filename' => 'quote.pdf',
				'mime' => 'application/pdf',
				'data' => '%PDF-1.4',
			]],
		]);
		$this->assertStringContainsString('Content-Type: multipart/mixed; boundary="crm_', $payload);
		$this->assertStringContainsString('Content-Disposition: attachment; filename="quote.pdf"', $payload);
		$this->assertStringContainsString(base64_encode('%PDF-1.4'), $payload);
		$this->assertStringContainsString('Content-Transfer-Encoding: base64', $payload);
	}
}
