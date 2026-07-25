<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Email metadata on a company (§4.6). Never a mailbox copy: no bodies, no
 * attachments — subject plus an optional short snippet only.
 *
 * @method int getCompanyId()
 * @method void setCompanyId(int $companyId)
 * @method string getDirection()
 * @method void setDirection(string $direction)
 * @method string getFromAddr()
 * @method void setFromAddr(string $fromAddr)
 * @method string getToAddr()
 * @method void setToAddr(string $toAddr)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method \DateTime|null getOccurredAt()
 * @method void setOccurredAt(?\DateTime $occurredAt)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string|null getMailMessageId()
 * @method void setMailMessageId(?string $mailMessageId)
 * @method string|null getSnippet()
 * @method void setSnippet(?string $snippet)
 * @method int|null getPersonId()
 * @method void setPersonId(?int $personId)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 */
class EmailTouch extends Entity
{
	protected $companyId = 0;
	protected $direction = 'outbound';
	protected $fromAddr = '';
	protected $toAddr = '';
	protected $subject = '';
	protected $occurredAt = null;
	protected $source = 'manual';
	protected $mailMessageId = null;
	protected $snippet = null;
	protected $personId = null;
	protected $createdBy = null;
	protected $createdAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('personId', Types::BIGINT);
		$this->addType('occurredAt', Types::DATETIME);
		$this->addType('createdAt', Types::DATETIME);
	}
}
