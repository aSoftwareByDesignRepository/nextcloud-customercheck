<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Integration\SiblingFacadeContracts;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Capability-gated sibling probes — PSD §5.3.
 * Never throws into a page render; degraded panels show availability notes.
 * Never reads sibling tables directly (§5.1) — facades only.
 */
class FacadeProbeService
{
	/** @var array<string, bool> */
	private array $warned = [];

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly ?IURLGenerator $urlGenerator = null,
	) {
	}

	private function warnOnce(string $key, string $message, \Throwable $e): void
	{
		if (isset($this->warned[$key])) {
			return;
		}
		$this->warned[$key] = true;
		$this->logger->warning($message, ['exception' => $e]);
	}

	private function facadeUsable(string $class, int $minVersion): bool
	{
		if (!class_exists($class)) {
			return false;
		}
		$version = defined($class . '::FACADE_VERSION') ? (int)constant($class . '::FACADE_VERSION') : 0;
		return $version >= $minVersion;
	}

	/**
	 * @return array{available: bool, usable: bool, reason: ?string}
	 */
	private function probeApp(string $appId, string $facadeClass, int $minVersion): array
	{
		if (!$this->appManager->isEnabledForUser($appId)) {
			return ['available' => false, 'usable' => false, 'reason' => 'disabled'];
		}
		if (!class_exists($facadeClass)) {
			return ['available' => true, 'usable' => false, 'reason' => 'facade_missing'];
		}
		if (!$this->facadeUsable($facadeClass, $minVersion)) {
			return ['available' => true, 'usable' => false, 'reason' => 'too_old'];
		}
		return ['available' => true, 'usable' => true, 'reason' => null];
	}

	public function sbdlicenseopsAvailable(): bool
	{
		return $this->probeApp(
			SiblingFacadeContracts::SBD_LICENSE_OPS_APP,
			SiblingFacadeContracts::SBD_LICENSE_OPS_FACADE,
			SiblingFacadeContracts::SBD_LICENSE_OPS_MIN_VERSION,
		)['usable'];
	}

	public function invoicecheckAvailable(): bool
	{
		return $this->probeApp(
			SiblingFacadeContracts::INVOICE_CHECK_APP,
			SiblingFacadeContracts::INVOICE_CHECK_FACADE,
			SiblingFacadeContracts::INVOICE_CHECK_MIN_VERSION,
		)['usable'];
	}

	public function projectcheckAvailable(): bool
	{
		return $this->probeApp(
			SiblingFacadeContracts::PROJECT_CHECK_APP,
			SiblingFacadeContracts::PROJECT_CHECK_FACADE,
			SiblingFacadeContracts::PROJECT_CHECK_MIN_VERSION,
		)['usable'];
	}

	public function ticketcheckAvailable(): bool
	{
		return $this->probeApp(
			SiblingFacadeContracts::TICKET_CHECK_APP,
			SiblingFacadeContracts::TICKET_CHECK_FACADE,
			SiblingFacadeContracts::TICKET_CHECK_MIN_VERSION,
		)['usable'];
	}

	public function projectcheckWriteAvailable(): bool
	{
		return $this->probeApp(
			SiblingFacadeContracts::PROJECT_CHECK_APP,
			SiblingFacadeContracts::PROJECT_CHECK_WRITE_FACADE,
			SiblingFacadeContracts::PROJECT_CHECK_WRITE_MIN_VERSION,
		)['usable'];
	}

	public function ticketcheckWriteAvailable(): bool
	{
		return $this->probeApp(
			SiblingFacadeContracts::TICKET_CHECK_APP,
			SiblingFacadeContracts::TICKET_CHECK_WRITE_FACADE,
			SiblingFacadeContracts::TICKET_CHECK_WRITE_MIN_VERSION,
		)['usable'];
	}

	public function mailAvailable(): bool
	{
		return $this->appManager->isEnabledForUser('mail');
	}

	/**
	 * Truth table for Settings / 360 panels (§5.3).
	 * Boolean keys stay for template BC; `*_detail` carries reason codes.
	 *
	 * @return array<string, bool|array{available:bool,usable:bool,reason:?string}>
	 */
	public function capabilityMatrix(): array
	{
		$sbd = $this->probeApp(
			SiblingFacadeContracts::SBD_LICENSE_OPS_APP,
			SiblingFacadeContracts::SBD_LICENSE_OPS_FACADE,
			SiblingFacadeContracts::SBD_LICENSE_OPS_MIN_VERSION,
		);
		$inv = $this->probeApp(
			SiblingFacadeContracts::INVOICE_CHECK_APP,
			SiblingFacadeContracts::INVOICE_CHECK_FACADE,
			SiblingFacadeContracts::INVOICE_CHECK_MIN_VERSION,
		);
		$pc = $this->probeApp(
			SiblingFacadeContracts::PROJECT_CHECK_APP,
			SiblingFacadeContracts::PROJECT_CHECK_FACADE,
			SiblingFacadeContracts::PROJECT_CHECK_MIN_VERSION,
		);
		$tc = $this->probeApp(
			SiblingFacadeContracts::TICKET_CHECK_APP,
			SiblingFacadeContracts::TICKET_CHECK_FACADE,
			SiblingFacadeContracts::TICKET_CHECK_MIN_VERSION,
		);
		$pcWrite = $this->probeApp(
			SiblingFacadeContracts::PROJECT_CHECK_APP,
			SiblingFacadeContracts::PROJECT_CHECK_WRITE_FACADE,
			SiblingFacadeContracts::PROJECT_CHECK_WRITE_MIN_VERSION,
		);
		$tcWrite = $this->probeApp(
			SiblingFacadeContracts::TICKET_CHECK_APP,
			SiblingFacadeContracts::TICKET_CHECK_WRITE_FACADE,
			SiblingFacadeContracts::TICKET_CHECK_WRITE_MIN_VERSION,
		);
		$mailOn = $this->mailAvailable();

		return [
			'sbdlicenseops' => $sbd['usable'],
			'invoicecheck' => $inv['usable'],
			'projectcheck' => $pc['usable'],
			'ticketcheck' => $tc['usable'],
			'projectcheckWrite' => $pcWrite['usable'],
			'ticketcheckWrite' => $tcWrite['usable'],
			'mail' => $mailOn,
			'sbdlicenseops_detail' => $sbd,
			'invoicecheck_detail' => $inv,
			'projectcheck_detail' => $pc,
			'ticketcheck_detail' => $tc,
			'projectcheckWrite_detail' => $pcWrite,
			'ticketcheckWrite_detail' => $tcWrite,
			'mail_detail' => [
				'available' => $mailOn,
				'usable' => $mailOn,
				'reason' => $mailOn ? null : 'disabled',
			],
		];
	}

	/**
	 * @return list<array{id:int,subject:string,companyId:?int,customerId?:int,deepLink:?string}>
	 */
	public function listWaitingTickets(int $limit = 50): array
	{
		if (!$this->ticketcheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::TICKET_CHECK_FACADE);
			$result = $facade->listWaitingTickets($limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('ticket_waiting', 'CustomerCheck TicketCheck facade failed', $e);
			return [];
		}
	}

	/**
	 * Open / waiting tickets for one TicketCheck customer id (company 360 panel).
	 *
	 * @return list<array{id:int,subject:string,status?:string,deepLink:?string}>
	 */
	public function listOpenTicketsForCustomer(int $tcCustomerId, int $limit = 50): array
	{
		if ($tcCustomerId <= 0 || !$this->ticketcheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::TICKET_CHECK_FACADE);
			$result = $facade->listOpenTickets($tcCustomerId, $limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('ticket_open', 'CustomerCheck TicketCheck open-tickets facade failed', $e);
			return [];
		}
	}

	/** @return list<array<string,mixed>> */
	public function listSalesLogRows(int $limit = 500): array
	{
		if (!$this->sbdlicenseopsAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::SBD_LICENSE_OPS_FACADE);
			$result = $facade->listRecent($limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('sales_log', 'CustomerCheck sales-log facade failed', $e);
			return [];
		}
	}

	/** @return list<array<string,mixed>> */
	public function listProjectCheckCustomers(int $limit = 500): array
	{
		if (!$this->projectcheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::PROJECT_CHECK_FACADE);
			$result = $facade->listCustomers($limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('project_customers', 'CustomerCheck ProjectCheck facade failed', $e);
			return [];
		}
	}

	/**
	 * @return list<array{id:int,name:string,status:string,openHours:float}>
	 */
	public function listProjectsForCustomer(int $pcCustomerId, int $limit = 200): array
	{
		if ($pcCustomerId <= 0 || !$this->projectcheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::PROJECT_CHECK_FACADE);
			$result = $facade->listProjects($pcCustomerId, $limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('project_list', 'CustomerCheck ProjectCheck projects facade failed', $e);
			return [];
		}
	}

	/** @return list<array<string,mixed>> */
	public function listTicketCheckCustomers(int $limit = 500): array
	{
		if (!$this->ticketcheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::TICKET_CHECK_FACADE);
			$result = method_exists($facade, 'listCustomers') ? $facade->listCustomers($limit) : [];
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('ticket_customers', 'CustomerCheck TicketCheck customer facade failed', $e);
			return [];
		}
	}

	/**
	 * @return list<array{icInvoiceId:?int,invoiceRef:?string,paidTotalCents:int,status:string,pcCustomerId?:int|null}>
	 */
	public function listPaidInvoiceSignals(int $limit = 500): array
	{
		if (!$this->invoicecheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::INVOICE_CHECK_FACADE);
			$result = $facade->listRecentlyPaid($limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('invoice_paid', 'CustomerCheck invoice facade failed', $e);
			return [];
		}
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listInvoicesForPcCustomer(int $pcCustomerId, int $limit = 200): array
	{
		if ($pcCustomerId <= 0 || !$this->invoicecheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::INVOICE_CHECK_FACADE);
			$result = $facade->listByPcCustomer($pcCustomerId, $limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('invoice_list', 'CustomerCheck invoice list facade failed', $e);
			return [];
		}
	}

	/**
	 * Overdue receivables from InvoicingCheck (Money overdue bucket).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listOverdueInvoices(int $limit = 200): array
	{
		if (!$this->invoicecheckAvailable()) {
			return [];
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::INVOICE_CHECK_FACADE);
			if (!method_exists($facade, 'listOverdue')) {
				return [];
			}
			$result = $facade->listOverdue($limit);
			return is_array($result) ? $result : [];
		} catch (\Throwable $e) {
			$this->warnOnce('invoice_overdue', 'CustomerCheck invoice overdue facade failed', $e);
			return [];
		}
	}

	/**
	 * Preferred: probe Mail searchable API. Fallback returns [] so callers
	 * show deep-link buttons (§14.8). Never stores message bodies.
	 *
	 * @param list<string> $emails
	 * @return list<array{direction:string,fromAddr:string,toAddr:string,subject:string,occurredAt:?string,deepLink:?string}>
	 */
	public function searchMailMessages(array $emails, int $limit = 50): array
	{
		if ($emails === [] || !$this->mailAvailable()) {
			return [];
		}
		try {
			// Nextcloud Mail does not expose a stable cross-version search API for
			// arbitrary from:/to: filters. Return empty so MailRelatedService
			// degrades to deep-link buttons (still PSD-compliant §14.8 Fallback).
			if (interface_exists('OCA\\Mail\\Contracts\\IMailManager')
				|| class_exists('OCA\\Mail\\Service\\Search\\MailSearch')
			) {
				// Keep hook point for future Mail API; do not invent private calls.
				return [];
			}
			return [];
		} catch (\Throwable $e) {
			$this->warnOnce('mail_related', 'CustomerCheck Mail related search failed', $e);
			return [];
		}
	}

	/**
	 * Honest open billable minutes across linked PC customers.
	 *
	 * Requires CrmCustomerReadFacade FACADE_VERSION ≥ 2 (settlement openHours).
	 * Older stub facades (hardcoded openHours=0.0) → known=false / honesty_blocked.
	 *
	 * @param list<int> $pcCustomerIds Linked ProjectCheck customer ids for this workspace
	 * @return array{known: bool, minutes: int}|null null when ProjectCheck is not available
	 */
	public function honestOpenBillableMinutes(array $pcCustomerIds = []): ?array
	{
		if (!$this->projectcheckAvailable()) {
			return null;
		}
		try {
			$facade = \OC::$server->get(SiblingFacadeContracts::PROJECT_CHECK_FACADE);
			if (!is_object($facade) || !method_exists($facade, 'sumOpenBillableMinutes')) {
				return ['known' => false, 'minutes' => 0];
			}

			$version = 1;
			$ref = new \ReflectionClass($facade);
			if ($ref->hasConstant('FACADE_VERSION')) {
				$version = (int)$ref->getConstant('FACADE_VERSION');
			}
			// v1 hardcoded openHours=0.0 — never treat as truth.
			if ($version < 2) {
				return ['known' => false, 'minutes' => 0];
			}

			$ids = [];
			foreach ($pcCustomerIds as $rawId) {
				$id = (int)$rawId;
				if ($id > 0) {
					$ids[$id] = $id;
				}
			}
			$ids = array_values($ids);

			// No linked PC customers → zero open billable is an honest empty book.
			if ($ids === []) {
				return ['known' => true, 'minutes' => 0];
			}

			$minutes = 0;
			foreach ($ids as $id) {
				$minutes += max(0, (int)$facade->sumOpenBillableMinutes($id));
			}
			return ['known' => true, 'minutes' => max(0, $minutes)];
		} catch (\Throwable $e) {
			$this->warnOnce('pc_billable', 'CustomerCheck ProjectCheck billable probe failed', $e);
			return ['known' => false, 'minutes' => 0];
		}
	}

	/**
	 * Compose URL for Mail app when available; otherwise null (caller uses mailto).
	 */
	public function mailComposeUrl(string $to, string $subject, string $body): ?string
	{
		if (!$this->mailAvailable() || $this->urlGenerator === null) {
			return null;
		}
		try {
			$base = $this->urlGenerator->linkToRouteAbsolute('mail.home.index');
			$query = http_build_query([
				'to' => $to,
				'subject' => $subject,
				'body' => $body,
			], '', '&', PHP_QUERY_RFC3986);
			return $base . '#compose?' . $query;
		} catch (\Throwable $e) {
			$this->warnOnce('mail_compose', 'CustomerCheck Mail compose URL failed', $e);
			return null;
		}
	}
}
