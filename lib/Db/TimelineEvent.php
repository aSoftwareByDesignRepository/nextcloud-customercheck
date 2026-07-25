<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Append-only audit record on a company timeline (§4.6). Never updated by users;
 * only the GDPR erase path rewrites `payload_json` (§4.8).
 *
 * @method int getCompanyId()
 * @method void setCompanyId(int $companyId)
 * @method string getActor()
 * @method void setActor(string $actor)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string|null getPayloadJson()
 * @method void setPayloadJson(?string $payloadJson)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 */
class TimelineEvent extends Entity
{
	protected $companyId = 0;
	protected $actor = 'system';
	protected $eventType = 'note';
	protected $payloadJson = '{}';
	protected $createdAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('createdAt', Types::DATETIME);
	}

	/** @return array<string, mixed> */
	public function decodedPayload(): array
	{
		$decoded = json_decode((string)($this->payloadJson ?? '{}'), true);
		return is_array($decoded) ? $decoded : [];
	}
}
