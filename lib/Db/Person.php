<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCompanyId()
 * @method void setCompanyId(int $companyId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string|null getPhone()
 * @method void setPhone(?string $phone)
 * @method int getIsPrimary()
 * @method void setIsPrimary(int $isPrimary)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class Person extends Entity
{
	protected $companyId = 0;
	protected $name = '';
	protected $role = null;
	protected $email = null;
	protected $phone = null;
	protected $isPrimary = 0;
	protected $notes = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('isPrimary', Types::INTEGER);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function isPrimaryContact(): bool
	{
		return (int)$this->isPrimary === 1;
	}
}
