<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Customer Documents — link/create/browse Nextcloud Files folders (CC §8).
 *
 * Never SELECTs filecache. Uses OCP Files APIs only (AC-CC-14).
 */
class CompanyDocumentsService
{
	public function __construct(
		private readonly CompanyService $companies,
		private readonly IRootFolder $rootFolder,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly TimelineService $timeline,
	) {
	}

	/**
	 * @return list<array{fileId: int, name: string, path: string}>
	 */
	public function searchFolders(string $userId, string $query, int $limit = 20): array
	{
		$q = trim($query);
		if (mb_strlen($q) < 2 || $userId === '') {
			return [];
		}
		$limit = max(1, min(50, $limit));
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return [];
		}

		$out = [];
		try {
			$hits = $userFolder->search($q);
		} catch (\Throwable) {
			return [];
		}
		foreach ($hits as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			try {
				$out[] = [
					'fileId' => (int)$node->getId(),
					'name' => (string)$node->getName(),
					'path' => $this->displayPath($userFolder, $node),
				];
			} catch (\Throwable) {
				continue;
			}
			if (count($out) >= $limit) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function linkFolder(int $companyId, int $fileId, string $userId, ?string $updatedAtPrecondition): Company
	{
		$company = $this->companies->get($companyId);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

		$folder = $this->resolveFolder($userId, $fileId);
		$path = $this->pathFor($userId, $folder);

		return $this->companies->update($companyId, [
			'filesFolderId' => (int)$folder->getId(),
			'filesFolderPath' => $path,
		], $userId, $updatedAtPrecondition);
	}

	/**
	 * Link by fileId and/or user-relative path from the Files picker.
	 *
	 * Prefer fileId when present; path is a resilient fallback when the client
	 * resolved the folder path but not the numeric id.
	 *
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function linkFromPicker(
		int $companyId,
		string $userId,
		?int $fileId,
		?string $path,
		?string $updatedAtPrecondition,
	): Company {
		$id = $fileId !== null ? (int)$fileId : 0;
		if ($id > 0) {
			return $this->linkFolder($companyId, $id, $userId, $updatedAtPrecondition);
		}

		$normalized = $this->normalizeClientPath($path);
		if ($normalized === null) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Pick a folder in the browser, then click Choose.'),
			);
		}

		$folder = $this->resolveFolderByPath($userId, $normalized);
		$company = $this->companies->get($companyId);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);
		$display = $this->pathFor($userId, $folder);

		return $this->companies->update($companyId, [
			'filesFolderId' => (int)$folder->getId(),
			'filesFolderPath' => $display,
		], $userId, $updatedAtPrecondition);
	}

	/**
	 * @return array{fileId: int, path: string, company: Company}
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function createFolder(int $companyId, string $userId, ?int $parentFileId, ?string $updatedAtPrecondition): array
	{
		$company = $this->companies->get($companyId);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not access your Files.'),
			);
		}

		$parent = $userFolder;
		if ($parentFileId !== null && $parentFileId > 0) {
			$parent = $this->resolveFolder($userId, $parentFileId);
		} else {
			try {
				if (!$userFolder->nodeExists('CustomerCheck')) {
					$userFolder->newFolder('CustomerCheck');
				}
				$root = $userFolder->get('CustomerCheck');
				if (!$root instanceof Folder) {
					throw new ValidationException(
						Constants::ERR_FILES_NOT_FOLDER,
						$this->l10n->t('CustomerCheck in Files is not a folder.'),
					);
				}
				$parent = $root;
			} catch (ValidationException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new ValidationException(
					Constants::ERR_FILES_PERMISSION_DENIED,
					$this->l10n->t('Could not create the CustomerCheck folder.'),
				);
			}
		}

		$slug = (string)$company->getSlug();
		$name = $slug !== '' ? $slug : ('company-' . $companyId);
		$name = preg_replace('/[^a-z0-9._-]+/i', '-', $name) ?: ('company-' . $companyId);
		try {
			if ($parent->nodeExists($name)) {
				$existing = $parent->get($name);
				if (!$existing instanceof Folder) {
					$name = $name . '-' . $companyId;
					$folder = $parent->newFolder($name);
				} else {
					$folder = $existing;
				}
			} else {
				$folder = $parent->newFolder($name);
			}
		} catch (NotPermittedException) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('You do not have permission to create that folder.'),
			);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not create the customer folder.'),
			);
		}

		$path = $this->pathFor($userId, $folder);
		$updated = $this->companies->update($companyId, [
			'filesFolderId' => (int)$folder->getId(),
			'filesFolderPath' => $path,
		], $userId, OptimisticLock::format($company->getUpdatedAt()));

		$this->timeline->append($companyId, 'company.updated', $userId, [
			'filesFolderLinked' => true,
			'path' => $path,
		]);

		return [
			'fileId' => (int)$folder->getId(),
			'path' => $path,
			'company' => $updated,
		];
	}

	/**
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function unlinkFolder(int $companyId, string $userId, ?string $updatedAtPrecondition): Company
	{
		return $this->companies->update($companyId, [
			'filesFolderId' => null,
			'filesFolderPath' => null,
		], $userId, $updatedAtPrecondition);
	}

	/**
	 * Upload bytes into the company's Files folder (actor ACL). Creates the folder if missing.
	 *
	 * @return array{fileId: int, name: string}
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function uploadIntoCompanyFolder(int $companyId, string $userId, string $fileName, string $binary): array
	{
		$company = $this->companies->get($companyId);
		$folderId = $company->getFilesFolderId();
		if ($folderId === null || (int)$folderId <= 0) {
			$created = $this->createFolder(
				$companyId,
				$userId,
				null,
				OptimisticLock::format($company->getUpdatedAt()),
			);
			$folderId = (int)$created['fileId'];
		}
		$folder = $this->resolveFolder($userId, (int)$folderId);

		$safe = basename(str_replace("\0", '', trim($fileName)));
		$safe = str_replace(['/', '\\'], '-', $safe);
		if ($safe === '' || $safe === '.' || $safe === '..') {
			$safe = 'upload.bin';
		}
		$safe = mb_substr($safe, 0, 200);
		$target = $safe;
		$n = 0;
		while ($folder->nodeExists($target)) {
			$n++;
			$dot = strrpos($safe, '.');
			if ($dot === false) {
				$target = $safe . '-' . $n;
			} else {
				$target = substr($safe, 0, $dot) . '-' . $n . substr($safe, $dot);
			}
			if ($n > 50) {
				$target = 'upload-' . time() . '-' . bin2hex(random_bytes(4)) . '.bin';
				break;
			}
		}

		try {
			$file = $folder->newFile($target);
			$file->putContent($binary);
		} catch (NotPermittedException) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('You do not have permission to upload into that folder.'),
			);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not upload the file.'),
			);
		}

		return [
			'fileId' => (int)$file->getId(),
			'name' => (string)$file->getName(),
		];
	}

	/**
	 * Best-effort cleanup when a companion upload is not linked (idempotency race / link failure).
	 * Never throws — caller already has a more important error or replay payload.
	 */
	public function tryDeleteUploadedFile(string $userId, int $fileId): void
	{
		if ($fileId < 1 || $userId === '') {
			return;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($fileId);
			$node = $nodes[0] ?? null;
			if ($node !== null && !($node instanceof Folder)) {
				$node->delete();
			}
		} catch (\Throwable) {
			// Orphan may linger in company folder — preferred over failing the client replay.
		}
	}

	/**
	 * @return array{items: list<array{fileId:int,name:string,isFolder:bool,size:int,mtime:int}>, broken: bool, folderUrl: ?string, path: ?string}
	 */
	public function listChildren(int $companyId, string $userId, bool $clearBrokenLink = true): array
	{
		$company = $this->companies->get($companyId);
		$fileId = $company->getFilesFolderId();
		if ($fileId === null || (int)$fileId <= 0) {
			return [
				'items' => [],
				'broken' => false,
				'folderUrl' => null,
				'path' => $company->getFilesFolderPath(),
			];
		}

		try {
			$folder = $this->resolveFolder($userId, (int)$fileId);
		} catch (ValidationException $e) {
			if ($e->getErrorCode() === Constants::ERR_FILES_NOT_FOUND && $clearBrokenLink) {
				try {
					$this->companies->update($companyId, [
						'filesFolderId' => null,
						'filesFolderPath' => null,
					], $userId, OptimisticLock::format($company->getUpdatedAt()));
				} catch (\Throwable) {
					// Stale lock — still report broken.
				}
			}

			return [
				'items' => [],
				'broken' => true,
				'folderUrl' => null,
				'path' => null,
			];
		}

		$items = [];
		try {
			$children = $folder->getDirectoryListing();
		} catch (NotPermittedException) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Ask an admin for access to this folder.'),
			);
		}
		foreach ($children as $child) {
			try {
				$items[] = [
					'fileId' => (int)$child->getId(),
					'name' => (string)$child->getName(),
					'isFolder' => $child->getType() === FileInfo::TYPE_FOLDER,
					'size' => (int)$child->getSize(),
					'mtime' => (int)$child->getMTime(),
				];
			} catch (\Throwable) {
				continue;
			}
		}
		usort($items, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
		$items = array_slice($items, 0, 50);

		return [
			'items' => $items,
			'broken' => false,
			'folderUrl' => $this->filesUrl((int)$folder->getId()),
			'path' => $this->pathFor($userId, $folder),
		];
	}

