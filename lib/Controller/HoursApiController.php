<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\QuotaService;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class HoursApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly AgreementService $agreements,
		private readonly QuotaService $quota,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		try {
			$this->accessControl->requireCanUseApp();
			$agreement = $this->agreements->get($id);
			$entries = $this->quota->listEntries($id);

			return $this->ok([
				'snapshot' => $this->quota->snapshot($agreement),
				'entries' => array_map(static fn ($e) => [
					'id' => (int)$e->getId(),
					'entryDate' => $e->getEntryDate()?->format('Y-m-d'),
					'minutes' => (int)$e->getMinutes(),
					'topic' => $e->getTopic(),
				], $entries),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(int $id): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			$agreement = $this->agreements->get($id);
			$date = DateMath::parseStrictDate((string)($body['entryDate'] ?? ''));
			if ($date === null) {
				throw ValidationException::field('VALIDATION', 'entryDate', 'Use YYYY-MM-DD.');
			}
			$entry = $this->quota->logHours(
				$agreement,
				$date,
				(int)($body['minutes'] ?? 0),
				(string)($body['topic'] ?? ''),
				$uid,
			);

			return $this->ok([
				'id' => (int)$entry->getId(),
				'snapshot' => $this->quota->snapshot($agreement),
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function update(int $entryId): JSONResponse
	{
		try {
			$uid = $this->accessControl->requireCanUseApp();
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			$entry = $this->quota->updateEntry($entryId, is_array($body) ? $body : [], $uid);
			$agreement = $this->agreements->get((int)$entry->getAgreementId());

			return $this->ok([
				'id' => (int)$entry->getId(),
				'minutes' => (int)$entry->getMinutes(),
				'topic' => $entry->getTopic(),
				'entryDate' => $entry->getEntryDate()?->format('Y-m-d'),
				'snapshot' => $this->quota->snapshot($agreement),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
