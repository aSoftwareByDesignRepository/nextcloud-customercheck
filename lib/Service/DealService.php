<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Pipeline deals — PSD §4.4, §4.7-C, §14.2.
 */
class DealService
{
	public function __construct(
		private readonly DealMapper $dealMapper,
		private readonly CompanyService $companyService,
		private readonly StageSequenceService $stageSequences,
		private readonly TimelineService $timelineService,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	public function get(int $id): Deal
	{
		try {
			return $this->dealMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l10n->t('This deal no longer exists.'));
		}
	}

	/** @return Deal[] */
	public function search(array $filters = [], int $limit = 500, int $offset = 0): array
	{
		return $this->dealMapper->search($filters, $limit, $offset);
	}

	public function create(array $data, string $actor): Deal
	{
		$companyId = (int)($data['companyId'] ?? 0);
		$this->companyService->get($companyId);

		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a deal title.'));
		}

		$stage = (string)($data['stage'] ?? 'lead');
		if (!in_array($stage, Constants::DEAL_STAGES, true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'stage', $this->l10n->t('Unknown pipeline stage.'));
		}

		$now = $this->timeFactory->getDateTime();
		$deal = new Deal();
		$deal->setCompanyId($companyId);
		$deal->setTitle($title);
		$deal->setStage($stage);
		$deal->setValueCents(max(0, (int)($data['valueCents'] ?? 0)));
		$deal->setProducts(isset($data['products']) ? trim((string)$data['products']) : null);
		$close = DateMath::parseStrictDate((string)($data['expectedClose'] ?? ''));
		$deal->setExpectedClose($close !== null ? new \DateTime($close->format('Y-m-d')) : null);
		$owner = trim((string)($data['ownerUserId'] ?? $actor));
		$deal->setOwnerUserId($owner !== '' ? $owner : null);
		$bucket = isset($data['forecastBucket']) ? trim((string)$data['forecastBucket']) : null;
		if ($bucket !== null && $bucket !== '' && !in_array($bucket, Constants::FORECAST_BUCKETS, true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'forecastBucket', $this->l10n->t('Unknown forecast bucket.'));
		}
		$deal->setForecastBucket($bucket !== '' ? $bucket : null);
		if ($stage === 'lead') {
			$slaHours = max(1, (int)($data['leadSlaHours'] ?? Constants::LEAD_SLA_HOURS_DEFAULT));
			$deal->setLeadSlaDueAt((clone $now)->modify('+' . $slaHours . ' hours'));
		}
		$deal->setStageChangedAt($now);
		$deal->setCreatedBy($actor);
		$deal->setCreatedAt($now);
		$deal->setUpdatedAt($now);

		$deal = $this->dealMapper->insert($deal);
		$fired = $this->stageSequences->fireForStage($deal, $stage, $actor);
		$this->timelineService->append($companyId, 'deal.stage', $actor, [
			'dealId' => (int)$deal->getId(),
			'stage' => $stage,
			'sequencesFired' => $fired,
		]);

