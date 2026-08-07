# CustomerCheck ↔ Suite GDPR erase (AC-L7)

Full operator runbook (G10):  
`planning/check-productivity-suite/SUITE-GDPR-ERASE-RUNBOOK.md` in the monorepo.

## Non-cascade rule (this app)

`CompanyService::gdprErase` and `CompanyMergeService::merge` only touch `crm_*` tables.  
They must **never** delete ProjectCheck (`pc_*`), InvoiceCheck (`ic_*`), TicketCheck, MaintenanceCheck, or InventoryCheck rows.

Sibling ledgers keep their history; export the CRM company before erase.
