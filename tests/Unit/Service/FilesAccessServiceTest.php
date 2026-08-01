<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Actor-scoped Files resolution — confused-deputy / size / mime guards.
 */
final class FilesAccessServiceTest extends TestCase
{
	private function service(IRootFolder $root): FilesAccessService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $s, array $a = []): string {
			foreach ($a as $v) {
				$pos = strpos($s, '%s');
				if ($pos === false) {
					break;
				}
				$s = substr_replace($s, (string)$v, $pos, 2);
			}

			return $s;
		});

		return new FilesAccessService($root, $l10n);
	}

	public function testRejectsEmptyActorOrZeroId(): void
	{
		$svc = $this->service($this->createMock(IRootFolder::class));
		try {
			$svc->resolveReadableFile('', 1);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_FILES_NOT_FOUND, $e->getErrorCode());
		}
		try {
			$svc->resolveReadableFile('ae', 0);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_FILES_NOT_FOUND, $e->getErrorCode());
		}
	}

	public function testRejectsMissingNode(): void
	{
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(42)->willReturn([]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('ae')->willReturn($userFolder);

		try {
			$this->service($root)->resolveReadableFile('ae', 42);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_FILES_NOT_FOUND, $e->getErrorCode());
		}
	}

	public function testRejectsFolderNode(): void
	{
		$folder = $this->createMock(Folder::class);
		$folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$folder]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		try {
			$this->service($root)->resolveReadableFile('ae', 7);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_FILES_NOT_FILE, $e->getErrorCode());
		}
	}

	public function testRejectsDisallowedMime(): void
	{
		$file = $this->createMock(File::class);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getMimetype')->willReturn('application/zip');
		$file->method('getSize')->willReturn(100);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		try {
			$this->service($root)->resolveReadableFile('ae', 9, ['application/pdf'], 1000);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_ATTACHMENT_DENIED, $e->getErrorCode());
		}
	}

	public function testRejectsOversizedFile(): void
	{
		$file = $this->createMock(File::class);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(20_000_000);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		try {
			$this->service($root)->resolveReadableFile('ae', 9, ['application/pdf'], 10_000);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_ATTACHMENT_TOO_LARGE, $e->getErrorCode());
		}
	}

	public function testReturnsReadableFile(): void
	{
		$file = $this->createMock(File::class);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(512);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(11)->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('ae')->willReturn($userFolder);

		$result = $this->service($root)->resolveReadableFile(
			'ae',
			11,
			Constants::QUOTE_FILE_ALLOWED_MIMES,
			Constants::QUOTE_FILE_MAX_BYTES,
		);
		$this->assertSame($file, $result);
	}

	public function testLoadMailAttachmentsEnforcesCountAndTotal(): void
	{
		$root = $this->createMock(IRootFolder::class);
		$svc = $this->service($root);
		try {
			$svc->loadMailAttachments('ae', [1, 2, 3, 4, 5, 6]);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_ATTACHMENT_DENIED, $e->getErrorCode());
		}
	}

	public function testLoadMailAttachmentsReturnsBytes(): void
	{
		$file = $this->createMock(File::class);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(4);
		$file->method('getName')->willReturn('quote.pdf');
		$file->method('getContent')->willReturn('%PDF');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(55)->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('ae')->willReturn($userFolder);

		$out = $this->service($root)->loadMailAttachments('ae', [55, 55]);
		$this->assertCount(1, $out);
		$this->assertSame(55, $out[0]['fileId']);
		$this->assertSame('quote.pdf', $out[0]['filename']);
		$this->assertSame('%PDF', $out[0]['data']);
	}
}
