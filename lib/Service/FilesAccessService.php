<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;

/**
 * Actor-scoped Files resolution — never trust raw file ids alone.
 *
 * All CRM features that store or open a Nextcloud file id must go through
 * getUserFolder($actor)->getById(...). Service accounts and filecache SQL
 * are forbidden (confused-deputy / privacy).
 */
class FilesAccessService
{
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * True when the actor can read the file id (non-folder). Never throws for missing/denied.
	 */
	public function isReadableFile(string $userId, int $fileId): bool
	{
		try {
			$this->resolveReadableFile($userId, $fileId);

			return true;
		} catch (ValidationException) {
			return false;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Resolve a file the actor can read. Rejects folders and inaccessible ids.
	 *
	 * @param list<string>|null $allowedMimes null = any non-folder file
	 * @throws ValidationException
	 */
	public function resolveReadableFile(
		string $userId,
		int $fileId,
		?array $allowedMimes = null,
		?int $maxBytes = null,
	): File {
		$userId = trim($userId);
		if ($userId === '' || $fileId <= 0) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOUND,
				$this->l10n->t('Pick a file in Files, then choose it.'),
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
				$this->l10n->t('That file is gone or you cannot read it.'),
			);
		}

		$node = $nodes[0];
		if ($node->getType() === FileInfo::TYPE_FOLDER || !$node instanceof File) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FILE,
				$this->l10n->t('Pick a file, not a folder.'),
			);
		}

		$mime = strtolower(trim((string)$node->getMimetype()));
		if ($allowedMimes !== null && $allowedMimes !== []) {
			$allowed = array_map(static fn (string $m): string => strtolower(trim($m)), $allowedMimes);
			if (!in_array($mime, $allowed, true)) {
				throw new ValidationException(
					Constants::ERR_ATTACHMENT_DENIED,
					$this->l10n->t('This file type is not allowed. Use a PDF (or another allowed type).'),
				);
			}
		}

		$size = (int)$node->getSize();
		if ($maxBytes !== null && $maxBytes > 0 && $size > $maxBytes) {
			throw new ValidationException(
				Constants::ERR_ATTACHMENT_TOO_LARGE,
				$this->l10n->t('This file is too large (max %s).', [$this->humanBytes($maxBytes)]),
			);
		}

		return $node;
	}

	/**
	 * Resolve a readable file that lives under a company folder (path-prefix tenancy).
	 * Prevents linking arbitrary personal/shared files onto a deal checklist.
	 *
	 * @throws ValidationException
	 */
	public function assertFileUnderFolder(string $userId, int $fileId, int $folderId): File
	{
		$file = $this->resolveReadableFile($userId, $fileId);
		$userId = trim($userId);
		if ($folderId <= 0) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOUND,
				$this->l10n->t('Link a company folder in Files before attaching evidence.'),
			);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($folderId);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not access your Files.'),
			);
		}
		$folder = $nodes[0] ?? null;
		if (!$folder instanceof Folder) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOUND,
				$this->l10n->t('That company folder is gone or you cannot open it.'),
			);
		}

		$folderPath = rtrim($folder->getPath(), '/');
		$filePath = $file->getPath();
		if ($filePath !== $folderPath && !str_starts_with($filePath, $folderPath . '/')) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'fileId',
				$this->l10n->t('Pick a file from this company’s folder.'),
			);
		}

		return $file;
	}

	/**
	 * Load attachment bytes for shared-mailbox send (actor-scoped).
	 *
	 * @param list<int> $fileIds
	 * @return list<array{fileId: int, filename: string, mime: string, data: string, size: int}>
	 * @throws ValidationException
	 */
	public function loadMailAttachments(string $userId, array $fileIds): array
	{
		$ids = [];
		foreach ($fileIds as $raw) {
			$id = (int)$raw;
			if ($id > 0 && !in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}
		if (count($ids) > Constants::MAIL_ATTACH_MAX_COUNT) {
			throw new ValidationException(
				Constants::ERR_ATTACHMENT_DENIED,
				$this->l10n->t('You can attach at most %s files.', [(string)Constants::MAIL_ATTACH_MAX_COUNT]),
			);
		}

		$out = [];
		$total = 0;
		foreach ($ids as $id) {
			$file = $this->resolveReadableFile(
				$userId,
				$id,
				Constants::MAIL_ATTACH_ALLOWED_MIMES,
				Constants::MAIL_ATTACH_MAX_BYTES,
			);
			try {
				$data = $file->getContent();
			} catch (\Throwable) {
				throw new ValidationException(
					Constants::ERR_FILES_PERMISSION_DENIED,
					$this->l10n->t('Could not read the attachment “%s”.', [(string)$file->getName()]),
				);
			}
			$size = strlen($data);
			$total += $size;
			if ($total > Constants::MAIL_ATTACH_MAX_TOTAL_BYTES) {
				throw new ValidationException(
					Constants::ERR_ATTACHMENT_TOO_LARGE,
					$this->l10n->t('Attachments together are too large (max %s).', [
						$this->humanBytes(Constants::MAIL_ATTACH_MAX_TOTAL_BYTES),
					]),
				);
			}
			$out[] = [
				'fileId' => $id,
				'filename' => $this->safeFilename((string)$file->getName()),
				'mime' => strtolower(trim((string)$file->getMimetype())) ?: 'application/octet-stream',
				'data' => $data,
				'size' => $size,
			];
		}

		return $out;
	}

	/**
	 * Stage a device upload into the actor's Files tree for outbound mail (MH-18 companion).
	 * Stored under CustomerCheck/Mail Outbound/ so FAC-14 ACL is the same user folder.
	 *
	 * @return array{fileId: int, name: string, mime: string, size: int}
	 * @throws ValidationException
	 */
	public function stageOutboundMailAttachment(
		string $userId,
		string $fileName,
		string $mime,
		string $binary,
	): array {
		$userId = trim($userId);
		if ($userId === '') {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Sign in to attach a file.'),
			);
		}
		$size = strlen($binary);
		if ($size <= 0) {
			throw new ValidationException(
				Constants::ERR_ATTACHMENT_DENIED,
				$this->l10n->t('The attachment is empty.'),
			);
		}
		if ($size > Constants::MAIL_ATTACH_MAX_BYTES) {
			throw new ValidationException(
				Constants::ERR_ATTACHMENT_TOO_LARGE,
				$this->l10n->t('Each attachment may be at most %s.', [
					$this->humanBytes(Constants::MAIL_ATTACH_MAX_BYTES),
				]),
			);
		}
		$mime = strtolower(trim($mime));
		if ($mime === '' || $mime === 'application/octet-stream') {
			$mime = $this->guessMimeFromName($fileName);
		}
		if (!in_array($mime, Constants::MAIL_ATTACH_ALLOWED_MIMES, true)) {
			throw new ValidationException(
				Constants::ERR_ATTACHMENT_DENIED,
				$this->l10n->t('Only PDF, PNG, JPEG or plain text attachments are allowed.'),
			);
		}
		$safeName = $this->safeFilename($fileName);
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$root = $this->ensureFolder($userFolder, 'CustomerCheck');
			$outbox = $this->ensureFolder($root, 'Mail Outbound');
			if ($outbox->nodeExists($safeName)) {
				$safeName = pathinfo($safeName, PATHINFO_FILENAME)
					. '-' . substr(bin2hex(random_bytes(4)), 0, 8)
					. (pathinfo($safeName, PATHINFO_EXTENSION) !== ''
						? '.' . pathinfo($safeName, PATHINFO_EXTENSION)
						: '');
				$safeName = $this->safeFilename($safeName);
			}
			$file = $outbox->newFile($safeName, $binary);
			$id = (int)$file->getId();
			if ($id <= 0) {
				throw new \RuntimeException('missing file id');
			}

			return [
				'fileId' => $id,
				'name' => (string)$file->getName(),
				'mime' => $mime,
				'size' => $size,
			];
		} catch (ValidationException $e) {
			throw $e;
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Could not store the attachment in your Files.'),
			);
		}
	}

	private function ensureFolder(Folder $parent, string $name): Folder
	{
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOLDER,
				$this->l10n->t('Could not prepare the mail attachment folder.'),
			);
		}
		$created = $parent->newFolder($name);
		if (!$created instanceof Folder) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOLDER,
				$this->l10n->t('Could not prepare the mail attachment folder.'),
			);
		}

		return $created;
	}

	/**
	 * List attachable files under a path in the actor's Files (companion MH-18 Files picker).
	 * Only returns non-folder files with MAIL_ATTACH_ALLOWED_MIMES. Path is relative to user root.
	 *
	 * @return array{path: string, parent: string|null, folders: list<array{name: string, path: string}>, files: list<array{id: int, name: string, mime: string, size: int, path: string}>}
	 */
	public function listAttachableMailFiles(string $userId, string $relativePath = '', int $limit = 100): array
	{
		$userId = trim($userId);
		if ($userId === '') {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('Sign in to browse Files.'),
			);
		}
		$limit = max(1, min(200, $limit));
		$relativePath = trim(str_replace('\\', '/', $relativePath), '/');
		if (str_contains($relativePath, '..')) {
			throw new ValidationException(
				Constants::ERR_FILES_PERMISSION_DENIED,
				$this->l10n->t('That folder path is not allowed.'),
			);
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$folder = $relativePath === '' ? $userFolder : $userFolder->get($relativePath);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOUND,
				$this->l10n->t('That folder is gone or you cannot open it.'),
			);
		}
		if (!$folder instanceof Folder) {
			throw new ValidationException(
				Constants::ERR_FILES_NOT_FOLDER,
				$this->l10n->t('Pick a folder, not a file.'),
			);
		}
		$folders = [];
		$files = [];
		try {
			$children = $folder->getDirectoryListing();
		} catch (\Throwable) {
			$children = [];
		}
		foreach ($children as $node) {
			if ($node instanceof Folder) {
				if (count($folders) >= $limit) {
					continue;
				}
				$name = (string)$node->getName();
				$folders[] = [
					'name' => $name,
					'path' => ltrim(($relativePath !== '' ? $relativePath . '/' : '') . $name, '/'),
				];
				continue;
			}
			if (!$node instanceof File) {
				continue;
			}
			if (count($files) >= $limit) {
				continue;
			}
			$mime = strtolower(trim((string)$node->getMimetype()));
			if (!in_array($mime, Constants::MAIL_ATTACH_ALLOWED_MIMES, true)) {
				continue;
			}
			$size = (int)$node->getSize();
			if ($size <= 0 || $size > Constants::MAIL_ATTACH_MAX_BYTES) {
				continue;
			}
			$id = (int)$node->getId();
			if ($id <= 0) {
				continue;
			}
			$name = (string)$node->getName();
			$files[] = [
				'id' => $id,
				'name' => $name,
				'mime' => $mime,
				'size' => $size,
				'path' => ltrim(($relativePath !== '' ? $relativePath . '/' : '') . $name, '/'),
			];
		}
		$parent = null;
		if ($relativePath !== '') {
			$parent = dirname($relativePath);
			if ($parent === '.' || $parent === '/') {
				$parent = '';
			}
		}

		return [
			'path' => $relativePath,
			'parent' => $parent,
			'folders' => $folders,
			'files' => $files,
		];
	}

	private function guessMimeFromName(string $name): string
	{
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

		return match ($ext) {
			'pdf' => 'application/pdf',
			'png' => 'image/png',
			'jpg', 'jpeg' => 'image/jpeg',
			'txt' => 'text/plain',
			default => 'application/octet-stream',
		};
	}

	private function safeFilename(string $name): string
	{
		$name = str_replace(["\r", "\n", "\0"], '', $name);
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..') {
			return 'attachment.bin';
		}

		return mb_substr($name, 0, 180);
	}

	private function humanBytes(int $bytes): string
	{
		if ($bytes >= 1048576) {
			return (string)(int)round($bytes / 1048576) . ' MiB';
		}

		return (string)(int)round($bytes / 1024) . ' KiB';
	}
}
