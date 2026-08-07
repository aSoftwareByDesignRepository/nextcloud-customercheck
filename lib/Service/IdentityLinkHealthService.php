<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Classify PC/TC soft-link health for 360 + Identity Health (MH-04/05/11).
 *
 * States: not_linked | linked | broken | sibling_unavailable
 */
class IdentityLinkHealthService
{
	public const STATE_NOT_LINKED = 'not_linked';
	public const STATE_LINKED = 'linked';
	public const STATE_BROKEN = 'broken';
	public const STATE_SIBLING_UNAVAILABLE = 'sibling_unavailable';

	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly FacadeProbeService $facades,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}

	public function renamePushEnabled(): bool
	{
		$raw = $this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_IDENTITY_RENAME_PUSH,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_IDENTITY_RENAME_PUSH],
		);

		return $raw === '1' || $raw === 'true' || $raw === true;
	}

	/**
	 * @return array{
	 *   pc: array{state:string,pcCustomerId:?int,label:string,deepLink:?string},
	 *   tc: array{state:string,tcCustomerId:?int,label:string,deepLink:?string}
	 * }
	 */
	public function classifyCompany(Company $company): array
	{
		$pcId = (int)($company->getPcCustomerId() ?? 0);
		$tcId = (int)($company->getTcCustomerId() ?? 0);

		return [
			'pc' => $this->classifyPc($pcId > 0 ? $pcId : null),
			'tc' => $this->classifyTc($tcId > 0 ? $tcId : null),
		];
	}

	/**
	 * @return array{state:string,pcCustomerId:?int,label:string,deepLink:?string}
	 */
	public function classifyPc(?int $pcCustomerId): array
	{
		if ($pcCustomerId === null || $pcCustomerId <= 0) {
			$available = $this->facades->projectcheckAvailable();

			return [
				'state' => $available ? self::STATE_NOT_LINKED : self::STATE_SIBLING_UNAVAILABLE,
				'pcCustomerId' => null,
				'label' => $available
					? $this->l10n->t('Not linked to ProjectCheck')
					: $this->l10n->t('ProjectCheck is not available on this instance.'),
				'deepLink' => null,
			];
		}

		if (!$this->facades->projectcheckAvailable()) {
			return [
				'state' => self::STATE_SIBLING_UNAVAILABLE,
				'pcCustomerId' => $pcCustomerId,
				'label' => $this->l10n->t('Linked ProjectCheck customer cannot be verified (app unavailable).'),
				'deepLink' => null,
			];
		}

		$dto = $this->facades->getProjectCheckCustomer($pcCustomerId);
		if ($dto === null) {
			return [
				'state' => self::STATE_BROKEN,
				'pcCustomerId' => $pcCustomerId,
				'label' => $this->l10n->t('Broken link: ProjectCheck customer #%d was deleted or is missing.', [$pcCustomerId]),
				'deepLink' => null,
			];
		}

		$name = trim((string)($dto['name'] ?? $dto['displayName'] ?? ''));

		return [
			'state' => self::STATE_LINKED,
			'pcCustomerId' => $pcCustomerId,
			'label' => $name !== ''
				? $this->l10n->t('Linked to ProjectCheck: %s', [$name])
				: $this->l10n->t('Linked to ProjectCheck'),
			'deepLink' => $this->facades->projectCheckCustomerDeepLink($pcCustomerId),
		];
	}

	/**
	 * @return array{state:string,tcCustomerId:?int,label:string,deepLink:?string}
	 */
	public function classifyTc(?int $tcCustomerId): array
	{
		if ($tcCustomerId === null || $tcCustomerId <= 0) {
			$available = $this->facades->ticketcheckAvailable();

			return [
				'state' => $available ? self::STATE_NOT_LINKED : self::STATE_SIBLING_UNAVAILABLE,
				'tcCustomerId' => null,
				'label' => $available
					? $this->l10n->t('Not linked to TicketCheck')
					: $this->l10n->t('TicketCheck is not available on this instance.'),
				'deepLink' => null,
			];
		}

		if (!$this->facades->ticketcheckAvailable()) {
			return [
				'state' => self::STATE_SIBLING_UNAVAILABLE,
				'tcCustomerId' => $tcCustomerId,
				'label' => $this->l10n->t('Linked TicketCheck customer cannot be verified (app unavailable).'),
				'deepLink' => null,
			];
		}

		$dto = $this->facades->getTicketCheckCustomer($tcCustomerId);
		if ($dto === null) {
			return [
				'state' => self::STATE_BROKEN,
				'tcCustomerId' => $tcCustomerId,
				'label' => $this->l10n->t('Broken link: TicketCheck customer #%d was deleted or is missing.', [$tcCustomerId]),
				'deepLink' => null,
			];
		}

		$name = trim((string)($dto['name'] ?? $dto['displayName'] ?? ''));

		return [
			'state' => self::STATE_LINKED,
			'tcCustomerId' => $tcCustomerId,
			'label' => $name !== ''
				? $this->l10n->t('Linked to TicketCheck: %s', [$name])
				: $this->l10n->t('Linked to TicketCheck'),
			'deepLink' => $this->facades->ticketCheckCustomerDeepLink($tcCustomerId),
		];
	}

	/**
	 * Admin KPI counts — no PII payloads (AC-C-13).
	 *
	 * @return array{
	 *   pcLinked:int,pcUnlinked:int,pcBroken:int,
	 *   tcLinked:int,tcUnlinked:int,tcBroken:int,
	 *   mnLinkedPc:int,mnLinkedCrm:int,mnUnlinked:int
	 * }
	 */
	public function adminHealthCounts(): array
	{
		$pcIds = $this->companyMapper->listLinkedPcCustomerIds();
		$tcIds = $this->companyMapper->listLinkedTcCustomerIds();
		$total = $this->companyMapper->countSearch(['archived' => false]);

		$pcLinkedLive = 0;
		$pcBroken = 0;
		if ($this->facades->projectcheckAvailable()) {
			$existing = $this->facades->projectCheckCustomerIdSet(max(2000, count($pcIds) + 100));
			foreach ($pcIds as $id) {
				if (isset($existing[$id])) {
					$pcLinkedLive++;
				} else {
					$pcBroken++;
				}
			}
		} else {
			// Sibling down: treat stored links as linked-but-unverifiable (not broken).
			$pcLinkedLive = count($pcIds);
		}

		$tcLinkedLive = 0;
		$tcBroken = 0;
		if ($this->facades->ticketcheckAvailable()) {
			$existing = $this->facades->ticketCheckCustomerIdSet(max(2000, count($tcIds) + 100));
			foreach ($tcIds as $id) {
				if (isset($existing[$id])) {
					$tcLinkedLive++;
				} else {
					$tcBroken++;
				}
			}
		} else {
			$tcLinkedLive = count($tcIds);
		}

		$pcUnlinked = max(0, $total - count($pcIds));
		$tcUnlinked = max(0, $total - count($tcIds));

		$mn = $this->facades->maintenanceIdentityCounts();

		return [
			'pcLinked' => $pcLinkedLive,
			'pcUnlinked' => $pcUnlinked,
			'pcBroken' => $pcBroken,
			'tcLinked' => $tcLinkedLive,
			'tcUnlinked' => $tcUnlinked,
			'tcBroken' => $tcBroken,
			'mnLinkedPc' => (int)($mn['mnLinkedPc'] ?? 0),
			'mnLinkedCrm' => (int)($mn['mnLinkedCrm'] ?? 0),
			'mnUnlinked' => (int)($mn['mnUnlinked'] ?? 0),
		];
	}
}