		return $deal;
	}

	public function update(int $id, array $data, string $actor): Deal
	{
		$deal = $this->get($id);
		OptimisticLock::assertFresh($deal->getUpdatedAt(), isset($data['updatedAt']) ? (string)$data['updatedAt'] : null);

		if (isset($data['title'])) {
			$title = trim((string)$data['title']);
			if ($title === '') {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a deal title.'));
			}
			$deal->setTitle($title);
		}
		if (array_key_exists('valueCents', $data)) {
			$deal->setValueCents(max(0, (int)$data['valueCents']));
		}
		if (array_key_exists('products', $data)) {
			$deal->setProducts($data['products'] === null ? null : trim((string)$data['products']));
		}
		if (array_key_exists('expectedClose', $data)) {
			$close = DateMath::parseStrictDate((string)($data['expectedClose'] ?? ''));
			$deal->setExpectedClose($close !== null ? new \DateTime($close->format('Y-m-d')) : null);
		}
		if (array_key_exists('agreementId', $data)) {
			$deal->setAgreementId($data['agreementId'] === null ? null : (int)$data['agreementId']);
		}
		if (array_key_exists('ownerUserId', $data)) {
			$owner = trim((string)($data['ownerUserId'] ?? ''));
			$deal->setOwnerUserId($owner !== '' ? $owner : null);
		}
		if (array_key_exists('forecastBucket', $data)) {
			$bucket = $data['forecastBucket'] === null ? null : trim((string)$data['forecastBucket']);
			if ($bucket !== null && $bucket !== '' && !in_array($bucket, Constants::FORECAST_BUCKETS, true)) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'forecastBucket', $this->l10n->t('Unknown forecast bucket.'));
			}
			$deal->setForecastBucket($bucket !== '' ? $bucket : null);
		}
		if (array_key_exists('quoteSentOn', $data)) {
			$qs = DateMath::parseStrictDate((string)($data['quoteSentOn'] ?? ''));
			$deal->setQuoteSentOn($qs !== null ? new \DateTime($qs->format('Y-m-d')) : null);
		}
		if (array_key_exists('quoteFileId', $data)) {
			$deal->setQuoteFileId($data['quoteFileId'] === null ? null : (int)$data['quoteFileId']);
		}
		if (array_key_exists('nextActionSkipUntil', $data)) {
			$skip = DateMath::parseStrictDate((string)($data['nextActionSkipUntil'] ?? ''));
			$skipDt = $skip !== null ? new \DateTime($skip->format('Y-m-d')) : null;
			if (!ChaseLogic::isSkipAllowed($skipDt, $this->timeFactory->getDateTime())) {
				throw ValidationException::field(
					Constants::ERR_SKIP_TOO_FAR,
					'nextActionSkipUntil',
					$this->l10n->t('Skip date cannot be more than 7 days ahead.'),
				);
			}
			$deal->setNextActionSkipUntil($skipDt);
		}
		if (array_key_exists('leadFirstTouchAt', $data) && $data['leadFirstTouchAt']) {
			$deal->setLeadFirstTouchAt($this->timeFactory->getDateTime());
		}

		$deal->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->dealMapper->update($deal);
	}

	/**
	 * @param array<string, mixed> $extra lostReasonCode, wonReasonCode, lostReason detail
	 * @return array{deal: Deal, sequencesFired: list<string>}
	 */
	public function changeStage(int $id, string $stage, ?string $lostReason, ?string $updatedAt, string $actor, array $extra = []): array
	{
		$deal = $this->get($id);
		OptimisticLock::assertFresh($deal->getUpdatedAt(), $updatedAt);

		$current = (string)$deal->getStage();
		if (in_array($current, Constants::DEAL_TERMINAL_STAGES, true) && $stage !== $current) {
			throw ValidationException::field(
				Constants::ERR_DEAL_TERMINAL,
				'stage',
				$this->l10n->t('Won and lost deals cannot be reopened. Create a new deal instead.'),
			);
		}
		if (!in_array($stage, Constants::DEAL_STAGES, true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'stage', $this->l10n->t('Unknown pipeline stage.'));
		}
		if ($stage === 'lost') {
			$code = trim((string)($extra['lostReasonCode'] ?? ''));
			$detail = trim((string)($extra['lostReason'] ?? $lostReason ?? ''));
			// Free-text alone is never enough — always require a known loss code (§11.6 / §18.7).
			if ($code === '' && $detail !== '' && in_array($detail, Constants::LOSS_REASON_CODES, true)) {
				$code = $detail;
				$detail = '';
			}
			if ($code === '' || !in_array($code, Constants::LOSS_REASON_CODES, true)) {
				throw ValidationException::field(
					Constants::ERR_LOSS_CODE_REQUIRED,
					'lostReasonCode',
					$this->l10n->t('Pick a loss reason code.'),
				);
			}
			$deal->setLostReasonCode($code);
			if ($code === 'other' && $detail === '') {
				throw ValidationException::field(
					Constants::ERR_LOST_REASON_REQUIRED,
					'lostReason',
					$this->l10n->t('Explain why the deal was lost.'),
				);
			}
			$deal->setLostReason($detail !== '' ? $detail : $code);
		}
		if ($stage === 'won' && isset($extra['wonReasonCode'])) {
			$w = trim((string)$extra['wonReasonCode']);
			$deal->setWonReasonCode($w !== '' ? $w : null);
		}

		$now = $this->timeFactory->getDateTime();
		$deal->setStage($stage);
		$deal->setStageChangedAt($now);
		$deal->setUpdatedAt($now);
		$deal = $this->dealMapper->update($deal);

		$fired = $this->stageSequences->fireForStage($deal, $stage, $actor);
		$this->timelineService->append((int)$deal->getCompanyId(), 'deal.stage', $actor, [
			'dealId' => (int)$deal->getId(),
			'from' => $current,
			'to' => $stage,
			'sequencesFired' => $fired,
		]);

		return ['deal' => $deal, 'sequencesFired' => $fired];
	}

	public function staleDealDays(): int
	{
		$raw = $this->config->getAppValue(
			\OCA\CustomerCheck\AppInfo\Application::APP_ID,
			Constants::CONFIG_STALE_DEAL_DAYS,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_STALE_DEAL_DAYS],
		);
		$days = (int)$raw;
		return $days > 0 ? $days : 14;
	}

	public function isStale(Deal $deal, ?\DateTimeInterface $now = null): bool
	{
		if (in_array((string)$deal->getStage(), Constants::DEAL_TERMINAL_STAGES, true)) {
			return false;
		}
		$changed = $deal->getStageChangedAt();
		if ($changed === null) {
			return false;
		}
		$now = $now ?? $this->timeFactory->getDateTime();
		return DateMath::daysBetween($changed, $now) > $this->staleDealDays();
	}

	/** @return array<string, mixed> */
	public function toArray(Deal $deal): array
	{
		$today = $this->timeFactory->getDateTime();
		return [
			'id' => (int)$deal->getId(),
			'title' => (string)$deal->getTitle(),
			'stage' => (string)$deal->getStage(),
			'companyId' => (int)$deal->getCompanyId(),
			'valueCents' => (int)$deal->getValueCents(),
			'products' => $deal->getProducts(),
			'expectedClose' => $deal->getExpectedClose()?->format('Y-m-d'),
			'stageChangedAt' => OptimisticLock::format($deal->getStageChangedAt()),
			'lostReason' => $deal->getLostReason(),
			'lostReasonCode' => $deal->getLostReasonCode(),
			'wonReasonCode' => $deal->getWonReasonCode(),
			'agreementId' => $deal->getAgreementId(),
			'ownerUserId' => $deal->getOwnerUserId(),
			'quoteSentOn' => $deal->getQuoteSentOn()?->format('Y-m-d'),
			'quoteAgeDays' => ChaseLogic::quoteAgeDays($deal->getQuoteSentOn(), $today),
			'quoteFileId' => $deal->getQuoteFileId(),
			'forecastBucket' => $deal->getForecastBucket(),
			'leadFirstTouchAt' => OptimisticLock::format($deal->getLeadFirstTouchAt()),
			'leadSlaDueAt' => OptimisticLock::format($deal->getLeadSlaDueAt()),
			'nextActionSkipUntil' => $deal->getNextActionSkipUntil()?->format('Y-m-d'),
			'stale' => $this->isStale($deal),
			'updatedAt' => OptimisticLock::format($deal->getUpdatedAt()),
		];
	}
}
