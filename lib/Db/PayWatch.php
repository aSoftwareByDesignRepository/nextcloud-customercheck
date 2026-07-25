<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Invoice-paid watermark per agreement (§14.3): remembers the last observed
 * invoice status and paid total so effects fire only on a real transition.
 *
 * @method int getAgreementId()
 * @method void setAgreementId(int $agreementId)
 * @method int getLastPaidTotalCents()
 * @method void setLastPaidTotalCents(int $lastPaidTotalCents)
 * @method string getLastStatus()
 * @method void setLastStatus(string $lastStatus)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class PayWatch extends Entity
{
	protected $agreementId = 0;
	protected $lastPaidTotalCents = 0;
	protected $lastStatus = 'none';
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('agreementId', Types::BIGINT);
		$this->addType('lastPaidTotalCents', Types::BIGINT);
		$this->addType('updatedAt', Types::DATETIME);
	}
}
