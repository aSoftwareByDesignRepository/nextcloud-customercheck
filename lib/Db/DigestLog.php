<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Weekly digest ledger — unique (week_key, workspace_id) for Digest v2 (§10.5).
 *
 * @method string getWeekKey()
 * @method void setWeekKey(string $weekKey)
 * @method int getWorkspaceId()
 * @method void setWorkspaceId(int $workspaceId)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $sentAt)
 * @method string|null getPayloadJson()
 * @method void setPayloadJson(?string $payloadJson)
 */
class DigestLog extends Entity
{
	protected $weekKey = '';
	protected $workspaceId = 0;
	protected $sentAt = null;
	protected $payloadJson = '{}';

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('workspaceId', Types::BIGINT);
		$this->addType('sentAt', Types::DATETIME);
	}
}
