<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\Files\File;
use OCP\Files\FileInfo;
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
