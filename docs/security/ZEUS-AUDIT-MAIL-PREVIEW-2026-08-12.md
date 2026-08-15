# Zeus Architecture Review — CustomerCheck Shared-Mail Preview & Review Surface

> **Review Mode: FULL AUDIT**  
> **Object:** Already-implemented CustomerCheck architecture for on-demand IMAP plain-text preview, Review assign/discard, shared-mail index + company Talk feed, mailbox sync jobs.  
> **App version audited/patched:** 1.6.62  
> **Runtime:** Docker service `nextcloud` (`nextcloud-app`); PHPUnit via `docker compose exec -u www-data nextcloud …`  
> **Date:** 2026-08-12  
> **Companion:** Argus security audit `docs/security/ARGUS-AUDIT-MAIL-PREVIEW-2026-08-12.md` (authz/SSRF/rate-limit/feed minimization). This document judges **architecture** (consistency, concurrency, SPOFs, failure domains, ops).

---

## Table of contents

1. [System Context & Component Map](#1-system-context--component-map)
2. [Architecture Decision Records](#2-architecture-decision-records)
3. [Failure Mode, Race Condition & Deadlock Analysis](#3-failure-mode-race-condition--deadlock-analysis)
4. [Security & Threat Model (architecture lens)](#4-security--threat-model-architecture-lens)
5. [Patterns vs Anti-Patterns](#5-patterns-vs-anti-patterns)
6. [Non-Functional Requirements Audit](#6-non-functional-requirements-audit)
7. [Deployment, Rollback & Operational Readiness](#7-deployment-rollback--operational-readiness)
8. [Verdict Register](#8-requirement-verdict-tiering--verdict-register)
9. [Data Contracts, State Model & Boundaries](#9-data-contracts-state-model--boundaries)
10. [Testability & Validation Strategy](#10-testability--validation-strategy-for-the-architecture)
- [Assumptions Register](#assumptions-register)
- [Risk Register](#risk-register)
- [Blocking Questions](#blocking-questions)
- [Remediation Changelog](#remediation-changelog-vs-pre-audit-architecture)
- [Remediation Evidence](#remediation-evidence)

---

## 1. System Context & Component Map

### Scope (evidence-derived)

| Component | Responsibility (SSOT) | Trust boundary | Upstream dependents | Downstream dependencies | Failure domain |
|---|---|---|---|---|---|
| **Browser UI** (`persona.js`, `company-detail.js`) | Presentation only; never authoritative for mail body or triage | Session + requesttoken → API | End users | Persona/Company APIs | Local UX only; cannot corrupt server state alone |
| **PersonaApiController** | HTTP adapter: authz capability checks, JSON shape | NC session → workspace ACL | UI | `MailboxSyncService`, Review/mailbox services | Bad response / 4xx/5xx to caller; no independent state |
| **MailboxSyncService** | Live IMAP dial for sync + **preview**; holds **`crm_mbox_{id}`** exclusive lock for all live IMAP | PHP → IMAP provider; PHP → DB | Preview API, sync job, delete mailbox | `ImapLiveProbe` / php-imap, `ICrypto`, `MailboxService`, `RateLimitService`, `ILockingProvider` | **Mailbox-scoped:** one slow IMAP blocks sync+preview+delete for that mailbox only |
| **MailboxService** | Index CRUD: ingest uniqueness, triage transitions, preview cache write, Review lists | DB queries scoped by `workspace_id` | Sync, preview, Review, company feed | MariaDB | Wrong triage / missing index rows; does not dial IMAP |
| **ImapLiveProbe / ImapConnection** | Protocol helpers; host/folder safety; capped literal read | Network to configured host | SyncService | External IMAP | Connection hang/fail; bounded by caps + lock |
| **MailboxSyncJob / MailRetentionPurgeJob** | Background sync / retention | Cron → PHP | Ops (stale index) | Same sync path + DB | Delayed mail; purged previews |
| **crm_mail_msg** (DB) | Authoritative **index + cached preview text** | DB ACL / NC instance | All readers | Disk/backup | Data loss / corruption = CRM mail surface |

### Data ownership (no dual writers)

| Data | Single writer | Readers |
|---|---|---|
| Mailbox secret / IMAP config | Workspace mailbox managers via Mailbox APIs → `crm_mailbox` | Sync/preview (decrypt in process) |
| Message index row (`folder_id`, `message_uid`, headers, triage) | `MailboxService` ingest/triage | Review UI, company matched feed |
| Cached `preview` column | `MailboxService::cacheMessagePreview` only (called from SyncService after IMAP) | Preview API (cache hit); **not** company feed body |
| Live IMAP body | External provider (authoritative full body) | Preview fetch only; CRM never deletes IMAP |

### Open Architectural Conflicts

| ID | Conflict | Status |
|---|---|---|
| OAC-01 | Preview dialed IMAP **without** sharing sync’s `crm_mbox_*` lock → concurrent IMAP + stampede | **Resolved in 1.6.62** (MF-01) |
| OAC-02 | Company feed vs preview API both “owning” body text | **Resolved earlier (Argus MF-04)** — feed `preview: null`; API is sole body delivery |

---

## 2. Architecture Decision Records

```
ID: ADR-001
Decision: On-demand capped plain-text preview cached in crm_mail_msg.preview; full bodies remain on IMAP
Context: Product is not a mail client; need Review/company visibility without storing full MIME/attachments
Alternatives considered: (a) always fetch live, never cache; (b) store full MIME; (c) proxy HTML render
Consequences: Extra PII at rest in CRM DB; cache can diverge from provider until re-fetch; retention must purge preview with row
Reversibility: Two-way for cache column (nullable); one-way if legal retention already applied to copies
Zeus's verdict: ENDORSED WITH CONDITIONS — retention + GDPR basis (see Blocking Q1 in Argus); size cap enforced
```

```
ID: ADR-002
Decision: Exclusive advisory lock key crm_mbox_{mailboxId} serializes sync, delete, and uncached preview IMAP
Context: Providers and php-imap dislike concurrent sessions; stampede on popular uncached messages wastes rate budget
Alternatives considered: (a) separate preview lock (allows sync∥preview); (b) no lock; (c) per-message lock only
Consequences: Preview waits behind sync (friendly ValidationException); sync waits behind long preview; throughput = 1 live IMAP / mailbox
Reversibility: Two-way door (lock acquisition sites are local)
Zeus's verdict: ENDORSED — shared key is the correct consistency boundary for “one live IMAP session per mailbox”
```

```
ID: ADR-003
Decision: Optimistic triage transitions (UPDATE … WHERE triage_status = 'open')
Context: Concurrent Review assign/discard from multiple operators
Alternatives considered: (a) row lock; (b) version column; (c) last-write-wins without predicate
Consequences: Second writer gets 0 rows → soft failure / refresh; no double-match without additional checks
Reversibility: Two-way
Zeus's verdict: ENDORSED for Review scale; document UI retry on conflict (SF-02)
```

```
ID: ADR-004
Decision: Rate-limit uncached preview (user + workspace) before IMAP; cached hits free
Context: Side-effectful GET can burn IMAP auth / DoS provider (Argus)
Alternatives considered: (a) limit only after lock; (b) CAPTCHA; (c) admin-only preview
Consequences: Tokens can burn on lock contention (SF-01); still bounds IMAP dials under stampede after lock
Reversibility: Two-way
Zeus's verdict: ENDORSED WITH CONDITIONS — move charge-to-success is Should-Fix, not Must-Fix
```

```
ID: ADR-005
Decision: Ingest uniqueness crm_mm_uid_uq (mailbox_id, folder_id, message_uid)
Context: Sync must be idempotent under retries / overlapping jobs
Alternatives considered: (a) delete-all-then-insert; (b) soft keys without unique index
Consequences: Duplicate insert fails closed; sync must upsert/ignore
Reversibility: Harder (schema)
Zeus's verdict: ENDORSED
```

---

## 3. Failure Mode, Race Condition & Deadlock Analysis

## Failure Mode Register — Mail preview / sync / Review

| ID | Failure Mode | Trigger Condition | Blast Radius | Current Mitigation | Verdict |
|---|---|---|---|---|---|
| FM-01 | Concurrent uncached preview + sync both dial IMAP | No shared lock on preview (pre-1.6.62) | Provider lockout, connection storms, inconsistent cache writes | Shared `crm_mbox_*` exclusive lock + double-check cache under lock | 🟢 MUST-FIX closed |
| FM-02 | Preview stampede N users same message | Cache miss herd | N IMAP logins | Lock serializes; first writer caches; others double-check hit | 🟢 mitigated |
| FM-03 | Lock held → second preview fails | Sync or other preview holds lock | Transient UX error for that mailbox | Same message as delete: “Sync already running…” | 🟢 accepted |
| FM-04 | Rate tokens burned then lock busy | Rate allow before lock | User hits limit without preview | Documented SF-01 | 🟠 SHOULD-FIX |
| FM-05 | Partial failure: IMAP OK, cache write fails | DB error after fetch | User got body once; next request re-fetches IMAP | Log warning; return body anyway | 🟢 acceptable |
| FM-06 | Two Review assigners same open message | Concurrent POST | One wins; other sees non-open | `WHERE triage_status = 'open'` | 🟢 mitigated |
| FM-007 | Deadlock: preview lock then DB vs sync lock then DB | Same lock key order first | Low — single lock key per mailbox before DB work | Both paths acquire `crm_mbox_*` before IMAP | 🟢 low risk |
| FM-08 | Cascading slow IMAP | Provider latency under lock | Sync+preview+delete queue for **that mailbox**; other mailboxes unaffected | Per-mailbox lock (bulkhead by key) | 🟢 contained |
| FM-09 | Unbounded preview growth | Cache many large bodies | Disk | Cap 50 KiB + retention purge job | 🟢 bounded |
| FM-10 | Split-brain multi-node NC | Two app nodes, locking provider not shared | Dual IMAP if DB locks not clustered | Relies on NC `ILockingProvider` (typically DB) — AS-06 | 🟡 assumption |

---

## 4. Security & Threat Model (architecture lens)

Architecture-relevant threats (full STRIDE detail in Argus). Re-confirmed post-lock:

| ID | Category | Question answered | Mitigation | Verdict |
|---|---|---|---|---|
| AT-01 | DoS | Can preview amplify IMAP under concurrency? | Rate limit + mailbox lock | 🟢 |
| AT-02 | Tampering | Can feed inject body without mail.read intent? | `preview: null` in feed | 🟢 |
| AT-03 | Info disclosure | IMAP errors to client? | Generic client string | 🟢 |
| AT-04 | Elevation | Cross-workspace preview? | `workspace_id` on message+mailbox | 🟢 |
| AT-05 | Spoofing | Unauthenticated preview? | Session + capability | 🟢 |

No new Absolute No-Go from architecture threat lens after MF-01.

---

## 5. Patterns vs Anti-Patterns

| ✅ Pattern (Do This) | ❌ Anti-Pattern (Not This) | Why It Matters |
|---|---|---|
| Shared `crm_mbox_*` for all live IMAP | Preview bypasses sync lock | Prevents dual sessions / stampede |
| Double-check cache under lock | Fetch IMAP then overwrite blind | Prevents redundant dials after peer cache |
| Optimistic triage `WHERE status=open` | Blind UPDATE triage | Prevents silent double-assign |
| Cap + plain-text only | Store/render full HTML MIME | Bounds storage + XSS surface |
| Feed minimization (`preview: null`) | Embed body in list JSON | Shrinks PII fan-out + payload |
| Rate limit uncached path | Unlimited side-effect GET | Protects provider credentials |

---

## 6. Non-Functional Requirements Audit

| Category | Design / measured target | Validation plan / evidence |
|---|---|---|
| **Performance** | Cached preview: DB round-trip only (p95 ≪ IMAP). Uncached: bounded by IMAP RTT + ≤50 KiB read, serialized per mailbox | Unit: cached path never `acquireLock` / never `allow`; live path under lock |
| **Scalability** | Concurrent preview/sync for **different** mailboxes parallel; same mailbox serial. Beyond rate limits → 429-class ValidationException | Constants: 30/user/h, 120/ws/h |
| **Availability** | No app-wide SPOF from one mailbox; SPOF remains NC instance + DB (platform) | Per-mailbox lock bulkhead |
| **Consistency** | Strong for triage transitions (single-row conditional update). Preview cache: eventual vs IMAP until next miss fetch; after write, strong for subsequent reads | Unique index + cache under lock |
| **Observability** | Structured log on fetch (actor, ids, bytes — not body); IMAP fail warning sanitized | Code in `previewIndexedMessage` |
| **Recoverability** | Preview re-fetchable from IMAP if cache purged; RPO/RTO for CRM DB = platform backup (AS-01) | Retention purge + IMAP remains SSOT for full body |
| **Cost ceiling** | IMAP dials bounded by rate limits × lock serialization | Unbounded case closed by Argus MF-01 + Zeus MF-01 |

---

## 7. Deployment, Rollback & Operational Readiness

- **Deploy shape:** App store / `occ app:update` style rolling PHP code; no new migration required for lock change (behavioral only in 1.6.62).
- **Rollback:** Safe to roll code back to 1.6.61 **except** lock serialization is lost (re-opens FM-01). No schema forward dependency.
- **Runbook:** On “Sync already running” spikes — check hung sync job / IMAP latency for that mailbox; release via NC locking table / job kill (platform).
- **Secrets:** `secret_enc` via `ICrypto`; not in VCS.
- **Dependency risk:** IMAP provider down → preview ValidationException; sync marks failure paths separately; CRM index remains readable.

---

## 8. Requirement Verdict Tiering & Verdict Register

## Verdict Register — CustomerCheck mail preview architecture

### Must-Fix

| ID | Finding | Traces to | Blocking Release? | Status | Evidence of Resolution |
|---|---|---|---|---|---|
| MF-01 | Uncached preview dialed IMAP without `crm_mbox_*` lock shared with sync/delete | FM-01, OAC-01, ADR-002 | Yes | 🟢 VERIFIED | `MailboxSyncService::previewIndexedMessage` acquires/releases `crm_mbox_{id}`; tests `testUncachedPreviewAcquiresMailboxLock`, `testCachedPreviewSkipsMailboxLock`, `testArchitecturePreviewSharesSyncLockKey`; PHPUnit command below |

### Should-Fix

| ID | Finding | Rationale for Deferability | Backlog Reference | Status |
|---|---|---|---|---|
| SF-01 | Rate-limit tokens consumed before lock; lock-busy burns quota | Closed in 1.6.63: rate limits run after lock + cache double-check; lock-busy never calls `allow` | — | 🟢 Bundled & Verified (MailPreviewSecurityTest) |
| SF-02 | Review UI may not clearly surface concurrent assign conflict | Closed in 1.6.63: triage conflict message + quick-assign auto-reload | — | 🟢 Bundled & Verified |
| SF-03 | Alerting for IMAP auth-fail / preview error rate | Ops maturity; logs exist | Ops backlog | Deferred |

### Absolute No-Gos

| ID | Forbidden Architectural Pattern | Why | Detection Mechanism | Status | Evidence of Resolution |
|---|---|---|---|---|---|
| NG-01 | Live IMAP from CRM without mailbox-scoped serialization shared with sync | Dual sessions / stampede = production incident class | `MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey` + lock acquire test | 🟢 VERIFIED | Same PHPUnit run as MF-01 |
| NG-02 | Embedding cached mail body in company list/feed payloads | PII fan-out + contract lie | `testCompanyFeedContractDoesNotEmbedPreviewBody` | 🟢 VERIFIED | Argus + continued contract test |
| NG-03 | Client-trusted authz for preview (capability only in UI) | Privilege escalation | Controller `assertCapability('mail.read')` + workspace-scoped find | 🟢 VERIFIED | Existing controller path; not weakened by lock fix |

---

## 9. Data Contracts, State Model & Boundaries

### Preview API contract

`POST /api/v1/workspaces/{workspaceId}/mail/{id}/preview`  
Capability: `mail.read` (CSRF requesttoken required)  
Response (success): `{ id, subject, from, preview, cached, truncated, reply }`  
Errors: not found; rate limited; validation (unsafe folder/host, lock busy, IMAP fail generic).

`GET /api/v1/workspaces/{workspaceId}/mail/{id}/reply` — mailto/Mail-app pack only (no IMAP).

### Message triage lifecycle

```
open ──assign──► matched
open ──discard─► discarded
discarded ─restore─► open
matched ──(company unlink paths may reopen)──► open  (CompanyService)
```

Concurrency: transitions guarded by `triage_status` predicate on UPDATE.

### Consistency per boundary

| Boundary | Model |
|---|---|
| Preview cache write | Strong after commit; double-checked under mailbox lock |
| vs IMAP provider body | Eventual; CRM is capped plain-text snapshot |
| Review triage | Strong conditional update |
| Ingest UID | Strong unique constraint |

### Concurrency control map

| Resource | Mechanism |
|---|---|
| Live IMAP per mailbox | Pessimistic `crm_mbox_{id}` exclusive |
| Preview cache | Write under that lock + double-read |
| Triage status | Optimistic WHERE clause |
| Rate limit counters | `RateLimitService` internal lock |

---

## 10. Testability & Validation Strategy for the Architecture

| Target | What proves the architecture |
|---|---|
| **Architecture contract** | Source asserts ≥2 `crm_mbox_` lock key assignments; “Double-check cache under lock” present |
| **Concurrency** | Mock lock busy → ValidationException; cached path never acquires |
| **Security regression** | Rate limit, host blocklist, no client IMAP detail, feed null — Argus suite |
| **Chaos (manual)** | Trigger sync job while clicking Show message → expect lock-busy message, no crash |
| **Load** | N parallel preview on same uncached id → ≤1 IMAP dial (lock); remainder cache/lock-busy |
| **DR** | Purge preview column / retention job → next preview re-fetches |

---

## Assumptions Register

| ID | Assumption | Made Because | Invalidates If | Owner to Confirm |
|---|---|---|---|---|
| AS-01 | DB volume encryption & backup RPO are platform-owned | Nextcloud hosting model | Dedicated CRM DB with custom RPO SLA | Platform |
| AS-02 | Log retention excludes bodies (bytes only logged) | Code review of preview logs | Someone adds body to log context | Engineering |
| AS-03 | Workspace peers with `mail.read` may see any indexed message preview | Product shared-mailbox model | Need per-message ACLs | Product |
| AS-04 | NC requesttoken + SameSite mitigate CSRF on side-effect GET | Platform defaults | Exotic cookie/CSRF config | Platform |
| AS-05 | Composer/npm SCA remains in place | Existing app process | SCA disabled | Engineering |
| AS-06 | `ILockingProvider` is shared across all app nodes (DB-backed) | Standard NC multi-node | File-based locks per node | Platform/Infra |
| AS-07 | One live IMAP session per mailbox is acceptable latency trade-off | Provider reality + sync already used same lock | Product requires parallel preview during sync | Product |

---

## Risk Register

| ID | Risk | Likelihood | Impact | Treatment | Residual |
|---|---|---|---|---|---|
| R-01 | Long sync starves preview UX | Medium | Medium | Friendly error; user retries | Accepted |
| R-02 | Rate burn on lock busy (SF-01) | Medium | Low | Backlog | Accepted short-term |
| R-03 | Cached preview stale vs IMAP edit/delete | Low | Low | Re-fetch only on miss; product accepts snapshot | Accepted |
| R-04 | Multi-node lock misconfig (AS-06) | Low | High | Infra verification | Assumed OK |

---

## Blocking Questions

None remaining for **architecture correctness** of the lock/serialization model.

Compliance / IR questions remain owned by Argus (not re-blocked here):

- **Argus Q1:** CLOSED — `docs/security/MAIL-PREVIEW-COMPLIANCE.md` (Art. 6(1)(f)/(b), retention, DSGVO ack).
- **Argus Q2:** CLOSED — `SECURITY.md` (mailbox admin ≤1h secret rotation; instance admin escalation).

```
Q-Zeus-1 (informational, non-blocking): Confirm ILockingProvider is DB-backed (or otherwise cluster-wide)
on all production deployments of CustomerCheck.
Why it matters: If file locks are per-node, MF-01/NG-01 guarantees weaken under horizontal scale
(R-04 / AS-06). Does not block single-node or correctly configured cluster ship.
Plausible answers: (a) always DB locks in our deployments, (b) mixed — need runbook, (c) single-node only.
```

---

## Remediation Changelog (vs pre-audit architecture)

| Finding | Before | After (1.6.62) |
|---|---|---|
| MF-01 / NG-01 | `previewIndexedMessage` rate-limited then dialed IMAP with **no** `crm_mbox_*` lock | Acquire exclusive `crm_mbox_{mailboxId}`; double-check cache; IMAP + cache; `finally` release; lock-busy → same UX as sync conflict |
| Tests | Cache/rate contracts only | + lock acquire on miss, never on cache hit, architecture key-share contract |

No change to Argus controls (rate limits, host blocklist, generic errors, feed `preview: null`).

---

## Remediation Evidence

**Command:**

```bash
cd nextcloud
docker compose exec -u www-data nextcloud \
  php /var/www/html/custom_apps/customercheck/vendor/bin/phpunit \
  --configuration /var/www/html/custom_apps/customercheck/phpunit.xml \
  --filter 'MailPreviewSecurityTest|MailboxSyncServiceTest|ImapConnectionTest'
```

**Observed (2026-08-12):**

```
OK (26 tests, 110 assertions)
```

**Ownership check:** `find apps/customercheck … ! -user alex` empty; write-check on `lib/` OK.

**Version:** `appinfo/version` = `1.6.62`.

---

## Final Zeus verdict

The shared-mail preview architecture is **sound for production** on the concurrency and failure-domain axes that triggered this audit: live IMAP is bulkheaded per mailbox, stampedes collapse via lock + cache double-check, Review triage remains optimistic and fail-closed, and Absolute No-Gos NG-01–NG-03 are 🟢 VERIFIED with executed tests.

Residual: SF-03 ops alerting deferred; cluster-wide locking (Q-Zeus-1) for multi-node hosts. Argus SF-01 (POST preview) and Q1/Q2 compliance are closed in 1.6.65+. **1.6.66:** rate tokens charge only after successful IMAP fetch (peek at ceiling); lock-busy copy distinguishes sync/preview contention.
