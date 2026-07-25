<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Integration;

/**
 * Versioned sibling facade contracts — PSD §5.2.
 *
 * CRM never writes into sibling apps. Runtime probing lives in
 * {@see \OCA\CustomerCheck\Service\FacadeProbeService}; these constants are the
 * single source of truth for required facade versions.
 */
final class SiblingFacadeContracts
{
	public const SBD_LICENSE_OPS_APP = 'sbdlicenseops';
	public const SBD_LICENSE_OPS_FACADE = 'OCA\\SbdLicenseOps\\Facade\\SalesLogReadFacade';
	public const SBD_LICENSE_OPS_MIN_VERSION = 1;

	public const INVOICE_CHECK_APP = 'invoicecheck';
	public const INVOICE_CHECK_FACADE = 'OCA\\InvoiceCheck\\Facade\\CrmInvoiceReadFacade';
	public const INVOICE_CHECK_MIN_VERSION = 1;

	public const PROJECT_CHECK_APP = 'projectcheck';
	public const PROJECT_CHECK_FACADE = 'OCA\\ProjectCheck\\Facade\\CrmCustomerReadFacade';
	public const PROJECT_CHECK_MIN_VERSION = 1;

	/** TicketCheck PHP namespace is Ticketcheck (lowercase c). */
	public const TICKET_CHECK_APP = 'ticketcheck';
	public const TICKET_CHECK_FACADE = 'OCA\\Ticketcheck\\Facade\\CrmHelpdeskReadFacade';
	public const TICKET_CHECK_MIN_VERSION = 1;

	private function __construct()
	{
	}
}
