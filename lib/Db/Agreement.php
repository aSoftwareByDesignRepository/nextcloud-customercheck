<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCompanyId()
 * @method void setCompanyId(int $companyId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getProductCode()
 * @method void setProductCode(string $productCode)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string|null getLimitsJson()
 * @method void setLimitsJson(?string $limitsJson)
 * @method \DateTime|null getStartsAt()
 * @method void setStartsAt(?\DateTime $startsAt)
 * @method \DateTime|null getValidUntil()
 * @method void setValidUntil(?\DateTime $validUntil)
 * @method int getAmountCents()
 * @method void setAmountCents(int $amountCents)
 * @method string|null getInvoiceRef()
 * @method void setInvoiceRef(?string $invoiceRef)
 * @method string getInvoiceStatus()
 * @method void setInvoiceStatus(string $invoiceStatus)
 * @method int|null getIcInvoiceId()
 * @method void setIcInvoiceId(?int $icInvoiceId)
 * @method int|null getSalesLogId()
 * @method void setSalesLogId(?int $salesLogId)
 * @method string|null getKeyFingerprint()
 * @method void setKeyFingerprint(?string $keyFingerprint)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int|null getSupersededBy()
 * @method void setSupersededBy(?int $supersededBy)
 * @method string|null getCancelReason()
 * @method void setCancelReason(?string $cancelReason)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(?\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 */
class Agreement extends Entity
{
	protected $companyId = 0;
	protected $kind = 'license';
	protected $productCode = 'custom';
	protected $label = '';
	protected $limitsJson = '{}';
	protected $startsAt = null;
	protected $validUntil = null;
	protected $amountCents = 0;
	protected $invoiceRef = null;
	protected $invoiceStatus = 'none';
	protected $icInvoiceId = null;
	protected $salesLogId = null;
	protected $keyFingerprint = null;
	protected $status = 'draft';
	protected $supersededBy = null;
	protected $cancelReason = null;
	protected $notes = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct()
	{
		$this->addType('id', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('amountCents', Types::BIGINT);
		$this->addType('icInvoiceId', Types::BIGINT);
		$this->addType('salesLogId', Types::BIGINT);
		$this->addType('supersededBy', Types::BIGINT);
		$this->addType('startsAt', Types::DATE);
		$this->addType('validUntil', Types::DATE);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	/**
	 * Decoded `limits_json`, always an array (never null) so callers can index safely.
	 *
	 * @return array<string, mixed>
	 */
	public function decodedLimits(): array
	{
		$decoded = json_decode((string)($this->limitsJson ?? '{}'), true);
		return is_array($decoded) ? $decoded : [];
	}
}
