<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getAgreementId()
 * @method void setAgreementId(int $agreementId)
 * @method \DateTime|null getEntryDate()
 * @method void setEntryDate(?\DateTime $entryDate)
 * @method int getMinutes()
 * @method void setMinutes(int $minutes)
 * @method string|null getTopic()
 * @method void setTopic(?string $topic)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 */
class HoursEntry extends Entity
{
	protected $agreementId = 0;
	protected $entryDate = null;
	protected $minutes = 0;
	protected $topic = null;
	protected $createdBy = null;
	protected $createdAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('agreementId', Types::BIGINT);
		$this->addType('minutes', Types::INTEGER);
		$this->addType('entryDate', Types::DATE);
		$this->addType('createdAt', Types::DATETIME);
	}
}
