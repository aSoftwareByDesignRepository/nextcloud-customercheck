<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Stage-sequence ledger — unique (deal_id, sequence_id) so re-entering a stage
 * never recreates follow-up activities (§14.2).
 *
 * @method int getDealId()
 * @method void setDealId(int $dealId)
 * @method string getSequenceId()
 * @method void setSequenceId(string $sequenceId)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 */
class SeqLog extends Entity
{
	protected $dealId = 0;
	protected $sequenceId = '';
	protected $createdAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('dealId', Types::BIGINT);
		$this->addType('createdAt', Types::DATETIME);
	}
}
