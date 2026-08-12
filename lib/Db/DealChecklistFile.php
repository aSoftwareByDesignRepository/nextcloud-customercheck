<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getItemId()
 * @method void setItemId(int $itemId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getNameSnap()
 * @method void setNameSnap(string $nameSnap)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class DealChecklistFile extends Entity
{
	protected $itemId = 0;
	protected $fileId = 0;
	protected $nameSnap = '';
	protected $createdBy = null;
	protected $createdAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('itemId', Types::BIGINT);
		$this->addType('fileId', Types::BIGINT);
		$this->addType('createdAt', Types::DATETIME);
	}
}
