<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Db\TimelineEvent;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Append-only company audit trail (§4.6).
 *
 * Timeline writes are the last step of the lock order (§4.8), so they never
 * hold a lock another writer is waiting for. Appending must never break the
 * business operation that triggered it: a failed audit write is logged by the
 * caller's transaction rollback, never swallowed silently mid-transaction.
 */
class TimelineService
{
	public function __construct(
		private readonly TimelineEventMapper $mapper,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function append(int $companyId, string $eventType, string $actor, array $payload = []): TimelineEvent
	{
		$event = new TimelineEvent();
		$event->setCompanyId($companyId);
		$event->setEventType($eventType);
		$event->setActor($actor !== '' ? $actor : 'system');
		$event->setPayloadJson($this->encodePayload($payload));
		$event->setCreatedAt($this->timeFactory->getDateTime());

		return $this->mapper->insert($event);
	}

	/** @return TimelineEvent[] */
	public function listForCompany(int $companyId, int $limit = 100, int $offset = 0): array
	{
		return $this->mapper->findByCompany($companyId, $limit, $offset);
	}

	/**
	 * GDPR erase (§4.8): the event type and timestamp survive as proof that
	 * something happened; the payload is replaced.
	 */
	public function anonymiseCompany(int $companyId): int
	{
		return $this->mapper->anonymiseByCompany($companyId);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function encodePayload(array $payload): string
	{
		try {
			$encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		} catch (\JsonException) {
			return '{}';
		}

		// Payloads are metadata, not documents; keep the audit row bounded.
		if (strlen($encoded) > 4000) {
			return json_encode(['truncated' => true], JSON_THROW_ON_ERROR);
		}

		return $encoded;
	}
}
