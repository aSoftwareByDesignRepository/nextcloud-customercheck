<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyDocumentsService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Documents folder link — picker path + fileId, home-root rejection, file rejection.
 */
final class CompanyDocumentsServiceTest extends TestCase
{
	private CompanyService&MockObject $companies;
	private IRootFolder&MockObject $root;
	private IL10N&MockObject $l10n;
	private CompanyDocumentsService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->companies = $this->createMock(CompanyService::class);
		$this->root = $this->createMock(IRootFolder::class);
		$url = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$timeline = $this->createMock(TimelineService::class);
		$this->svc = new CompanyDocumentsService(
			$this->companies,
			$this->root,
			$url,
			$this->l10n,
			$timeline,
		);
	}

	public function testLinkFromPickerPrefersFileId(): void
	{
		$company = $this->companyFixture(7);
		$userFolder = $this->createMock(Folder::class);
		$target = $this->createMock(Folder::class);
		$userFolder->method('getId')->willReturn(1);
		$userFolder->method('getPath')->willReturn('/alice/files');
		$userFolder->method('getById')->with(42)->willReturn([$target]);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/alice/files/Customers/Acme');
		$target->method('getName')->willReturn('Acme');

		$this->root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$this->companies->method('get')->with(7)->willReturn($company);
		$this->companies->expects($this->once())->method('update')->with(
			7,
			$this->callback(static function (array $fields): bool {
				return ($fields['filesFolderId'] ?? null) === 42
					&& ($fields['filesFolderPath'] ?? null) === '/Customers/Acme';
			}),
			'alice',
			'2026-07-28T10:00:00+00:00',
		)->willReturn($company);

		$out = $this->svc->linkFromPicker(7, 'alice', 42, '/ignored', '2026-07-28T10:00:00+00:00');
		$this->assertSame($company, $out);
	}

	public function testLinkFromPickerResolvesPathWhenFileIdMissing(): void
	{
		$company = $this->companyFixture(3);
		$userFolder = $this->createMock(Folder::class);
		$target = $this->createMock(Folder::class);
		$userFolder->method('getId')->willReturn(1);
		$userFolder->method('getPath')->willReturn('/alice/files');
		$userFolder->method('get')->with('Contracts/Acme')->willReturn($target);
		$target->method('getId')->willReturn(99);
		$target->method('getPath')->willReturn('/alice/files/Contracts/Acme');
		$target->method('getName')->willReturn('Acme');

		$this->root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$this->companies->method('get')->with(3)->willReturn($company);
		$this->companies->expects($this->once())->method('update')->with(
			3,
			$this->callback(static function (array $fields): bool {
				return ($fields['filesFolderId'] ?? null) === 99
					&& ($fields['filesFolderPath'] ?? null) === '/Contracts/Acme';
			}),
			'alice',
			'2026-07-28T10:00:00+00:00',
		)->willReturn($company);

		$this->svc->linkFromPicker(3, 'alice', null, '/Contracts/Acme', '2026-07-28T10:00:00+00:00');
	}

	public function testLinkFromPickerRejectsHomeRootPath(): void
	{
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Pick a folder in the browser');
		$this->svc->linkFromPicker(1, 'alice', null, '/', null);
	}

	public function testLinkFromPickerRejectsPathTraversal(): void
	{
		try {
			$this->svc->linkFromPicker(1, 'alice', null, '/../etc', null);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertStringContainsString('Pick a folder in the browser', $e->getMessage());
		}
	}

	public function testLinkFromPickerRejectsFileNode(): void
	{
		$userFolder = $this->createMock(Folder::class);
		$file = $this->createMock(File::class);
		$userFolder->method('getId')->willReturn(1);
		$userFolder->method('get')->with('readme.txt')->willReturn($file);
		$this->root->method('getUserFolder')->willReturn($userFolder);

		try {
			$this->svc->linkFromPicker(1, 'alice', null, '/readme.txt', null);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_FILES_NOT_FOLDER, $e->getErrorCode());
		}
	}

	public function testLinkFolderRejectsHomeRootFileId(): void
	{
		$company = $this->companyFixture(2);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getId')->willReturn(5);
		$userFolder->method('getById')->with(5)->willReturn([$userFolder]);
		$this->root->method('getUserFolder')->willReturn($userFolder);
		$this->companies->method('get')->willReturn($company);

		try {
			$this->svc->linkFolder(2, 5, 'alice', '2026-07-28T10:00:00+00:00');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertStringContainsString('too broad', $e->getMessage());
		}
	}

	public function testLinkFromPickerMissingFolder(): void
	{
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getId')->willReturn(1);
		$userFolder->method('get')->willThrowException(new FilesNotFoundException());
		$this->root->method('getUserFolder')->willReturn($userFolder);

		try {
			$this->svc->linkFromPicker(1, 'alice', null, '/Gone', null);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_FILES_NOT_FOUND, $e->getErrorCode());
		}
	}

	public function testSearchFoldersRequiresTwoChars(): void
	{
		$this->assertSame([], $this->svc->searchFolders('alice', 'a'));
		$this->assertSame([], $this->svc->searchFolders('', 'ab'));
	}

	private function companyFixture(int $id): Company
	{
		$c = new Company();
		$c->setId($id);
		$c->setSlug('acme');
		$c->setUpdatedAt(new \DateTime('2026-07-28T10:00:00+00:00'));

		return $c;
	}
}
