# Identity support runbook — “two Acme GmbH”

**Audience:** Support / admins  
**Related:** SHARED-IDENTITY-COMPLETION-SPEC MH-14 · FIELD-DUAL-CUSTOMER.md

## What “two customers” usually means

| Situation | Meaning | Fix |
|-----------|---------|-----|
| Same name in CustomerCheck and ProjectCheck, **not linked** | Two masters by choice or missed CTA | On CRM 360 → **Create in ProjectCheck** or **Link existing** |
| Same name in MaintenanceCheck and CRM/PC | Field register vs commercial hub (allowed) | Optional soft-link on MN customer; never silent merge |
| CRM shows **Broken link** | `pc_customer_id` / `tc_customer_id` points at deleted sibling | **Unlink**, then link another or create |
| Import from ProjectCheck created a CRM twin **without** link | Pre-1.6.17 import or advanced “create unlinked” | Edit company → pick ProjectCheck customer, or re-import with link default |

## Diagnosis steps

1. Open the CRM company 360 → section **Link apps (setup)** (zone 4).
2. Read the badge: Linked / Not linked / Broken link / Unavailable (text, not colour alone).
3. Admin KPI: `GET /apps/customercheck/api/v1/admin/identity-health` (app admin) → `pcUnlinked` / `pcBroken` counts.
4. ProjectCheck customer page → **CustomerCheck** panel shows reverse deep link when CRM owns the soft link.

## Safe actions

- **Unlink** clears the CRM (or MN) soft-link id only — never deletes the sibling ledger.
- **Merge** in CustomerCheck when two CRM companies share one commercial party (never auto-merge by name).
- Rollback flags: app config `identity_rename_push=0` (CUC), `mn_soft_link_ui=0` (MN) — columns stay, UX hides.

## GDPR

Erase CRM company → clears `pc_customer_id` / `tc_customer_id`. Erase MN customer → clears soft links. Neither cascades into PC/IC invoices or sibling masters.

## Helpful links

- Suite overview: https://nextcloud.software-by-design.de/
- Sponsors: https://github.com/sponsors/aSoftwareByDesignRepository
