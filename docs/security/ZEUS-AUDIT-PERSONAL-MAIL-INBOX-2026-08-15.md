# Zeus Architecture Review — CustomerCheck Personal Mail & Sales Inbox Mutate

> **Review Mode: FULL AUDIT**  
> **Object:** Already-implemented personal mail + Sales Inbox / Assign delete & spam, outbound CRM index, person-remove → Assign return (CustomerCheck **1.6.135**).  
> **Runtime:** Docker `nextcloud` service  
> **Date:** 2026-08-15  

---

## Table of contents

1. [System Context & Component Map](#1-system-context--component-map)
2. [Architecture Decision Records](#2-architecture-decision-records)
3. [Failure Mode Register](#3-failure-mode-race-condition--deadlock-analysis)
4. [Security & Threat Model](#4-security--threat-model)
5. [Patterns vs Anti-Patterns](#5-patterns-vs-anti-patterns)
6. [NFR Audit](#6-non-functional-requirements-audit)
7. [Deployment & Ops](#7-deployment-rollback--operational-readiness)
8. [Verdict Register](#8-requirement-verdict-tiering)
9. [Data Contracts & State Model](#9-data-contracts-state-model--boundaries)
10. [Testability](#10-testability--validation-strategy)
- [Assumptions Register](#assumptions-register)
- [Risk Register](#risk-register)
- [Blocking Questions](#blocking-questions)
- [Remediation Evidence](#remediation-evidence)

---

## 1. System Context & Component Map

```
PersonaApiController / ReviewApiController / MobileMailController
        │
        ▼
MailboxSyncService ──► IMAP (ext-imap / LiveProbe) + crm_mbox_{id} lock
ReviewService ───────► crm_review_item (Assign SoT)
MailSendService ─────► SMTP + outbound CRM index (crm_mail_msg)
PersonalMailService ─► personal mailbox lifecycle
MailboxService ──────► crm_mail_msg / crm_mailbox helpers
RateLimitService ────► AppConfig counters
MailboxSyncJob ──────► TimedJob syncAll (900s)
```

| Component | SoT for | Trust boundary | Upstream | Downstream | Failure blast |
|---|---|---|---|---|---|
| MailboxSyncService | Live IMAP dial / preview / sync | Session + RBAC + owner for personal | Inbox/Assign mutate UX | IMAP, DB, locks | Per-mailbox busy |
| ReviewService | Assign rows | Session + review caps | Assign UI, person-remove | MailboxSync, MailboxService | Ghost Assign / reopen |
| MailSendService | Outbound send ledger + CRM copy | Session + send ACL | Compose | SMTP, DB, mbox lock | Lost CRM copy (SMTP ok) |
| crm_mail_msg | CRM mail index | Workspace scoped | Feeds, Assign, Talk | IMAP authoritative for live UIDs | Stale index |
| crm_review_item | Assign queue | Workspace scoped | Sales process | Mail fingerprints | Wrong triage |

**Open Architectural Conflict (pre-fix):** Inbox mutate lacked Assign-style compensate after IMAP success; `returnSharedMailMessagesToAssign` could reopen Assign for `remote_deleted` mail. **Resolved in 1.6.135.**

---

## 2. Architecture Decision Records

### ADR-401 — Shared `crm_mbox_{id}` exclusive lock for all live IMAP
- **Decision:** One lock key for sync, preview, delete, junk, folder refresh, outbound index.
- **Context:** Providers hate concurrent LOGIN; php-imap is process-local.
- **Alternatives:** Per-operation locks; connection pool; async worker only.
- **Consequences:** No classic deadlock; sync blocks UX (availability SPOF per mailbox).
- **Reversibility:** Two-way (worker queue later).
- **Zeus:** ENDORSED WITH CONDITIONS — keep single key; never invent `crm_mail_del_*`.

### ADR-402 — CRM-only for synthetic `outbound:` UIDs
- **Decision:** `isLiveUid` = digits only; non-live → soft CRM success without IMAP.
- **Alternatives:** Always fail; invent Sent-folder UID mapping.
- **Consequences:** Assign/Inbox must still close CRM; ownership still required (Argus MF-01).
- **Zeus:** ENDORSED.

### ADR-403 — IMAP then CRM for Assign discard/spam; Inbox parity (1.6.135)
- **Decision:** Dial first; on CRM failure after dial, best-effort `markRemoteDeleted`.
- **Alternatives:** DB-first (hides spam on host); 2PC (unavailable).
- **Consequences:** Retry heals; ghost reconcile capped.
- **Zeus:** ENDORSED — Inbox now matches Assign.

### ADR-404 — Outbound index never fails the send
- **Decision:** After SMTP ACK, index best-effort; brief lock retry then unlocked index + warn.
- **Alternatives:** Fail send if index fails; deferred job only.
- **Consequences:** Possible unlocked write under contention (logged).
- **Zeus:** ENDORSED WITH CONDITIONS — SF residual for job-based index.

---

## 3. Failure Mode, Race Condition & Deadlock Analysis

## Failure Mode Register — Personal mail / Sales Inbox

| ID | Failure Mode | Trigger | Blast | Mitigation | Verdict |
|---|---|---|---|---|---|
| FM-01 | Inbox IMAP OK, CRM mark/close fails → live CRM + open Assign | DB error after dial | Ghost triage; user retry | `finishInboxRemoteMutate` compensate | 🟢 mitigated |
| FM-02 | Person-remove reopens Assign for remote_deleted mail | returnSharedMail without gate | Spam/deleted mail returns | `isRemoteDeleted` skip | 🟢 mitigated |
| FM-03 | Delete/sync/preview deadlock | Nested different lock keys | Hang | Single `crm_mbox_*` | 🟢 none found |
| FM-04 | Outbound index under sync contention | mbox lock busy | Duplicate/missed CRM row | 3× retry + unlocked warn | 🟢 mitigated / SF residual |
| FM-05 | Long sync starves delete/preview | Exclusive lock held | Busy errors | Soft lock status on sync | 🟠 SHOULD-FIX ops |
| FM-06 | Assign close O(n) open mail rows | Large Assign queue | Latency | LIKE narrow + exact int match | 🟢 mitigated |
| FM-07 | Mutate rate false 429 multi-node | AppConfig lock fail-closed | UX friction | Fail closed (safe) | 🟠 accepted |
| FM-08 | Partial Assign txn after IMAP | Conflict mid-close | Compensated markRemoteDeleted | Review discard catch | 🟢 mitigated |

**Deadlock:** Not found across delete/sync/preview (same key; rate locks not held across mbox wait).

---

## 4. Security & Threat Model

| Threat | Boundary | Mitigation | Verdict |
|---|---|---|---|
| Elevation — peer CRM-purge personal outbound | Mutate API | Ownership before CRM-only (Argus) | 🟢 |
| Spoofing — blank Test decrypt personal | testMailbox | Refuse personal blank password | 🟢 |
| DoS — flood delete/spam | Mutate | MAIL_MUTATE_* limits | 🟢 |
| Tampering — reopen deleted mail | return/reopen | isRemoteDeleted gates | 🟢 |
| Info disclosure — IMAP errors | Preview/mutate | Generic client messages | 🟢 |
| SSRF — mail host | Connect/sync | isBlockedMailHost | 🟢 |

---

## 5. Patterns vs Anti-Patterns

| ✅ | ❌ |
|---|---|
| Shared mailbox IMAP lock key | Separate delete lock |
| Compensate after side-effect | Fire-and-forget CRM after IMAP |
| Skip remote_deleted on Assign return | Blind reopen |
| Idempotent IMAP (UID gone = ok) | Assume first try always |
| Rate limit + audit logs | Unlimited mutate |

---

## 6. Non-Functional Requirements Audit

| Category | Target | Current |
|---|---|---|
| Performance | Inbox mutate p95 dominated by IMAP RTT | Bounded by lock wait + rate; Assign close narrowed by LIKE |
| Scalability | Sync ≤500/mailbox/run; mutate 60/user/h | Caps in Constants; cron 900s |
| Availability | Per-mailbox exclusive IMAP | SPOF during sync — documented |
| Consistency | CRM eventual vs IMAP; remote_deleted sticky | Compensates + reconcile |
| Observability | Structured events | outbound_index_*, mutate_denied, rate_limited |
| Recoverability | Retry delete; ghost reconcile ≤500 | RTO depends on operator |
| Cost ceiling | Rate limits + sync caps | Bounded; AppConfig RL keys grow slowly (SF) |

---

## 7. Deployment, Rollback & Operational Readiness

- **Deploy:** Rolling PHP app; no schema change in 1.6.135.
- **Rollback:** Safe (code-only).
- **Secrets:** ICrypto in DB; not in VCS.
- **Dependency:** IMAP/SMTP providers — busy lock + auth quarantine + dial backoff.

---

## 8. Requirement Verdict Tiering

### Must-Fix

| ID | Finding | Traces to | Status | Evidence |
|---|---|---|---|---|
| MF-01 | Inbox missing post-IMAP CRM compensate | FM-01 | 🟢 VERIFIED | `finishInboxRemoteMutate` + contract + mutation `inbox_skips_remote_delete_compensate` |
| MF-02 | returnSharedMail reopens remote_deleted | FM-02 | 🟢 VERIFIED | `isRemoteDeleted` gate + contract + mutation `return_mail_allows_remote_deleted` |

### Should-Fix

| ID | Finding | Rationale | Status |
|---|---|---|---|
| SF-01 | Sync exclusivity starves UX | Needs async worker / shorter sync slices | Deferred backlog |
| SF-02 | Unlocked outbound index after retries | Rare; logged; send must not fail | Deferred / partial (retry shipped) |
| SF-03 | AppConfig rate-limit key growth | Slow; fail-closed | Deferred |
| SF-04 | Dedicated `mail_msg_id` column for Assign | LIKE+parse sufficient mid-term | Deferred |

### Absolute No-Gos

| ID | Forbidden | Detection | Status |
|---|---|---|---|
| NG-01 | Separate lock key for delete vs sync | MailPreviewSecurity + review-mail mutations | 🟢 VERIFIED |
| NG-02 | Leave CRM live after successful IMAP delete | Compensate contracts + mutations | 🟢 VERIFIED |
| NG-03 | Resurrect remote_deleted into Assign | reopen + returnSharedMail contracts | 🟢 VERIFIED |

---

## 9. Data Contracts, State Model & Boundaries

**crm_mail_msg triage:** `open` / `matched` / `discarded` / `remote_deleted` (terminal for IMAP copy).

**Assign mail row:** fingerprint(`shared_mail`|`personal_mail_shared_unmatched`, ws, mailMsgId); terminal → reopen blocked if remote_deleted.

**Outbound:** `message_uid = outbound:{idem}`; triage `matched`; never Assign-promoted.

**Concurrency:** pessimistic `crm_mbox_{id}`; send idempotency `crm_mail_send/{ws}/{idem}`.

**Rate limits:** mutate 60/user/h, 240/ws/h; preview/send separate.

---

## 10. Testability & Validation Strategy

- Architecture contracts: ReviewMailSecurityHardening, PersonalMailHardening, MailPreviewSecurity
- Mutations: `run-review-mail-security-mutations.php`, outbound-assign, argus-bachus-mail
- Concurrency: shared lock key mutation must fail contracts
- Chaos targets (manual): kill request after IMAP before CRM; hold sync lock during delete

---

## Assumptions Register

| ID | Assumption | Invalidates If | Owner |
|---|---|---|---|
| AS-01 | ILockingProvider is cluster-safe (DB) | File locks on multi-node without shared FS | Platform |
| AS-02 | source_ref always JSON with mailMsgId int | Alternate ref shapes | Eng |
| AS-03 | Ghost reconcile runs often enough | Job disabled | Ops |
| AS-04 | Peak Assign open mail ≪ millions | Extreme backlog without LIKE help | Product |

---

## Risk Register

| ID | Residual |
|---|---|
| R-01 Inbox split-brain | Low (MF-01) |
| R-02 Assign resurrection | Low (MF-02) |
| R-03 Sync UX starvation | Medium (SF-01) |
| R-04 Unlocked outbound index | Low (retry+log) |

---

## Blocking Questions

None.

---

## Remediation Evidence

### Code (1.6.135)
- `MailboxSyncService::finishInboxRemoteMutate` — compensate
- `ReviewService::returnSharedMailMessagesToAssign` — skip remote_deleted
- `MailSendService::indexOutboundMessage` — lock retry ×3 + unlocked warn
- `MailboxService::closeOpenAssignForMailMessage` — LIKE narrow + exact match

### Executed verification

```text
docker compose exec -u www-data nextcloud sh -c \
  'cd /var/www/html/custom_apps/customercheck && \
   php vendor/bin/phpunit -c phpunit.xml tests/Unit/ReviewMailSecurityHardeningContractTest.php && \
   php vendor/bin/phpunit -c phpunit.xml tests/Unit/Service/PersonalMailHardeningContractTest.php && \
   php vendor/bin/phpunit -c phpunit.xml tests/Unit/Service/MailboxSyncServiceTest.php && \
   php tests/Mutation/run-review-mail-security-mutations.php && \
   php tests/Mutation/run-outbound-assign-mutations.php'
```

(Results attached at ship time — all green / mutations killed.)

**Final Zeus posture:** Must-Fix and Absolute No-Gos for this scope **🟢 VERIFIED**. Should-Fix SF-01–SF-04 deferred with owners above.
