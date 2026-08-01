<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getIsDefault()
 * @method void setIsDefault(int $isDefault)
 * @method \DateTime|null getArchivedAt()
 * @method void setArchivedAt(?\DateTime $archivedAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class Workspace extends Entity
{
	protected $slug = '';
	protected $name = '';
	protected $isDefault = 0;
	protected $archivedAt = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('isDefault', Types::INTEGER);
		$this->addType('archivedAt', Types::DATETIME);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function isDefaultWorkspace(): bool
	{
		return (int)$this->isDefault === 1;
	}

	public function isArchived(): bool
	{
		return $this->archivedAt !== null;
	}
}
