<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method int getDealId()
 * @method void setDealId(int $dealId)
 * @method int getCompanyId()
 * @method void setCompanyId(int $companyId)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getAssigneeUserId()
 * @method void setAssigneeUserId(?string $assigneeUserId)
 * @method \DateTime|null getDueOn()
 * @method void setDueOn(?\DateTime $dueOn)
 * @method string|null getEvidenceNote()
 * @method void setEvidenceNote(?string $evidenceNote)
 * @method string|null getDoneBy()
 * @method void setDoneBy(?string $doneBy)
 * @method \DateTime|null getDoneAt()
 * @method void setDoneAt(?\DateTime $doneAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class DealChecklistItem extends Entity
{
	public const STATUS_OPEN = 'open';
	public const STATUS_DONE = 'done';
	public const STATUS_CANCELLED = 'cancelled';

	protected $workspaceId = 0;
	protected $dealId = 0;
	protected $companyId = 0;
	protected $sortOrder = 0;
	protected $title = '';
	protected $status = self::STATUS_OPEN;
	protected $assigneeUserId = null;
	protected $dueOn = null;
	protected $evidenceNote = null;
	protected $doneBy = null;
	protected $doneAt = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('dealId', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('dueOn', Types::DATE);
		$this->addType('doneAt', Types::DATETIME);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function isOpen(): bool
	{
		return $this->status === self::STATUS_OPEN;
	}
}
