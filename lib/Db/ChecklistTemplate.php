<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method string getTmplKey()
 * @method void setTmplKey(string $tmplKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getNameDe()
 * @method void setNameDe(?string $nameDe)
 * @method int getIsSystem()
 * @method void setIsSystem(int $isSystem)
 * @method int getIsEnabled()
 * @method void setIsEnabled(int $isEnabled)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class ChecklistTemplate extends Entity
{
	protected $workspaceId = 0;
	protected $tmplKey = '';
	protected $name = '';
	protected $nameDe = null;
	protected $isSystem = 0;
	protected $isEnabled = 1;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('isSystem', Types::INTEGER);
		$this->addType('isEnabled', Types::INTEGER);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}
}
