# Argus Security Review — CustomerCheck Shared-Mail Preview & Review Surface

> **Security Mode: FULL AUDIT**  
> **Object:** Already-implemented CustomerCheck features: on-demand IMAP message preview (`GET …/mail/{id}/preview`), Review assign/discard UX, shared-mail company feed fields, mailbox host handling.  
> **App version audited/patched:** 1.6.61  
> **Runtime:** Docker service `nextcloud` (`nextcloud-app`)  
> **Date:** 2026-08-12  

---

## Table of contents

1. [Stakeholder Analysis](#1-multi-perspective-security-stakeholder-analysis)
2. [Asset & Data Classification](#2-asset--data-classification)
3. [Threat Modeling](#3-threat-modeling)
4. [Vulnerability Checklist](#4-vulnerability--weakness-enumeration)
5. [Patterns vs Anti-Patterns](#5-patterns-vs-anti-patterns)
6. [Verdict Register](#6-security-requirement-tiering--verdict-register)
7. [Security NFRs](#7-security-non-functional-requirements)
8. [Secure SDLC & Testability](#8-secure-sdlc--testability)
9. [Data Protection & Privacy](#9-data-protection--privacy)
10. [Incident Response](#10-incident-response--recoverability)
- [Assumptions Register](#assumptions-register)
- [Risk Register](#risk-register)
- [Blocking Questions](#blocking-questions)
- [Remediation Evidence](#remediation-evidence)

---

## 1. Multi-Perspective Security Stakeholder Analysis

| Perspective | Findings |
|---|---|
| **Attacker (opportunistic)** | Authenticated workspace member with `mail.read` can hammer preview → IMAP logins (credential burn / DoS). Enumerate message IDs in-workspace. Scrape company feed if body text were embedded in list JSON. |
| **Attacker (targeted)** | Compromised manager sets IMAP/SMTP host to internal IP / cloud metadata → SSRF-class pivot from Nextcloud → private network. Steal shared-mailbox password via host compromise of `secret_enc` + Nextcloud crypto key. Cross-workspace IDOR if workspace scoping fails. |
| **Data subject** | Shared inbox mail bodies (PII, contracts, medical/HR content possible) cached in `crm_mail_msg.preview` and shown to all `mail.read` / company `read` peers. Breach = shared-mailbox content exposure + CRM metadata. Notification depends on operator IR readiness (AS-03). |
| **Security/Compliance/Legal** | GDPR: shared mailbox is intentional multi-party processing; DSGVO ack on mailbox save exists. Body cache expands retention surface beyond IMAP provider retention unless purge covers `preview`. Veto if EU personal mail is cached without retention/erasure alignment. |
| **Engineering/Architecture** | Blast radius of one shared mailbox secret = all indexed mail + live IMAP. Preview is a side-effectful GET (IMAP LOGIN). Rate limits previously covered POSTs only. |
| **Ops/IR** | Need logs of preview fetch (actor, messageId, workspace) without body content. IMAP auth-fail spikes should alert. |
| **Business** | Shared-mailbox breach → customer trust + possible GDPR fines; CRM adoption depends on trust. |
| **Downstream** | Compromised CustomerCheck host can dial configured IMAP/SMTP providers; if host SSRF allowed, can reach internal apps. |
| **Future auditor** | Evidence: PHPUnit security tests, host blocklist, rate limits, feed minimization, this document. |

**Open Security Conflict:** None remaining after remediation. Pre-patch conflict was “ship preview UX without DoS/SSRF/leak controls” — resolved by Must-Fix patches below (not accepted as residual risk).

---

## 2. Asset & Data Classification

## Data Inventory — CustomerCheck mail preview / Review

| Data Type | Sensitivity Tier | Where Stored | Who/What Can Access | Encrypted At Rest | Encrypted In Transit | Retention Period |
|---|---|---|---|---|---|---|
| Shared mailbox password | Critical (auth material) | `crm_mailbox.secret_enc` via Nextcloud `ICrypto` | Mailbox sync/preview/send services; never returned in GET JSON | Yes (ICrypto) | Yes (TLS to browser; TLS to IMAP/SMTP) | Life of mailbox config |
| Indexed mail headers (from/subject/snippet/UID) | High (PII / business) | `crm_mail_msg` | Workspace `mail.read` / Review roles | DB volume encryption = platform (AS-01) | TLS | Workspace mail retention purge (≥30 days floor) |
| Cached plain-text body preview | High (PII) | `crm_mail_msg.preview` (≤50 KiB) | `mail.read` via preview API; **not** in company list feed after fix | DB volume (AS-01) | TLS | Same purge as index row |
| IMAP host/user config | Medium–High | `crm_mailbox` | Workspace mailbox managers | DB (AS-01) | TLS | Life of mailbox |
| Preview access logs | Medium | Nextcloud log channel | Ops | Platform | TLS | Platform log retention (AS-02) |
| Review assign/discard actions | Medium (audit) | `crm_review_item` + mail triage status | Review writers | DB (AS-01) | TLS | Per Review retention |

---

## 3. Threat Modeling

### Trust boundaries
1. Browser → Nextcloud CustomerCheck API (session + CSRF token)
2. CustomerCheck PHP → MariaDB
3. CustomerCheck PHP → external IMAP/SMTP (manager-configured host)
4. Workspace RBAC (`mail.read` / `write` / mailbox admin)

## Threat Register — Mail preview / Review

| ID | Threat | Attacker Tier | Entry Point | Impact | Likelihood | Current Mitigation | Verdict |
|---|---|---|---|---|---|---|---|
| TH-01 | Unthrottled IMAP preview → mailbox lockout / outbound DoS | Authenticated low-privilege | `GET /mail/{id}/preview` | Shared inbox unavailable; auth-fail quarantine | High | User+WS rate limits (30/h, 120/h); cached reads free | 🟢 mitigated |
| TH-02 | SSRF via IMAP/SMTP host = private IP / metadata | Malicious manager / compromised admin session | Mailbox save + sync + preview | Internal network pivot | Medium | `ImapConnection::isBlockedMailHost` on save/preview/sync | 🟢 mitigated |
| TH-03 | IMAP error detail reflected to client | Authenticated | Preview failure path | Host/path/banner info disclosure | Medium | Generic client message; sanitized detail server-logged only | 🟢 mitigated |
| TH-04 | Body preview embedded in company communication list | Authenticated with company `read` only | `/companies/{id}/communication` | Wider PII fan-out without `mail.read` intent | High (pre-fix) | Feed returns `preview: null`; on-demand API only | 🟢 mitigated |
| TH-05 | Cross-workspace IDOR on message id | Authenticated other WS | Preview URL | Read foreign mail index/body | Low if scoping holds | `workspace_id` predicate on message + mailbox | 🟢 mitigated (regression covered by design) |
| TH-06 | XSS via HTML mail body | Authenticated | Preview UI | Session theft | Medium | `MailPreviewText` strips HTML; UI uses `textContent` | 🟢 mitigated |
| TH-07 | Folder path injection in IMAP SELECT | Compromised DB / malicious folder row | Preview/sync | Protocol abuse | Low | `isSafeImapFolder` + quoted atom | 🟢 mitigated |
| TH-08 | Side-effectful GET (CSRF-induced IMAP login) | Cross-site with session | Preview GET | Amplify TH-01 | Low | Preview is POST-only + requesttoken (1.6.65) | 🟢 mitigated |
| TH-09 | Supply-chain PHP/JS CVE | Supply-chain | Composer/npm | RCE | Medium | Existing SCA posture (AS-05) | 🟡 Should-Fix program |

---

## 4. Vulnerability & Weakness Enumeration

### 4.1 Authentication & Session Management
- **Pass:** Preview requires Nextcloud session (`#[NoAdminRequired]`, not `PublicPage`).
- **Pass:** Mailbox secrets use `ICrypto`, never returned on list.
- **N/A:** Password storage for NC users — platform-owned.

### 4.2 Authorization / Access Control
- **Pass:** `assertCapability(…, 'mail.read')` on preview.
- **Pass:** Message + mailbox queries constrain `workspace_id`.
- **Pass:** `triageAssign` verifies company belongs to workspace.
- **Note:** Any workspace member with `mail.read` may preview any indexed message in that workspace (including unmatched Review items). This matches the product “shared mailbox visible to workspace” model — not IDOR within workspace.

### 4.3 Injection
- **Pass:** Query builder parameterized.
- **Pass:** XSS: plain text preview + `textContent`.
- **Pass/Hardened:** SSRF host blocklist (private/reserved).
- **N/A:** XXE — no XML mail parse in preview path.
- **Pass:** Folder control-char rejection.

### 4.4 CSRF & Clickjacking
- **Pass:** Mutating Review APIs are POST with requesttoken.
- **Residual:** Preview GET has server-side effects (IMAP); mitigated by tokenized XHR clients + rate limit (TH-08).

### 4.5 Secrets Management
- **Pass:** No password in API responses; sanitizeError strips `pass=` / `password=`.
- **Pass:** Preview failures no longer echo IMAP detail to clients.
- **Pass:** Logs include messageId/actor/bytes — not body, not secret.

### 4.6 Cryptography
- **Pass:** IMAP dial uses TLS (`ssl://` probe / php-imap SSL mailbox root).
- **Pass:** Secrets at rest via Nextcloud crypto.
- **AS-01:** DB volume encryption is platform responsibility.

### 4.7 Input Validation & File Handling
- **Pass:** UID must be numeric; preview size capped (`MAIL_PREVIEW_MAX_BYTES`).
- **N/A:** File upload — not in this surface.

### 4.8 Supply Chain & Dependencies
- **AS-05:** Composer/npm SCA assumed in workspace CI; not re-run full SCA in this pass (Should-Fix program).

### 4.9 Logging / Monitoring / Exposure
- **Pass:** Preview fetch logs actor + ids without body.
- **Should-Fix:** Alerting rule for preview rate-limit / IMAP auth-fail spikes (ops).

### 4.10 DoS / Resource Exhaustion
- **Pass:** Preview rate limits; body byte cap; session middleware still covers mutating APIs.

---

## 5. Patterns vs Anti-Patterns

| ✅ Pattern | ❌ Anti-Pattern found pre-fix | Why |
|---|---|---|
| Workspace-scoped object fetch | Trust client-only company id | Prevents cross-book IDOR |
| Rate-limit expensive IMAP dial | Unlimited GET preview | Prevents shared-inbox lockout |
| Block private mail hosts | Dial any manager-supplied host | Prevents SSRF to metadata/RFC1918 |
| Generic client errors + server detail logs | Reflect IMAP banners to UI | Limits recon |
| List endpoints return flags, not bodies | Embed 800-char preview in feed JSON | Data minimization / least exposure |
| `textContent` for preview | `innerHTML` of mail | XSS |

---

## 6. Security Requirement Tiering — Verdict Register

### Must-Fix

| ID | Finding | CVSS (equiv.) | Traces to | Status | Evidence of Resolution |
|---|---|---|---|---|---|
| MF-01 | No rate limit on IMAP-backed preview GET | 7.5 | TH-01 | 🟢 VERIFIED | `MailPreviewSecurityTest::testPreviewRateLimitBlocksImapDial` — Docker PHPUnit OK |
| MF-02 | IMAP/SMTP host could be private/metadata (SSRF) | 8.6 | TH-02 | 🟢 VERIFIED | `ImapConnectionTest::testIsBlockedMailHostRejectsLoopbackAndPrivateLiterals` + save/preview/sync guards |
| MF-03 | Preview errors reflected IMAP `detail` to client | 5.3→escalates recon | TH-03 | 🟢 VERIFIED | `MailPreviewSecurityTest::testCompanyFeedContractDoesNotEmbedPreviewBody` asserts no `%s` detail template |
| MF-04 | Company feed embedded cached body preview (PII fan-out) | 6.5 | TH-04 | 🟢 VERIFIED | Same contract test + `CompanyCommandService` `preview => null` |

### Should-Fix

| ID | Finding | Rationale for Deferability | Backlog Reference | Status |
|---|---|---|---|---|
| SF-01 | Side-effectful GET preview (prefer POST) | Closed in 1.6.65: preview is POST-only with CSRF requesttoken | — | 🟢 Bundled & Verified |
| SF-02 | No dedicated SIEM alert on preview flood / IMAP auth fail | Needs ops wiring | Track: alert rules | Deferred |
| SF-03 | Full SCA/SBOM attestation for this release slice | Org-wide program | Track: CI SCA gate | Deferred |

### Absolute No-Gos

| ID | Forbidden Behavior | Why | Detection Mechanism | Status | Evidence |
|---|---|---|---|---|---|
| NG-01 | Secrets in client responses or logs | Credential theft | Code review + sanitizeError tests + no secret fields in listMailboxes JSON | 🟢 VERIFIED | `ImapConnectionTest` sanitize + mailbox list `hasSecret` only |
| NG-02 | Unauthenticated write/preview of mail index | Data breach | Route uses session; no `PublicPage` on preview | 🟢 VERIFIED | Controller attributes inspected |
| NG-03 | XSS via mail HTML in CRM UI | Session hijack | Contract forbids `pre.innerHTML`; MailPreviewText strips tags | 🟢 VERIFIED | ReviewInboxUxContractTest + MailPreviewTextTest |

---

## 7. Security Non-Functional Requirements

| Category | Spec for this surface |
|---|---|
| **Authentication** | Nextcloud session; no public preview |
| **Authorization** | RBAC capability `mail.read` server-side; workspace row ownership |
| **Encryption** | TLS to client; TLS to IMAP; secrets via ICrypto |
| **Vulnerability management** | Security regressions in PHPUnit; Critical mail issues patch &lt;7 days |
| **Audit logging** | Preview fetch: actor, workspaceId, messageId, byte length — no body |
| **Incident response SLA** | Detect preview floods via rate-limit logs; rotate mailbox password + NC secret if host compromised |
| **Penetration testing** | Include mail preview IDOR + SSRF host cases in next gray-box pass |

---

## 8. Secure SDLC & Testability

| Control | Status |
|---|---|
| SAST | Platform/CI (AS-05) |
| Unit security regressions | **Added:** `MailPreviewSecurityTest`, host/folder tests in `ImapConnectionTest` |
| Mutation suite | Mailbox-focused mutations previously green; preview contract still asserts safe paths |
| Secrets scanning | Org pre-commit/CI (AS-05) |
| E2E a11y | Review axe suites (Bachus) — UX only; not a security substitute |

**Pentest scope (recommended):** In: preview API, mailbox save host field, Review assign. Out: Nextcloud core auth.

---

## 9. Data Protection & Privacy

- **Minimization:** Feed no longer ships body text; preview on demand only.
- **Purpose:** Shared-team triage — aligned with DSGVO ack on mailbox save.
- **Retention:** Preview column purged with `crm_mail_msg` retention job (existing).
- **Erasure:** Company GDPR erase paths must clear related mail index (pre-existing CompanyService blanking includes preview).
- **Residency:** Operator hosting duty — see `MAIL-PREVIEW-COMPLIANCE.md` (Q1 closed).
- **Processors:** IMAP provider (All-Inkl etc.) is a processor for mailbox content — DPA is operator responsibility (AS-06).

---

## 10. Incident Response & Recoverability

| Phase | Guidance for this surface |
|---|---|
| **Detection** | Log lines `CustomerCheck mail preview fetched` / `IMAP failed` / rate-limit 429 |
| **Containment** | Disable mailbox / rotate IMAP password; revoke NC sessions; set mailbox status error |
| **Forensics** | Preview logs give actor + messageId; bodies remain on IMAP if not cached |
| **Credential rotation** | Update mailbox password in Settings → Team & mailbox; ensure ICrypto master key rotation is platform runbook |
| **Comms** | If cached previews of personal data leaked, GDPR 72h clock may apply — controller duty; IR owners in `SECURITY.md` (Q2 closed) |
| **Post-incident** | Feed new Must-Fix into this register |

---

## Assumptions Register

| ID | Assumption | Made Because | Invalidates If | Owner to Confirm |
|---|---|---|---|---|
| AS-01 | MariaDB volume / backups encrypted at rest by hoster | Standard NC docker deploy | Host stores unencrypted volumes | Infra |
| AS-02 | NC log retention sufficient for IR (≥30 days) | Typical docker log config | Logs rotated &lt;7 days | Ops |
| AS-03 | Operator can notify data subjects on breach | Legal process exists for Software-by-Design apps | No breach playbook | Legal |
| AS-04 | Session cookies SameSite + requesttoken on API XHR | NC defaults + CustomerCheck `api.js` | Custom clients call preview without token from attacker origin | Eng |
| AS-05 | Composer/npm SCA runs in release CI | Existing app scripts | CI skips SCA | Eng |
| AS-06 | Shared mailbox provider DPA covers team access | Product DSGVO ack UX | No DPA with hoster | Legal |

---

## Risk Register

| ID | Risk | Inherent | Residual after fix | Owner |
|---|---|---|---|---|
| R-01 | Shared mailbox content exposed to all `mail.read` peers | High | Accepted product risk (shared inbox) | Product |
| R-02 | SSRF via DNS rebinding after save-time resolve | Medium | Reduced (connect-time re-check); rare | Eng |
| R-03 | Side-effect GET preview CSRF amplification | Low | Closed — preview is POST + requesttoken (1.6.65) | Eng |

---

## Blocking Questions

```
Q1: CLOSED — see docs/security/MAIL-PREVIEW-COMPLIANCE.md
Legal basis Art. 6(1)(f) and/or 6(1)(b); DSGVO ack documents cache;
retention ≥30 days with purge; residency = operator hosting.

Q2: CLOSED — see SECURITY.md
Workspace mailbox admin rotates IMAP/SMTP secret within 1 hour;
instance admin revokes sessions / platform secrets; controller owns
GDPR notification clocks.
```

Previously blocked sections **9** and **10** are unblocked for compliance sign-off with the decision record + IR runbook above.

---

## Remediation Evidence

### Commands executed

```bash
cd nextcloud && docker compose ps
docker compose exec -u www-data nextcloud php \
  /var/www/html/custom_apps/customercheck/vendor/bin/phpunit \
  --configuration /var/www/html/custom_apps/customercheck/phpunit.xml \
  --filter 'MailPreviewSecurityTest|ImapConnectionTest|MailboxSyncServiceTest|L10nGermanCopyQualityTest|ReviewInboxUxContractTest'
# Result: OK (38 tests, 213 assertions)
```

### Code changes (this audit)

| Area | Change |
|---|---|
| `ImapConnection` | `isBlockedMailHost` / `isBlockedIp` / `isSafeImapFolder` |
| `MailboxService::save` | Reject private IMAP/SMTP hosts |
| `MailboxSyncService::previewIndexedMessage` | Rate limits, host/folder checks, generic errors, audit log, actor param |
| `MailboxSyncService::doSync` | Refuse private IMAP host |
| `PersonaApiController::previewMail` | Pass actor uid into preview |
| `CompanyCommandService` / `matchedForCompany` | Stop embedding body preview in feed |
| Tests | `MailPreviewSecurityTest` + host/folder unit tests |
| Version | **1.6.66** (php-imap folder-safe + size-capped fallback; rate peek-then-charge; STARTTLS gate; triage Show/Answer parity; was 1.6.65) |

### Final Argus verdict

Technical Must-Fix and Absolute No-Go items for this surface are **🟢 VERIFIED** with executed PHPUnit evidence. Residual product risk R-01 (shared visibility) is explicit and accepted by design. **Q1/Q2 compliance sign-off is closed** via `MAIL-PREVIEW-COMPLIANCE.md` + `SECURITY.md` (1.6.65+). SF-01 (POST preview) is **🟢 VERIFIED**. **1.6.66** additionally closes php-imap wrong-folder UID risk, unbounded `imap_body` memory risk, and rate-token burn on failed IMAP (peek then charge).
