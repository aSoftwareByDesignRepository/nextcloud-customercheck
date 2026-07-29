<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method int getCanMailSend()
 * @method void setCanMailSend(int $canMailSend)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class WorkspaceMember extends Entity
{
	protected $workspaceId = 0;
	protected $userId = '';
	protected $role = 'member';
	protected $canMailSend = 1;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('canMailSend', Types::INTEGER);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function maySendMail(): bool
	{
		return (int)$this->canMailSend === 1;
	}
}
