<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getTemplateId()
 * @method void setTemplateId(int $templateId)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getTitleDe()
 * @method void setTitleDe(?string $titleDe)
 * @method string|null getInstructions()
 * @method void setInstructions(?string $instructions)
 */
class ChecklistTemplateItem extends Entity
{
	protected $templateId = 0;
	protected $sortOrder = 0;
	protected $title = '';
	protected $titleDe = null;
	protected $instructions = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('templateId', Types::BIGINT);
		$this->addType('sortOrder', Types::INTEGER);
	}
}
