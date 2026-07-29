<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Activity-due ledger row. Unique (activity_id, due_date_snap) makes
 * companion push + NC notifications exactly-once across job reruns.
 *
 * @method int getActivityId()
 * @method void setActivityId(int $activityId)
 * @method \DateTime|null getDueDateSnap()
 * @method void setDueDateSnap(?\DateTime $dueDateSnap)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $sentAt)
 */
class ActivityDueLog extends Entity
{
	protected $activityId = 0;
	protected $dueDateSnap = null;
	protected $sentAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('activityId', Types::BIGINT);
		$this->addType('dueDateSnap', Types::DATE);
		$this->addType('sentAt', Types::DATETIME);
	}
}
