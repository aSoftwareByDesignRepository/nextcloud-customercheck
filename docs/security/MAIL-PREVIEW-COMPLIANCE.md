# Mail preview — GDPR / compliance decisions (Q1 & Q2)

> **Status:** Closed for product compliance sign-off (CustomerCheck 1.6.66+)  
> **Companion audits:** `ARGUS-AUDIT-MAIL-PREVIEW-2026-08-12.md`, `ZEUS-AUDIT-MAIL-PREVIEW-2026-08-12.md`  
> **IR runbook:** `../SECURITY.md` (repo path: `SECURITY.md`)

---

## Q1 — EU legal basis for cached plain-text previews

### Decision

CustomerCheck may cache a **capped plain-text preview** (`crm_mail_msg.preview`, ≤ `MAIL_PREVIEW_MAX_BYTES`) of messages from a **workspace shared mailbox** after an authenticated operator with `mail.read` requests “Show message”.

**Legal bases (controller = Nextcloud instance operator):**

1. **Art. 6(1)(f) GDPR — legitimate interests:** enabling a defined team to triage workplace shared-inbox mail inside CRM without storing full MIME/attachments.
2. **Art. 6(1)(b) GDPR — contract:** where the shared mailbox is used to perform or support contracts with customers (B2B correspondence).

**Not a legal basis:** consent of every external sender (impractical for shared inboxes). Operators must not connect **personal** mailboxes — enforced by mandatory DSGVO acknowledgement on mailbox save.

### Minimization & retention

| Control | Implementation |
|---|---|
| Purpose limitation | Shared-team triage only; not a personal mail archive |
| Data minimization | Plain text only; no HTML render; no attachments; ≤50 KiB |
| Access | Workspace `mail.read`; feed lists never embed body (`preview: null`) |
| Retention | `mail_retention_days` ≥ 30; `MailRetentionPurgeJob` deletes index + preview |
| Erasure | Company GDPR erase blanks mail index fields including preview |
| Transparency | Mailbox save checkbox states colleagues may see messages **and** cached short previews |

### Residency

CustomerCheck does not choose the DB region. **Data residency = where the operator hosts Nextcloud.** Operators processing EU residents’ data must host (or contract hosters) accordingly and maintain a DPA with the IMAP provider (AS-06).

### Answer to Argus Q1

> Production CustomerCheck **may** process EU personal data in shared-mailbox index + cached previews when the operator’s deployment and mailbox content include EU data subjects. Legal basis is **documented above** and acknowledged at mailbox save. Retention/erasure are implemented in-app; residency remains an **operator hosting** duty.

---

## Q2 — Incident-response ownership for mailbox secret rotation

### Decision

| Priority | Owner | Action window |
|---|---|---|
| P0 — rotate IMAP/SMTP password | Workspace mailbox admin (Settings → Team & mailbox) | **≤ 1 hour** after credible suspicion |
| P0 — revoke NC sessions / instance secrets | Nextcloud instance admin | Same incident window |
| Legal notify | Data controller (operating org) | GDPR 72h where applicable |

**Detection inputs:** structured logs `CustomerCheck mail preview fetched` / `IMAP failed` / rate-limit validation errors; mailbox `auth_fail_count` / status `error`.

Full steps: `SECURITY.md` → “Suspected shared-mailbox secret leak”.

### Answer to Argus Q2

> On-call for secret rotation is the **workspace mailbox admin** first, escalated to the **instance admin**. Vendor is not the IR owner for self-hosted installs. SLA for password rotation: **1 hour**.

---

## Detection / verification (for auditors)

| Claim | Proof |
|---|---|
| Preview is POST (CSRF) | `appinfo/routes.php` verb `POST` for `persona_api#previewMail`; UI uses `apiPost` / `Api.post` |
| No GET preview route | Contract tests forbid GET preview registration |
| DSGVO ack mentions cache | `templates/settings/workspace-mailbox.php` checkbox copy |
| IR runbook exists | `SECURITY.md` |
| This decision record | This file |