	public function filesUrl(int $fileId): string
	{
		return $this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $fileId])
			?: $this->urlGenerator->getAbsoluteURL('/apps/files/?dir=/&fileid=' . $fileId);
	}

	/**
	 * @throws ValidationException
	 */
	private function resolveFolder(string $userId, int $fileId): Folder
	{
		if ($fileId <= 0) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Pick a folder in the browser, then click Choose.'),
			);
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($fileId);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not access your Files.'),
			);
		}
		if ($nodes === []) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOUND,
				$this->l10n->t('That folder is gone. Link a new one.'),
			);
		}
		$node = $nodes[0];
		if (!$node instanceof Folder) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOLDER,
				$this->l10n->t('Pick a folder, not a file.'),
			);
		}
		if ((int)$node->getId() === (int)$userFolder->getId()) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Your whole Files home is too broad. Pick a specific folder.'),
			);
		}

		return $node;
	}

	/**
	 * @throws ValidationException
	 */
	private function resolveFolderByPath(string $userId, string $normalizedPath): Folder
	{
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not access your Files.'),
			);
		}

		$relative = ltrim($normalizedPath, '/');
		try {
			$node = $relative === '' ? $userFolder : $userFolder->get($relative);
		} catch (FilesNotFoundException) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOUND,
				$this->l10n->t('That folder is gone. Link a new one.'),
			);
		} catch (NotPermittedException) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Ask an admin for access to this folder.'),
			);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not access your Files.'),
			);
		}

		if (!$node instanceof Folder) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOLDER,
				$this->l10n->t('Pick a folder, not a file.'),
			);
		}

		// Never link the entire home directory as a customer documents root.
		if ((int)$node->getId() === (int)$userFolder->getId()) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Your whole Files home is too broad. Pick a specific folder.'),
			);
		}

		return $node;
	}

	/**
	 * Normalize a client/DAV path to a user-relative folder path ("/Foo/Bar").
	 * Returns null when empty or home-root.
	 */
	private function normalizeClientPath(?string $path): ?string
	{
		$raw = trim((string)$path);
		if ($raw === '') {
			return null;
		}
		// Strip accidental absolute DAV prefixes if a client sends them.
		$raw = preg_replace('#^/remote\.php/dav/files/[^/]+#i', '', $raw) ?? $raw;
		$raw = preg_replace('#^/[^/]+/files#i', '', $raw) ?? $raw;
		$raw = '/' . trim(str_replace('\\', '/', $raw), '/');
		if ($raw === '/' || $raw === '') {
			return null;
		}
		// Block path tricks — OCP get() still scopes to user folder, but reject dots early.
		$parts = explode('/', trim($raw, '/'));
		foreach ($parts as $part) {
			if ($part === '.' || $part === '..' || $part === '') {
				return null;
			}
		}

		return '/' . implode('/', $parts);
	}

	private function pathFor(string $userId, Folder $folder): string
	{
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);

			return $this->displayPath($userFolder, $folder);
		} catch (\Throwable) {
			return '/' . $folder->getName();
		}
	}

	private function displayPath(Folder $userFolder, Folder $folder): string
	{
		$userPath = rtrim($userFolder->getPath(), '/');
		$full = $folder->getPath();
		if (str_starts_with($full, $userPath)) {
			$rel = substr($full, strlen($userPath));

			return $rel === '' ? '/' : $rel;
		}

		return $full;
	}
}
