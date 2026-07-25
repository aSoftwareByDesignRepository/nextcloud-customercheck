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
 * @method string|null getVatId()
 * @method void setVatId(?string $vatId)
 * @method string|null getAddrStreet()
 * @method void setAddrStreet(?string $addrStreet)
 * @method string|null getAddrZip()
 * @method void setAddrZip(?string $addrZip)
 * @method string|null getAddrCity()
 * @method void setAddrCity(?string $addrCity)
 * @method string getCountry()
 * @method void setCountry(string $country)
 * @method string getLang()
 * @method void setLang(string $lang)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getWebsite()
 * @method void setWebsite(?string $website)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method int|null getPcCustomerId()
 * @method void setPcCustomerId(?int $pcCustomerId)
 * @method int|null getTcCustomerId()
 * @method void setTcCustomerId(?int $tcCustomerId)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method \DateTime|null getArchivedAt()
 * @method void setArchivedAt(?\DateTime $archivedAt)
 */
class Company extends Entity
{
	protected $slug = '';
	protected $name = '';
	protected $vatId = null;
	protected $addrStreet = null;
	protected $addrZip = null;
	protected $addrCity = null;
	protected $country = 'DE';
	protected $lang = 'de';
	protected $status = 'lead';
	protected $website = null;
	protected $notes = null;
	protected $pcCustomerId = null;
	protected $tcCustomerId = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;
	protected $archivedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('pcCustomerId', Types::BIGINT);
		$this->addType('tcCustomerId', Types::BIGINT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
		$this->addType('archivedAt', Types::DATETIME);
	}

	public function isArchived(): bool
	{
		return $this->archivedAt !== null;
	}
}
