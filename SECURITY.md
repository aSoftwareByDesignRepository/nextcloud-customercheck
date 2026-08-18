# CustomerCheck — Security & incident response (shared mail)

This runbook covers the shared-mailbox surface (index, plain-text preview cache, SMTP send).

## Roles

| Role | Who | Duties |
|---|---|---|
| **Workspace mailbox admin** | Members who can save/edit mailboxes in Settings → Team & mailbox | Rotate IMAP/SMTP password; disable broken mailbox; confirm DSGVO ack |
| **Nextcloud instance admin** | Server admins (`admin` group / hoster) | Revoke sessions; rotate instance secrets / `secret` in `config.php`; restore from backup |
| **Data controller** | Organisation that operates the Nextcloud instance | Legal basis, DPIA, breach notification to authorities/subjects |

Vendor Software-by-Design is **not** the IR owner for self-hosted installs. Contact: https://nextcloud.software-by-design.de/ · security reports via the app store listing / GitHub Security Advisories when published.

## Suspected shared-mailbox secret leak (Q2 closed)

**SLA:** Rotate the shared IMAP/SMTP password within **1 hour** of credible suspicion.

1. **Contain**
   - Settings → Team & mailbox → open the mailbox → set a new password → Test connection → Save (re-ticks DSGVO ack).
   - If the UI is unavailable: disable the mailbox row / set status error via DB only as last resort, then fix via UI.
2. **Revoke sessions** (instance admin): Users → logout / wipe sessions for operators who may have been compromised.
3. **Audit**
   - Search Nextcloud logs for `CustomerCheck mail preview fetched`, `mail preview IMAP failed`, `rate`.
   - Note actors, workspace IDs, message IDs (bodies are not logged).
4. **Notify**
   - Controller applies GDPR Art. 33/34 clocks when personal data in cached previews may have leaked.
5. **Post-incident**
   - Confirm retention purge still runs; optionally shorten `mail_retention_days` temporarily.

## Preview cache (Q1 closed — summary)

- **What:** Optional ≤50 KiB plain-text snapshot in `crm_mail_msg.preview` after “Show message”.
- **Why / legal basis:** Art. 6(1)(f) legitimate interest (team triage of a workplace shared inbox) and/or Art. 6(1)(b) contract performance; mandatory DSGVO ack on mailbox save documents operator acceptance.
- **Retention:** Workspace `mail_retention_days` (floor 30); purge deletes index rows including `preview`. IMAP originals unchanged.

## Side-effectful preview API

`POST /api/v1/workspaces/{workspaceId}/mail/{id}/preview` only (CSRF `requesttoken`). GET is not registered.
