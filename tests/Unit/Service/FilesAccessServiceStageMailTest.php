<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class FilesAccessServiceStageMailTest extends TestCase
{
	public function testStageRejectsDisallowedMime(): void
	{
		$svc = new FilesAccessService(
			$this->createMock(IRootFolder::class),
			$this->l10n(),
		);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessageMatches('/PDF|PNG|JPEG|plain/i');
		$svc->stageOutboundMailAttachment('alex', 'evil.exe', 'application/x-msdownload', 'MZ');
	}

	public function testStageRejectsOversize(): void
	{
		$svc = new FilesAccessService(
			$this->createMock(IRootFolder::class),
			$this->l10n(),
		);
		$big = str_repeat('a', Constants::MAIL_ATTACH_MAX_BYTES + 1);
		try {
			$svc->stageOutboundMailAttachment('alex', 'big.pdf', 'application/pdf', $big);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_ATTACHMENT_TOO_LARGE, $e->getErrorCode());
		}
	}

	public function testStageWritesIntoUserOutboxFolder(): void
	{
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4242);
		$file->method('getName')->willReturn('offer.pdf');

		$outbox = $this->createMock(Folder::class);
		$outbox->method('nodeExists')->willReturn(false);
		$outbox->expects($this->once())
			->method('newFile')
			->with('offer.pdf', '%PDF')
			->willReturn($file);

		$crm = $this->createMock(Folder::class);
		$crm->method('nodeExists')->with('Mail Outbound')->willReturn(true);
		$crm->method('get')->with('Mail Outbound')->willReturn($outbox);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('CustomerCheck')->willReturn(true);
		$userFolder->method('get')->with('CustomerCheck')->willReturn($crm);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alex')->willReturn($userFolder);

		$svc = new FilesAccessService($root, $this->l10n());
		$out = $svc->stageOutboundMailAttachment('alex', 'offer.pdf', 'application/pdf', '%PDF');
		$this->assertSame(4242, $out['fileId']);
		$this->assertSame('offer.pdf', $out['name']);
		$this->assertSame('application/pdf', $out['mime']);
		$this->assertSame(4, $out['size']);
	}

	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $s, array $a = []): string {
			foreach ($a as $i => $v) {
				$s = str_replace('%s', (string)$v, $s);
				$s = str_replace('%' . ($i + 1) . '$s', (string)$v, $s);
			}

			return $s;
		});

		return $l;
	}
}
