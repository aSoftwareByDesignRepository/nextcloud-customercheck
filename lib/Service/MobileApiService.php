<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Support\OptimisticLock;

/**
 * Companion DTO composition (COMPANION-APP Appendix B).
 *
 * Pure mapping on top of the canonical web services — the mobile API never
 * grows its own business rules; the state machines, validation, and access
 * control stay in the shared service layer so web and mobile cannot diverge.
 */
class MobileApiService
{
	public function __construct(
		private readonly TodayService $todayService,
		private readonly CompanyService $companyService,
		private readonly ActivityService $activityService,
		private readonly AgreementMapper $agreementMapper,
		private readonly FacadeProbeService $facades,
	) {
	}

	/**
	 * Today queue mapped to the companion contract (Appendix B.1).
	 *
	 * @return array{items: list<array<string, mixed>>, meta: array{truncated: bool, total: int}}
	 */
	public function today(): array
	{
		$built = $this->todayService->build();
		$items = [];
		foreach ($built['items'] as $item) {
			$items[] = self::mapTodayItem($item);
		}

		return ['items' => $items, 'meta' => $built['meta']];
	}

	/**
	 * @param array<string, mixed> $item web Today item (TodayService::build shape)
	 * @return array<string, mixed> companion Today item (B.1)
	 */
	public static function mapTodayItem(array $item): array
	{
		$kind = (string)($item['kind'] ?? '');
		$companyName = (string)($item['companyName'] ?? '');
		$label = (string)($item['label'] ?? '');
		$dueDate = isset($item['dueDate']) ? (string)$item['dueDate'] : null;

		[$mobileKind, $urgency, $id, $deepLink] = match ($kind) {
			'agreement_expiry' => [
				'renewal',
				(!empty($item['inGrace']) || (int)($item['daysUntil'] ?? 99) <= 7) ? 'danger' : 'warning',
				sprintf('agr:%d:d%d', (int)($item['agreementId'] ?? 0), (int)($item['daysUntil'] ?? 0)),
				['type' => 'agreement', 'id' => (int)($item['agreementId'] ?? 0)],
			],
			'ticket_waiting' => [
				'ticket_waiting',
				'warning',
				sprintf('tkt:%s', (string)($item['ticketId'] ?? '0')),
				['type' => 'ticket', 'id' => (int)($item['ticketId'] ?? 0)],
			],
			'activity_overdue' => [
				'activity',
				'danger',
				sprintf('act:%d', (int)($item['activityId'] ?? 0)),
				['type' => 'activity', 'id' => (int)($item['activityId'] ?? 0)],
			],
			'deal_stale' => [
				'deal_stale',
				'info',
				sprintf('deal:%d', (int)($item['dealId'] ?? 0)),
				['type' => 'deal', 'id' => (int)($item['dealId'] ?? 0)],
			],
			default => [
				'activity',
				'info',
				sprintf('act:%d', (int)($item['activityId'] ?? 0)),
				['type' => 'activity', 'id' => (int)($item['activityId'] ?? 0)],
			],
		};

		return [
			'id' => $id,
			'kind' => $mobileKind,
			'urgency' => $urgency,
			'title' => $companyName !== '' ? ($companyName . ' — ' . $label) : $label,
			'subtitle' => $dueDate,
			'companyId' => isset($item['companyId']) && $item['companyId'] !== null ? (int)$item['companyId'] : null,
			'deepLink' => $deepLink,
		];
	}

