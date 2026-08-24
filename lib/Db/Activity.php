<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCompanyId()
 * @method void setCompanyId(int $companyId)
 * @method int|null getDealId()
 * @method void setDealId(?int $dealId)
 * @method int|null getAgreementId()
 * @method void setAgreementId(?int $agreementId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method \DateTime|null getDueDate()
 * @method void setDueDate(?\DateTime $dueDate)
 * @method \DateTime|null getDoneAt()
 * @method void setDoneAt(?\DateTime $doneAt)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method string|null getTodoUri()
 * @method void setTodoUri(?string $todoUri)
 * @method int getIsCallback()
 * @method void setIsCallback(int $isCallback)
 * @method int|null getPersonId()
 * @method void setPersonId(?int $personId)
 * @method string|null getAssigneeUserId()
 * @method void setAssigneeUserId(?string $assigneeUserId)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class Activity extends Entity
{
	protected $companyId = 0;
	protected $dealId = null;
	protected $agreementId = null;
	protected $type = 'todo';
	protected $title = '';
	protected $details = null;
	protected $dueDate = null;
	protected $doneAt = null;
	protected $origin = 'user';
	protected $todoUri = null;
	protected $isCallback = 0;
	protected $personId = null;
	protected $assigneeUserId = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('dealId', Types::BIGINT);
		$this->addType('agreementId', Types::BIGINT);
		$this->addType('dueDate', Types::DATE);
		$this->addType('doneAt', Types::DATETIME);
		$this->addType('isCallback', Types::INTEGER);
		$this->addType('personId', Types::BIGINT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function isOpen(): bool
	{
		return $this->doneAt === null;
	}

	public function isCallback(): bool
	{
		return (int)$this->isCallback === 1;
	}
}
