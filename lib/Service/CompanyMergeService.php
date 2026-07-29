<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\TimelineEventMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Company merge (CUC M1 S3 / WP-S1-CUC-MERGE).
 *
 * Moves children from source → keeper inside one transaction, archives the
 * source, and writes audit event {@see Constants::EVENT_COMPANY_MERGED} on the
 * keeper. Never cascades into ProjectCheck / InvoicingCheck / TicketCheck.
 *
 * Lock order: lower company id first (deadlock avoidance), then child tables
 * by the documented company → deal → agreement → activity/hours → event order.
 */
class CompanyMergeService
{
	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly PersonMapper $personMapper,
		private readonly AgreementMapper $agreementMapper,
		private readonly DealMapper $dealMapper,
		private readonly ActivityMapper $activityMapper,
		private readonly EmailTouchMapper $emailTouchMapper,
		private readonly TimelineEventMapper $timelineEventMapper,
		private readonly TimelineService $timeline,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Merge source company into keeper.
	 *
	 * @return array{
	 *   keeperId: int,
	 *   sourceId: int,
	 *   sourceSlug: string,
	 *   moved: array{
	 *     persons: int,
	 *     agreements: int,
	 *     deals: int,
	 *     activities: int,
	 *     emailTouches: int,
	 *     timelineEvents: int
	 *   },
	 *   company: Company
	 * }
	 *
	 * @throws NotFoundException
	 * @throws ValidationException
	 * @throws \OCA\CustomerCheck\Exception\ConflictException
	 */
	public function merge(int $sourceId, int $intoCompanyId, string $actor, ?string $sourceUpdatedAt, ?string $intoUpdatedAt = null): array
	{
		if ($sourceId <= 0 || $intoCompanyId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'intoCompanyId',
				$this->l10n->t('Choose a company to merge into.'),
			);
		}
		if ($sourceId === $intoCompanyId) {
			throw ValidationException::field(
				Constants::ERR_MERGE_SAME,
				'intoCompanyId',
				$this->l10n->t('A company cannot be merged into itself.'),
			);
		}

		$firstId = min($sourceId, $intoCompanyId);
		$secondId = max($sourceId, $intoCompanyId);

		$this->db->beginTransaction();
		try {
			if (!$this->companyMapper->lockRow($firstId) || !$this->companyMapper->lockRow($secondId)) {
				throw new NotFoundException(
					$this->l10n->t('This company no longer exists.'),
					Constants::ERR_COMPANY_NOT_FOUND,
				);
			}

			$source = $this->companyMapper->findById($sourceId);
			$keeper = $this->companyMapper->findById($intoCompanyId);

			OptimisticLock::assertFresh($source->getUpdatedAt(), $sourceUpdatedAt);
			// Keeper lock is mandatory: merge against a stale keeper races with
			// concurrent edits (E7) and can silently overwrite sibling links.
			OptimisticLock::assertFresh($keeper->getUpdatedAt(), $intoUpdatedAt);

			if ($source->isArchived()) {
				throw ValidationException::field(
					Constants::ERR_MERGE_SOURCE_ARCHIVED,
					'id',
					$this->l10n->t('Archived companies cannot be merged. Restore the source first.'),
				);
			}
			if ($keeper->isArchived()) {
				throw ValidationException::field(
					Constants::ERR_MERGE_TARGET_ARCHIVED,
					'intoCompanyId',
					$this->l10n->t('The merge target is archived. Restore it or pick another company.'),
				);
			}

			$this->assertSiblingLinksCompatible($source, $keeper);

			// Prefer keeper's primary; demote source primaries before move.
			if ($this->personMapper->findPrimary($intoCompanyId) !== null) {
				$this->personMapper->clearPrimaryFor($sourceId);
			}

			$moved = [
				'persons' => $this->personMapper->reassignCompany($sourceId, $intoCompanyId),
				'agreements' => $this->agreementMapper->reassignCompany($sourceId, $intoCompanyId),
				'deals' => $this->dealMapper->reassignCompany($sourceId, $intoCompanyId),
				'activities' => $this->activityMapper->reassignCompany($sourceId, $intoCompanyId),
				'emailTouches' => $this->emailTouchMapper->reassignCompany($sourceId, $intoCompanyId),
				'timelineEvents' => $this->timelineEventMapper->reassignCompany($sourceId, $intoCompanyId),
			];
			// Hours / remlog / seqlog / pay_watch follow agreements & deals via FK.

			$this->transferSiblingLinks($source, $keeper);

			$now = $this->timeFactory->getDateTime();
			$sourceSlug = (string)$source->getSlug();
			$sourceName = (string)$source->getName();

			$source->setArchivedAt($now);
			$source->setUpdatedAt($now);
			$this->companyMapper->update($source);

			$keeper->setUpdatedAt($now);
			$keeper = $this->companyMapper->update($keeper);

			/** @var array<string, mixed> $mergePayload */
			$mergePayload = [
				'sourceId' => $sourceId,
				'sourceSlug' => $sourceSlug,
				'sourceName' => $sourceName,
				'moved' => $moved,
			];
			$this->timeline->append($intoCompanyId, Constants::EVENT_COMPANY_MERGED, (string)$actor, $mergePayload);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return [
			'keeperId' => $intoCompanyId,
			'sourceId' => $sourceId,
			'sourceSlug' => $sourceSlug,
			'moved' => $moved,
			'company' => $keeper,
		];
	}

	/**
	 * @throws ValidationException
	 */
	private function assertSiblingLinksCompatible(Company $source, Company $keeper): void
	{
		$srcPc = $source->getPcCustomerId();
		$dstPc = $keeper->getPcCustomerId();
		if ($srcPc !== null && $dstPc !== null && (int)$srcPc !== (int)$dstPc) {
			throw ValidationException::field(
				Constants::ERR_MERGE_LINK_CONFLICT,
				'pcCustomerId',
				$this->l10n->t('Both companies link to different ProjectCheck customers. Unlink one before merging.'),
			);
		}

		$srcTc = $source->getTcCustomerId();
		$dstTc = $keeper->getTcCustomerId();
		if ($srcTc !== null && $dstTc !== null && (int)$srcTc !== (int)$dstTc) {
			throw ValidationException::field(
				Constants::ERR_MERGE_LINK_CONFLICT,
				'tcCustomerId',
				$this->l10n->t('Both companies link to different TicketCheck customers. Unlink one before merging.'),
			);
		}
	}

	private function transferSiblingLinks(Company $source, Company $keeper): void
	{
		if ($keeper->getPcCustomerId() === null && $source->getPcCustomerId() !== null) {
			$keeper->setPcCustomerId((int)$source->getPcCustomerId());
		}
		if ($keeper->getTcCustomerId() === null && $source->getTcCustomerId() !== null) {
			$keeper->setTcCustomerId((int)$source->getTcCustomerId());
		}
		// Clear source links so UNIQUE/collision probes never see two active rows.
		$source->setPcCustomerId(null);
		$source->setTcCustomerId(null);
	}
}