	/**
	 * Company 360 lite (Appendix B.2).
	 *
	 * @return array<string, mixed>
	 */
	public function companyLite(Company $company, ?string $userId = null): array
	{
		$companyId = (int)$company->getId();
		$agreements = $this->agreementMapper->findByCompany($companyId);

		$nextExpiry = null;
		$unpaidCount = 0;
		foreach ($agreements as $agreement) {
			/** @var Agreement $agreement */
			$status = (string)$agreement->getStatus();
			if (in_array($status, \OCA\CustomerCheck\Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			$until = $agreement->getValidUntil()?->format('Y-m-d');
			if ($until !== null && ($nextExpiry === null || $until < $nextExpiry)) {
				$nextExpiry = $until;
			}
			if (in_array((string)$agreement->getInvoiceStatus(), ['sent', 'overdue'], true)) {
				$unpaidCount++;
			}
		}

		$openActivities = $userId !== null && $userId !== ''
			? $this->activityService->countForMobile($userId, ['companyId' => $companyId, 'openOnly' => true])
			: $this->activityService->count(['companyId' => $companyId, 'openOnly' => true]);

		$openTickets = 0;
		$waitingTickets = 0;
		$tcCustomerId = $company->getTcCustomerId();
		$ticketsAvailable = $this->facades->ticketcheckAvailable();
		if ($ticketsAvailable && $tcCustomerId !== null && (int)$tcCustomerId > 0) {
			$openTickets = count($this->facades->listOpenTicketsForCustomer((int)$tcCustomerId, 50));
			foreach ($this->facades->listWaitingTickets(50) as $ticket) {
				if ((int)($ticket['customerId'] ?? 0) === (int)$tcCustomerId) {
					$waitingTickets++;
				}
			}
		}

		return [
			'id' => $companyId,
			'name' => (string)$company->getName(),
			'slug' => (string)$company->getSlug(),
			'status' => (string)$company->getStatus(),
			'lang' => (string)$company->getLang(),
			'archived' => $company->isArchived(),
			'nextExpiry' => $nextExpiry,
			'openActivities' => $openActivities,
			'unpaidCount' => $unpaidCount,
			'openTickets' => $openTickets,
			'waitingTickets' => $waitingTickets,
			'capabilities' => [
				'projectcheck' => $this->facades->projectcheckAvailable(),
				'invoicecheck' => $this->facades->invoicecheckAvailable(),
				'ticketcheck' => $ticketsAvailable,
			],
			'updatedAt' => OptimisticLock::format($company->getUpdatedAt()),
		];
	}

	/**
	 * Normalise a companion activity body (B.3 `summary` / `dueOn`) to the web
	 * service contract (`title` / `dueDate`). Web field names stay accepted.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public static function normaliseActivityBody(array $body): array
	{
		if (!array_key_exists('title', $body) && array_key_exists('summary', $body)) {
			$body['title'] = $body['summary'];
		}
		if (!array_key_exists('dueDate', $body) && array_key_exists('dueOn', $body)) {
			$body['dueDate'] = $body['dueOn'];
		}
		unset($body['summary'], $body['dueOn']);

		return $body;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function serialiseActivity(Activity $a): array
	{
		return [
			'id' => (int)$a->getId(),
			'companyId' => (int)$a->getCompanyId(),
			'dealId' => $a->getDealId(),
			'agreementId' => $a->getAgreementId(),
			'type' => (string)$a->getType(),
			'title' => (string)$a->getTitle(),
			'details' => $a->getDetails(),
			'dueDate' => $a->getDueDate()?->format('Y-m-d'),
			'doneAt' => OptimisticLock::format($a->getDoneAt()),
			'origin' => (string)$a->getOrigin(),
			'updatedAt' => OptimisticLock::format($a->getUpdatedAt()),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function serialiseAgreement(Agreement $a): array
	{
		return [
			'id' => (int)$a->getId(),
			'companyId' => (int)$a->getCompanyId(),
			'kind' => (string)$a->getKind(),
			'productCode' => (string)$a->getProductCode(),
			'label' => (string)$a->getLabel(),
			'limits' => $a->decodedLimits(),
			'startsAt' => $a->getStartsAt()?->format('Y-m-d'),
			'validUntil' => $a->getValidUntil()?->format('Y-m-d'),
			'amountCents' => (int)$a->getAmountCents(),
			'invoiceStatus' => (string)$a->getInvoiceStatus(),
			'status' => (string)$a->getStatus(),
			'updatedAt' => OptimisticLock::format($a->getUpdatedAt()),
		];
	}

	/**
	 * @param callable(Deal): bool $isStale
	 * @return array<string, mixed>
	 */
	public static function serialiseDeal(Deal $deal, callable $isStale): array
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
			'stale' => $isStale($deal),
			'updatedAt' => OptimisticLock::format($deal->getUpdatedAt()),
		];
	}

	/**
	 * Serialise a company search hit (companies list — name, slug, status only).
	 *
	 * @return array<string, mixed>
	 */
	public static function serialiseCompanyRow(Company $company): array
	{
		return [
			'id' => (int)$company->getId(),
			'name' => (string)$company->getName(),
			'slug' => (string)$company->getSlug(),
			'status' => (string)$company->getStatus(),
			'archived' => $company->isArchived(),
		];
	}
}
