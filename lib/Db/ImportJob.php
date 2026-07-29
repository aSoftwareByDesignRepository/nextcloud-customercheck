<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One row per import apply, for supportability (§4.0).
 *
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method string|null getSummaryJson()
 * @method void setSummaryJson(?string $summaryJson)
 */
class ImportJob extends Entity
{
	protected $source = 'csv';
	protected $createdBy = null;
	protected $createdAt = null;
	protected $summaryJson = '{}';

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('createdAt', Types::DATETIME);
	}
}
