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

		$deal->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->dealMapper->update($deal);
	}

	/**
	 * @return array{deal: Deal, sequencesFired: list<string>}
	 */
	public function changeStage(int $id, string $stage, ?string $lostReason, ?string $updatedAt, string $actor): array
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
			$reason = trim((string)($lostReason ?? ''));
			if ($reason === '') {
				throw ValidationException::field(
					Constants::ERR_LOST_REASON_REQUIRED,
					'lostReason',
					$this->l10n->t('Explain why the deal was lost.'),
				);
			}
			$deal->setLostReason($reason);
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

	/** @return array{id:int,title:string,stage:string,companyId:int,valueCents:int,products:?string,expectedClose:?string,stageChangedAt:?string,lostReason:?string,agreementId:?int,stale:bool,updatedAt:?string} */
	public function toArray(Deal $deal): array
	{
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
			'agreementId' => $deal->getAgreementId(),
			'stale' => $this->isStale($deal),
			'updatedAt' => OptimisticLock::format($deal->getUpdatedAt()),
		];
	}
}
