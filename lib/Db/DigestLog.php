<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Weekly digest ledger — unique `week_key` (`YYYY-Www`) makes the digest
 * exactly-once per ISO week (§14.1).
 *
 * @method string getWeekKey()
 * @method void setWeekKey(string $weekKey)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $sentAt)
 * @method string|null getPayloadJson()
 * @method void setPayloadJson(?string $payloadJson)
 */
class DigestLog extends Entity
{
	protected $weekKey = '';
	protected $sentAt = null;
	protected $payloadJson = '{}';

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('sentAt', Types::DATETIME);
	}
}
