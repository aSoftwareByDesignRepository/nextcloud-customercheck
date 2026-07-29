<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCompanyId()
 * @method void setCompanyId(int $companyId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getStage()
 * @method void setStage(string $stage)
 * @method int getValueCents()
 * @method void setValueCents(int $valueCents)
 * @method string|null getProducts()
 * @method void setProducts(?string $products)
 * @method \DateTime|null getExpectedClose()
 * @method void setExpectedClose(?\DateTime $expectedClose)
 * @method \DateTime|null getStageChangedAt()
 * @method void setStageChangedAt(?\DateTime $stageChangedAt)
 * @method string|null getLostReason()
 * @method void setLostReason(?string $lostReason)
 * @method int|null getAgreementId()
 * @method void setAgreementId(?int $agreementId)
 * @method string|null getOwnerUserId()
 * @method void setOwnerUserId(?string $ownerUserId)
 * @method \DateTime|null getQuoteSentOn()
 * @method void setQuoteSentOn(?\DateTime $quoteSentOn)
 * @method int|null getQuoteFileId()
 * @method void setQuoteFileId(?int $quoteFileId)
 * @method string|null getForecastBucket()
 * @method void setForecastBucket(?string $forecastBucket)
 * @method string|null getLostReasonCode()
 * @method void setLostReasonCode(?string $lostReasonCode)
 * @method string|null getWonReasonCode()
 * @method void setWonReasonCode(?string $wonReasonCode)
 * @method \DateTime|null getLeadFirstTouchAt()
 * @method void setLeadFirstTouchAt(?\DateTime $leadFirstTouchAt)
 * @method \DateTime|null getLeadSlaDueAt()
 * @method void setLeadSlaDueAt(?\DateTime $leadSlaDueAt)
 * @method \DateTime|null getNextActionSkipUntil()
 * @method void setNextActionSkipUntil(?\DateTime $nextActionSkipUntil)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class Deal extends Entity
{
	protected $companyId = 0;
	protected $title = '';
	protected $stage = 'lead';
	protected $valueCents = 0;
	protected $products = null;
	protected $expectedClose = null;
	protected $stageChangedAt = null;
	protected $lostReason = null;
	protected $agreementId = null;
	protected $ownerUserId = null;
	protected $quoteSentOn = null;
	protected $quoteFileId = null;
	protected $forecastBucket = null;
	protected $lostReasonCode = null;
	protected $wonReasonCode = null;
	protected $leadFirstTouchAt = null;
	protected $leadSlaDueAt = null;
	protected $nextActionSkipUntil = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('valueCents', Types::BIGINT);
		$this->addType('agreementId', Types::BIGINT);
		$this->addType('quoteFileId', Types::BIGINT);
		$this->addType('expectedClose', Types::DATE);
		$this->addType('quoteSentOn', Types::DATE);
		$this->addType('nextActionSkipUntil', Types::DATE);
		$this->addType('stageChangedAt', Types::DATETIME);
		$this->addType('leadFirstTouchAt', Types::DATETIME);
		$this->addType('leadSlaDueAt', Types::DATETIME);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}
}
