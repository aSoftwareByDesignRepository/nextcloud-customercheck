<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Reminder ledger row. The unique index `crm_rl_uq` on
 * (agreement_id, offset_days, valid_until_snap) is what makes the cadence
 * exactly-once across job reruns and races (§6.3).
 *
 * @method int getAgreementId()
 * @method void setAgreementId(int $agreementId)
 * @method int getOffsetDays()
 * @method void setOffsetDays(int $offsetDays)
 * @method \DateTime|null getValidUntilSnap()
 * @method void setValidUntilSnap(?\DateTime $validUntilSnap)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $sentAt)
 */
class ReminderLog extends Entity
{
	protected $agreementId = 0;
	protected $offsetDays = 0;
	protected $validUntilSnap = null;
	protected $sentAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('agreementId', Types::BIGINT);
		$this->addType('offsetDays', Types::INTEGER);
		$this->addType('validUntilSnap', Types::DATE);
		$this->addType('sentAt', Types::DATETIME);
	}
}
