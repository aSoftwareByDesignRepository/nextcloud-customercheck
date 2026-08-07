<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;

/**
 * TicketCheck waiting tickets always send companyId=null + customerId (TC id).
 * Resolve to a CRM company before workspace ACL filters (§8 / §18.10).
 */
final class TicketCompanyLink
{
	/**
	 * @param array<string, mixed> $ticket
	 * @param array<int, string> $companyNames cache filled on resolve
	 */
	public static function resolveCompanyId(array $ticket, CompanyMapper $companies, array &$companyNames): int
	{
		$companyId = isset($ticket['companyId']) ? (int)$ticket['companyId'] : 0;
		if ($companyId > 0) {
			return $companyId;
		}
		$tcCustomerId = (int)($ticket['customerId'] ?? 0);
		if ($tcCustomerId <= 0) {
			return 0;
		}
		$linked = $companies->findByTcCustomerId($tcCustomerId);
		if ($linked === null) {
			return 0;
		}
		$companyId = (int)$linked->getId();
		$companyNames[$companyId] = (string)$linked->getName();

		return $companyId;
	}

	/**
	 * @param list<Company> $workspaceCompanies
	 * @return array<int, int> tcCustomerId → companyId
	 */
	public static function tcIndexForWorkspace(array $workspaceCompanies): array
	{
		$index = [];
		foreach ($workspaceCompanies as $c) {
			$tc = (int)($c->getTcCustomerId() ?? 0);
			if ($tc > 0) {
				$index[$tc] = (int)$c->getId();
			}
		}

		return $index;
	}

	/**
	 * @param array<string, mixed> $ticket
	 * @param array<int, int> $tcIndex tcCustomerId → companyId
	 * @param array<int, true> $companyIdsInWorkspace
	 */
	public static function belongsToWorkspace(array $ticket, array $tcIndex, array $companyIdsInWorkspace): bool
	{
		$companyId = isset($ticket['companyId']) ? (int)$ticket['companyId'] : 0;
		if ($companyId > 0) {
			return isset($companyIdsInWorkspace[$companyId]);
		}
		$tcCustomerId = (int)($ticket['customerId'] ?? 0);
		if ($tcCustomerId <= 0) {
			return false;
		}

		return isset($tcIndex[$tcCustomerId]);
	}
}
