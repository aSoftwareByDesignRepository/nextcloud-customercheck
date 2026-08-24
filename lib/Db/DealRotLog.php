<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Deal-rotting nudge ledger. Unique (deal_id, snap_key) makes owner
 * reminders exactly-once per stage-stretch nudge wave.
 *
 * @method int getDealId()
 * @method void setDealId(int $dealId)
 * @method string getSnapKey()
 * @method void setSnapKey(string $snapKey)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $sentAt)
 */
class DealRotLog extends Entity
{
	protected $dealId = 0;
	protected $snapKey = '';
	protected $sentAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('dealId', Types::BIGINT);
		$this->addType('sentAt', Types::DATETIME);
	}
}
